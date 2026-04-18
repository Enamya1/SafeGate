import os
import threading
import time
from datetime import datetime
from typing import Any, Dict, List, Optional, Tuple

from sqlalchemy import text
from sqlalchemy.engine import Engine
from sqlalchemy.exc import ProgrammingError

try:
    import redis
except Exception:
    redis = None


EVENT_TYPES = {"view", "click", "like", "search", "add_to_cart", "favorite", "purchase"}


def _safe_int(value: Any, default: int = 0) -> int:
    try:
        return int(value)
    except Exception:
        return default


def _safe_float(value: Any, default: float = 0.0) -> float:
    try:
        return float(value)
    except Exception:
        return default


def _now_utc() -> datetime:
    return datetime.utcnow()


class TrendingCache:
    def __init__(self) -> None:
        self._fallback: Dict[str, Tuple[float, Any]] = {}
        self._lock = threading.Lock()
        self._redis = None
        self._ttl_seconds = max(10, _safe_int(os.environ.get("TRENDING_CACHE_TTL_SECONDS"), 120))
        url = (os.environ.get("REDIS_URL") or "").strip()
        if url and redis is not None:
            try:
                self._redis = redis.Redis.from_url(url, decode_responses=True)
                self._redis.ping()
            except Exception:
                self._redis = None

    def get_json(self, key: str) -> Optional[Any]:
        if self._redis is not None:
            try:
                raw = self._redis.get(key)
                if raw:
                    import json

                    return json.loads(raw)
            except Exception:
                pass

        now_mono = time.monotonic()
        with self._lock:
            cached = self._fallback.get(key)
            if cached is None:
                return None
            if cached[0] < now_mono:
                self._fallback.pop(key, None)
                return None
            return cached[1]

    def set_json(self, key: str, value: Any) -> None:
        if self._redis is not None:
            try:
                import json

                self._redis.setex(key, self._ttl_seconds, json.dumps(value))
                return
            except Exception:
                pass

        with self._lock:
            self._fallback[key] = (time.monotonic() + self._ttl_seconds, value)

    def invalidate(self, key: str) -> None:
        if self._redis is not None:
            try:
                self._redis.delete(key)
            except Exception:
                pass
        with self._lock:
            self._fallback.pop(key, None)


_trending_cache = TrendingCache()
_trending_updater_started = False
_trending_updater_lock = threading.Lock()


def _serialize_product(row: Dict[str, Any]) -> Dict[str, Any]:
    created_at = row.get("created_at")
    if isinstance(created_at, datetime):
        created_at = created_at.isoformat()
    return {
        "id": row.get("id"),
        "title": row.get("title"),
        "category_id": row.get("category_id"),
        "price": _safe_float(row.get("price"), 0.0),
        "views_count": _safe_int(row.get("views_count"), 0),
        "likes_count": _safe_int(row.get("likes_count"), 0),
        "clicks_count": _safe_int(row.get("clicks_count"), 0),
        "trending_score": _safe_float(row.get("trending_score"), 0.0),
        "created_at": created_at,
    }


def _update_single_product_trending(conn, product_id: int) -> None:
    conn.execute(
        text(
            """
            UPDATE products p
            SET p.trending_score = (
                (COALESCE(p.views_count, 0) * 0.2) +
                (COALESCE(p.likes_count, 0) * 0.5) +
                (COALESCE(p.clicks_count, 0) * 0.2) +
                (
                    CASE
                        WHEN COALESCE(p.views_count, 0) = 0 THEN 0
                        ELSE (COALESCE(p.likes_count, 0) / COALESCE(NULLIF(p.views_count, 0), 1)) * 0.1
                    END
                ) +
                (
                    10 * EXP(
                        -GREATEST(TIMESTAMPDIFF(HOUR, p.created_at, UTC_TIMESTAMP()), 0) / 72
                    )
                )
            )
            WHERE p.id = :product_id
            """
        ),
        {"product_id": int(product_id)},
    )


def update_all_trending_scores(conn) -> int:
    conn.execute(
        text(
            """
            UPDATE products p
            SET p.trending_score = (
                (COALESCE(p.views_count, 0) * 0.2) +
                (COALESCE(p.likes_count, 0) * 0.5) +
                (COALESCE(p.clicks_count, 0) * 0.2) +
                (
                    CASE
                        WHEN COALESCE(p.views_count, 0) = 0 THEN 0
                        ELSE (COALESCE(p.likes_count, 0) / COALESCE(NULLIF(p.views_count, 0), 1)) * 0.1
                    END
                ) +
                (
                    10 * EXP(
                        -GREATEST(TIMESTAMPDIFF(HOUR, p.created_at, UTC_TIMESTAMP()), 0) / 72
                    )
                )
            )
            WHERE p.deleted_at IS NULL
            """
        )
    )
    _trending_cache.invalidate("trending_products")
    return 1


def track_behavior_event(
    conn,
    user_id: int,
    product_id: Optional[int],
    event_type: str,
    event_at: Optional[datetime] = None,
) -> Dict[str, Any]:
    normalized_event = (event_type or "").strip().lower()
    if normalized_event not in EVENT_TYPES:
        normalized_event = "view"
    event_at = event_at or _now_utc()

    category_id = None
    seller_id = None
    if product_id is not None:
        product_row = conn.execute(
            text(
                """
                SELECT id, category_id, seller_id
                FROM products
                WHERE id = :product_id
                LIMIT 1
                """
            ),
            {"product_id": int(product_id)},
        ).mappings().first()
        if product_row is None:
            raise ValueError("product_id not found")
        category_id = product_row.get("category_id")
        seller_id = product_row.get("seller_id")

    conn.execute(
        text(
            """
            INSERT INTO behavioral_events (
                user_id, product_id, category_id, seller_id, event_type, occurred_at, created_at, updated_at
            ) VALUES (
                :user_id, :product_id, :category_id, :seller_id, :event_type, :occurred_at, UTC_TIMESTAMP(), UTC_TIMESTAMP()
            )
            """
        ),
        {
            "user_id": int(user_id),
            "product_id": int(product_id) if product_id is not None else None,
            "category_id": category_id,
            "seller_id": seller_id,
            "event_type": normalized_event,
            "occurred_at": event_at,
        },
    )

    if product_id is not None:
        if normalized_event == "view":
            conn.execute(text("UPDATE products SET views_count = COALESCE(views_count, 0) + 1 WHERE id = :id"), {"id": int(product_id)})
        elif normalized_event == "click":
            conn.execute(text("UPDATE products SET clicks_count = COALESCE(clicks_count, 0) + 1 WHERE id = :id"), {"id": int(product_id)})
        elif normalized_event in {"like", "favorite"}:
            conn.execute(text("UPDATE products SET likes_count = COALESCE(likes_count, 0) + 1 WHERE id = :id"), {"id": int(product_id)})
        _update_single_product_trending(conn, int(product_id))
        _trending_cache.invalidate("trending_products")

    return {
        "user_id": int(user_id),
        "product_id": int(product_id) if product_id is not None else None,
        "event_type": normalized_event,
        "timestamp": event_at.isoformat(),
    }


def get_trending_products(conn, limit: int = 20) -> List[Dict[str, Any]]:
    limit = max(1, min(100, int(limit)))
    cache_key = "trending_products"
    cached = _trending_cache.get_json(cache_key)
    if isinstance(cached, list):
        return cached[:limit]

    rows = conn.execute(
        text(
            """
            SELECT
                p.id,
                p.title,
                p.category_id,
                p.price,
                COALESCE(p.views_count, 0) AS views_count,
                COALESCE(p.likes_count, 0) AS likes_count,
                COALESCE(p.clicks_count, 0) AS clicks_count,
                COALESCE(p.trending_score, 0) AS trending_score,
                p.created_at
            FROM products p
            WHERE p.status = 'available'
              AND p.deleted_at IS NULL
            ORDER BY p.trending_score DESC, p.created_at DESC, p.id DESC
            LIMIT :limit_value
            """
        ),
        {"limit_value": limit},
    ).mappings().all()
    payload = [_serialize_product(dict(row)) for row in rows]
    _trending_cache.set_json(cache_key, payload)
    return payload


def get_related_products(conn, product_id: int, limit: int = 20) -> List[Dict[str, Any]]:
    limit = max(1, min(100, int(limit)))
    target = conn.execute(
        text(
            """
            SELECT p.id, p.category_id, p.price
            FROM products p
            WHERE p.id = :product_id
              AND p.deleted_at IS NULL
            LIMIT 1
            """
        ),
        {"product_id": int(product_id)},
    ).mappings().first()
    if target is None:
        return []

    target_category_id = target.get("category_id")
    target_price = _safe_float(target.get("price"), 0.0)
    min_price = target_price * 0.8
    max_price = target_price * 1.2

    content_rows = conn.execute(
        text(
            """
            SELECT
                p.id,
                p.title,
                p.category_id,
                p.price,
                COALESCE(p.views_count, 0) AS views_count,
                COALESCE(p.likes_count, 0) AS likes_count,
                COALESCE(p.clicks_count, 0) AS clicks_count,
                COALESCE(p.trending_score, 0) AS trending_score,
                p.created_at,
                (
                    CASE WHEN p.category_id = :target_category_id THEN 2.0 ELSE 0 END
                    + CASE
                        WHEN :target_price > 0 AND p.price BETWEEN :min_price AND :max_price THEN 1.5
                        ELSE 0
                      END
                    + (
                        SELECT COALESCE(COUNT(*), 0)
                        FROM product_tags pt
                        JOIN product_tags tgt ON tgt.tag_id = pt.tag_id
                        WHERE pt.product_id = p.id AND tgt.product_id = :target_product_id
                      ) * 0.8
                ) AS related_score
            FROM products p
            WHERE p.status = 'available'
              AND p.deleted_at IS NULL
              AND p.id <> :target_product_id
            ORDER BY related_score DESC, p.trending_score DESC, p.created_at DESC
            LIMIT :limit_value
            """
        ),
        {
            "target_product_id": int(product_id),
            "target_category_id": target_category_id,
            "target_price": target_price,
            "min_price": min_price,
            "max_price": max_price,
            "limit_value": limit,
        },
    ).mappings().all()

    collab_rows = conn.execute(
        text(
            """
            SELECT
                p.id,
                p.title,
                p.category_id,
                p.price,
                COALESCE(p.views_count, 0) AS views_count,
                COALESCE(p.likes_count, 0) AS likes_count,
                COALESCE(p.clicks_count, 0) AS clicks_count,
                COALESCE(p.trending_score, 0) AS trending_score,
                p.created_at,
                COUNT(*) AS collab_score
            FROM behavioral_events e_target
            JOIN behavioral_events e_other
              ON e_other.user_id = e_target.user_id
             AND e_other.product_id <> e_target.product_id
            JOIN products p
              ON p.id = e_other.product_id
            WHERE e_target.product_id = :target_product_id
              AND e_target.event_type IN ('view', 'click', 'like', 'add_to_cart')
              AND e_other.event_type IN ('view', 'click', 'like', 'add_to_cart')
              AND p.status = 'available'
              AND p.deleted_at IS NULL
            GROUP BY p.id, p.title, p.category_id, p.price, p.views_count, p.likes_count, p.clicks_count, p.trending_score, p.created_at
            ORDER BY collab_score DESC, p.trending_score DESC
            LIMIT :limit_value
            """
        ),
        {"target_product_id": int(product_id), "limit_value": limit},
    ).mappings().all()

    merged: Dict[int, Dict[str, Any]] = {}
    for row in content_rows:
        item = dict(row)
        pid = _safe_int(item.get("id"))
        item["final_related_score"] = _safe_float(item.get("related_score"), 0.0) * 0.7
        merged[pid] = item
    for row in collab_rows:
        item = dict(row)
        pid = _safe_int(item.get("id"))
        collab_component = _safe_float(item.get("collab_score"), 0.0) * 0.3
        if pid in merged:
            merged[pid]["final_related_score"] = _safe_float(merged[pid].get("final_related_score"), 0.0) + collab_component
        else:
            item["final_related_score"] = collab_component
            merged[pid] = item

    ranked = sorted(
        merged.values(),
        key=lambda x: (_safe_float(x.get("final_related_score"), 0.0), _safe_float(x.get("trending_score"), 0.0)),
        reverse=True,
    )[:limit]
    return [_serialize_product(row) for row in ranked]


def get_personalized_products(conn, user_id: int, limit: int = 20) -> List[Dict[str, Any]]:
    limit = max(1, min(100, int(limit)))
    rows = conn.execute(
        text(
            """
            WITH user_pref AS (
                SELECT
                    e.category_id,
                    SUM(
                        CASE e.event_type
                            WHEN 'like' THEN 4
                            WHEN 'add_to_cart' THEN 5
                            WHEN 'click' THEN 2
                            WHEN 'view' THEN 1
                            ELSE 0.5
                        END
                    ) AS category_weight
                FROM behavioral_events e
                WHERE e.user_id = :user_id
                  AND e.category_id IS NOT NULL
                  AND e.occurred_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
                GROUP BY e.category_id
            )
            SELECT
                p.id,
                p.title,
                p.category_id,
                p.price,
                COALESCE(p.views_count, 0) AS views_count,
                COALESCE(p.likes_count, 0) AS likes_count,
                COALESCE(p.clicks_count, 0) AS clicks_count,
                COALESCE(p.trending_score, 0) AS trending_score,
                p.created_at,
                COALESCE(up.category_weight, 0) AS personalized_score
            FROM products p
            LEFT JOIN user_pref up ON up.category_id = p.category_id
            WHERE p.status = 'available'
              AND p.deleted_at IS NULL
            ORDER BY personalized_score DESC, p.trending_score DESC, p.created_at DESC
            LIMIT :limit_value
            """
        ),
        {"user_id": int(user_id), "limit_value": limit},
    ).mappings().all()
    return [_serialize_product(dict(row)) for row in rows]


def build_hybrid_recommendations(
    conn,
    user_id: int,
    last_interacted_product_id: Optional[int] = None,
    limit: int = 30,
) -> Dict[str, Any]:
    limit = max(3, min(100, int(limit)))
    personalized_count = max(1, int(round(limit * 0.4)))
    related_count = max(1, int(round(limit * 0.3)))
    trending_count = max(1, limit - personalized_count - related_count)

    if last_interacted_product_id is None:
        last_row = conn.execute(
            text(
                """
                SELECT product_id
                FROM behavioral_events
                WHERE user_id = :user_id
                  AND product_id IS NOT NULL
                ORDER BY occurred_at DESC, id DESC
                LIMIT 1
                """
            ),
            {"user_id": int(user_id)},
        ).mappings().first()
        if last_row is not None:
            last_interacted_product_id = _safe_int(last_row.get("product_id"), 0) or None

    personalized = get_personalized_products(conn, user_id=user_id, limit=personalized_count * 2)
    related = get_related_products(conn, product_id=last_interacted_product_id, limit=related_count * 2) if last_interacted_product_id else []
    trending = get_trending_products(conn, limit=trending_count * 2)

    picked: List[Dict[str, Any]] = []
    seen: set = set()

    if last_interacted_product_id is not None:
        base_product_row = conn.execute(
            text(
                """
                SELECT
                    p.id, p.title, p.category_id, p.price,
                    COALESCE(p.views_count, 0) AS views_count,
                    COALESCE(p.likes_count, 0) AS likes_count,
                    COALESCE(p.clicks_count, 0) AS clicks_count,
                    COALESCE(p.trending_score, 0) AS trending_score,
                    p.created_at
                FROM products p
                WHERE p.id = :product_id
                  AND p.status = 'available'
                  AND p.deleted_at IS NULL
                LIMIT 1
                """
            ),
            {"product_id": int(last_interacted_product_id)},
        ).mappings().first()
        if base_product_row is not None:
            base_product = _serialize_product(dict(base_product_row))
            base_product["source"] = "last_interaction"
            picked.append(base_product)
            seen.add(_safe_int(base_product.get("id")))

    def _append_from_pool(pool: List[Dict[str, Any]], count: int, source: str) -> None:
        for item in pool:
            pid = _safe_int(item.get("id"))
            if pid <= 0 or pid in seen:
                continue
            copy_item = dict(item)
            copy_item["source"] = source
            picked.append(copy_item)
            seen.add(pid)
            if len([x for x in picked if x.get("source") == source]) >= count:
                break

    _append_from_pool(personalized, personalized_count, "personalized")
    _append_from_pool(related, related_count, "related")
    _append_from_pool(trending, trending_count, "trending")

    if len(picked) < limit:
        for pool, source in ((personalized, "personalized"), (related, "related"), (trending, "trending")):
            for item in pool:
                pid = _safe_int(item.get("id"))
                if pid <= 0 or pid in seen:
                    continue
                copy_item = dict(item)
                copy_item["source"] = source
                picked.append(copy_item)
                seen.add(pid)
                if len(picked) >= limit:
                    break
            if len(picked) >= limit:
                break

    # Final top-up from globally available products to keep response size stable.
    if len(picked) < limit:
        fallback_rows = conn.execute(
            text(
                """
                SELECT
                    p.id,
                    p.title,
                    p.category_id,
                    p.price,
                    COALESCE(p.views_count, 0) AS views_count,
                    COALESCE(p.likes_count, 0) AS likes_count,
                    COALESCE(p.clicks_count, 0) AS clicks_count,
                    COALESCE(p.trending_score, 0) AS trending_score,
                    p.created_at
                FROM products p
                WHERE p.status = 'available'
                  AND p.deleted_at IS NULL
                ORDER BY p.trending_score DESC, p.created_at DESC, p.id DESC
                LIMIT 500
                """
            )
        ).mappings().all()
        for row in fallback_rows:
            item = _serialize_product(dict(row))
            pid = _safe_int(item.get("id"))
            if pid <= 0 or pid in seen:
                continue
            item["source"] = "fallback"
            picked.append(item)
            seen.add(pid)
            if len(picked) >= limit:
                break

    return {
        "user_id": int(user_id),
        "last_interacted_product_id": last_interacted_product_id,
        "strategy": {"personalized": 0.4, "related": 0.3, "trending": 0.3},
        "products": picked[:limit],
    }


def run_trending_batch_update(engine: Engine) -> None:
    try:
        with engine.begin() as conn:
            update_all_trending_scores(conn)
    except Exception:
        return


def start_trending_batch_updater(engine: Engine) -> None:
    global _trending_updater_started
    with _trending_updater_lock:
        if _trending_updater_started:
            return
        _trending_updater_started = True

    interval_minutes = max(1, _safe_int(os.environ.get("TRENDING_BATCH_UPDATE_MINUTES"), 10))

    def _loop() -> None:
        while True:
            run_trending_batch_update(engine)
            time.sleep(interval_minutes * 60)

    thread = threading.Thread(target=_loop, daemon=True, name="trending-score-updater")
    thread.start()


def safe_recommendation_call(func, *args, **kwargs):
    try:
        return func(*args, **kwargs)
    except ProgrammingError as e:
        msg = str(getattr(e, "orig", e))
        raise RuntimeError(f"Database schema issue: {msg}")
