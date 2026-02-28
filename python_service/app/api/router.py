import json
import math
import os
import random
import urllib.error
import urllib.request
from datetime import datetime, timedelta
from typing import Any, Dict, List, Optional, Set, Tuple

from fastapi import APIRouter, Header, HTTPException, Query
from sqlalchemy import create_engine, text
from sqlalchemy.engine import Engine
from sqlalchemy.exc import ProgrammingError

router = APIRouter()
py_router = APIRouter()

_engine: Optional[Engine] = None


def _get_env_int(name: str, default: int) -> int:
    raw = os.environ.get(name)
    if raw is None or raw == "":
        return default
    try:
        return int(raw)
    except Exception:
        return default


def _get_db_engine() -> Engine:
    global _engine
    if _engine is not None:
        return _engine

    host = os.environ.get("DB_HOST", "127.0.0.1")
    port = _get_env_int("DB_PORT", 3306)
    database = os.environ.get("DB_DATABASE", "suki_db")
    username = os.environ.get("DB_USERNAME", "root")
    password = os.environ.get("DB_PASSWORD", "")

    url = f"mysql+pymysql://{username}:{password}@{host}:{port}/{database}"
    _engine = create_engine(
        url,
        pool_pre_ping=True,
        pool_recycle=3600,
        future=True,
    )
    return _engine


def _laravel_get_json(authorization: str, path: str) -> Dict[str, Any]:
    base_url = os.environ.get("LARAVEL_BASE_URL", "http://127.0.0.1:8000").rstrip("/")
    url = f"{base_url}{path}"

    req = urllib.request.Request(
        url,
        headers={
            "Accept": "application/json",
            "Authorization": authorization,
        },
        method="GET",
    )

    try:
        with urllib.request.urlopen(req, timeout=8) as resp:
            body = resp.read().decode("utf-8")
            data = json.loads(body) if body else {}
            return data if isinstance(data, dict) else {}
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8") if hasattr(e, "read") else ""
        try:
            data = json.loads(body) if body else {}
        except Exception:
            data = {}
        raise HTTPException(status_code=e.code, detail=data or {"message": "Laravel request failed"})
    except Exception:
        raise HTTPException(status_code=502, detail="Could not reach Laravel")


def _parse_lat_lng_from_location(location: Any) -> Optional[Tuple[float, float]]:
    if not isinstance(location, str) or not location:
        return None

    s = location.strip()
    at = s.find("@")
    if at != -1:
        after = s[at + 1 :]
        parts = after.split(",")
        if len(parts) >= 2:
            try:
                return float(parts[0]), float(parts[1])
            except Exception:
                pass

    markers = ["q=", "query=", "ll="]
    for m in markers:
        idx = s.find(m)
        if idx != -1:
            after = s[idx + len(m) :]
            after = after.split("&", 1)[0]
            parts = after.split(",")
            if len(parts) >= 2:
                try:
                    return float(parts[0]), float(parts[1])
                except Exception:
                    pass

    return None


def _parse_lat_lng(lat: Any, lng: Any, location: Any = None) -> Optional[Tuple[float, float]]:
    if lat is not None and lng is not None:
        try:
            return float(lat), float(lng)
        except Exception:
            pass
    if location is not None:
        return _parse_lat_lng_from_location(location)
    return None


def _haversine_km(a: Tuple[float, float], b: Tuple[float, float]) -> float:
    lat1, lon1 = a
    lat2, lon2 = b
    r = 6371.0
    phi1 = math.radians(lat1)
    phi2 = math.radians(lat2)
    dphi = math.radians(lat2 - lat1)
    dlambda = math.radians(lon2 - lon1)
    x = math.sin(dphi / 2) ** 2 + math.cos(phi1) * math.cos(phi2) * math.sin(dlambda / 2) ** 2
    return 2 * r * math.atan2(math.sqrt(x), math.sqrt(1 - x))


def _now_utc_naive() -> datetime:
    return datetime.utcnow()


def _event_weight(event_type: str) -> float:
    t = (event_type or "").strip().lower()
    if t in {"favorite", "favourite"}:
        return 3.0
    if t in {"offer", "make_offer"}:
        return 4.0
    if t in {"message", "chat"}:
        return 2.0
    if t in {"purchase", "buy", "transaction"}:
        return 5.0
    if t in {"view", "click"}:
        return 1.0
    return 0.5


def _time_decay(occurred_at: datetime, now: datetime) -> float:
    age_days = max(0.0, (now - occurred_at).total_seconds() / 86400.0)
    return math.exp(-age_days / 14.0)


def _group_by_key(rows: List[Dict[str, Any]], key: str) -> Dict[Any, List[Dict[str, Any]]]:
    out: Dict[Any, List[Dict[str, Any]]] = {}
    for r in rows:
        k = r.get(key)
        if k is None:
            continue
        out.setdefault(k, []).append(r)
    return out


def _missing_table_name_from_programming_error(e: ProgrammingError) -> Optional[str]:
    if not getattr(e, "orig", None) or not getattr(e.orig, "args", None) or len(e.orig.args) < 2:
        return None
    msg = str(e.orig.args[1])
    marker = "Table '"
    idx = msg.find(marker)
    if idx == -1:
        return None
    rest = msg[idx + len(marker) :]
    end = rest.find("'")
    if end == -1:
        return None
    full = rest[:end]
    if "." in full:
        return full.split(".", 1)[1]
    return full



@router.get("/ping")
def ping() -> dict:
    return {"message": "pong"}


@router.get("/test_py")
def test_py() -> dict:
    return {"message": "python connected"}


@router.get("/hi")
def hi(authorization: Optional[str] = Header(default=None)) -> dict:
    if not authorization:
        raise HTTPException(status_code=401, detail="Missing Authorization header")

    data = _laravel_get_json(authorization, "/api/user/me")

    user = data.get("user") if isinstance(data, dict) else None
    username = None

    if isinstance(user, dict):
        username = user.get("username") or user.get("full_name") or user.get("email")

    if not username:
        username = "user"

    return {"message": f"hi {username}"}


@py_router.get("/py/api/user/recommendations/products")
def recommend_products(
    authorization: Optional[str] = Header(default=None),
    page: int = Query(default=1, ge=1),
    page_size: int = Query(default=10, ge=1, le=50),
    random_count: int = Query(default=3, ge=0, le=50),
    lookback_days: int = Query(default=30, ge=1, le=365),
    seed: Optional[int] = Query(default=None),
) -> dict:
    if not authorization:
        raise HTTPException(status_code=401, detail="Missing Authorization header")

    if random_count > page_size:
        random_count = page_size

    me = _laravel_get_json(authorization, "/api/user/me")
    user = me.get("user") if isinstance(me, dict) else None
    if not isinstance(user, dict) or not user.get("id"):
        raise HTTPException(status_code=401, detail="Invalid user")
    role = user.get("role")
    if role is not None and str(role).lower() != "user":
        raise HTTPException(status_code=403, detail="Only users can access this endpoint")

    user_id = int(user["id"])
    buyer_dormitory_id = user.get("dormitory_id")
    buyer_dormitory_id = int(buyer_dormitory_id) if buyer_dormitory_id else None

    engine = _get_db_engine()
    now = _now_utc_naive()
    since = now - timedelta(days=lookback_days)

    try:
        conn = engine.connect()
    except Exception:
        raise HTTPException(status_code=503, detail="Database unavailable")

    with conn:
        buyer_dorm = None
        buyer_university_id = None
        if buyer_dormitory_id is not None:
            try:
                buyer_dorm = conn.execute(
                    text(
                        """
                        SELECT id, dormitory_name, domain, latitude, longitude, is_active, university_id
                        FROM dormitories
                        WHERE id = :id
                        LIMIT 1
                        """
                    ),
                    {"id": buyer_dormitory_id},
                ).mappings().first()
            except ProgrammingError as e:
                if getattr(e.orig, "args", None) and len(e.orig.args) >= 1 and int(e.orig.args[0]) == 1146:
                    table = _missing_table_name_from_programming_error(e) or "unknown"
                    raise HTTPException(
                        status_code=503,
                        detail=f"Database '{os.environ.get('DB_DATABASE', 'suki_db')}' missing table: {table}. Check DB_* env or run Laravel migrations.",
                    )
                raise
            if buyer_dorm is not None:
                buyer_university_id = buyer_dorm.get("university_id")
                buyer_university_id = int(buyer_university_id) if buyer_university_id is not None else None

        try:
            events = conn.execute(
                text(
                    """
                    SELECT id, event_type, product_id, category_id, seller_id, occurred_at
                    FROM behavioral_events
                    WHERE user_id = :user_id AND occurred_at >= :since
                    ORDER BY occurred_at DESC, id DESC
                    LIMIT 500
                    """
                ),
                {"user_id": user_id, "since": since},
            ).mappings().all()
        except ProgrammingError as e:
            if getattr(e.orig, "args", None) and len(e.orig.args) >= 1 and int(e.orig.args[0]) == 1146:
                events = []
            else:
                raise
        low_behavior = len(events) < 5

        last_event_id = 0
        last_event_at: Optional[str] = None
        if len(events) > 0:
            try:
                last_event_id = int(events[0].get("id") or 0)
            except Exception:
                last_event_id = 0
            try:
                occurred_at0 = events[0].get("occurred_at")
                if isinstance(occurred_at0, datetime):
                    last_event_at = occurred_at0.isoformat()
                elif isinstance(occurred_at0, str):
                    last_event_at = occurred_at0
            except Exception:
                last_event_at = None

        last_product_id = 0
        last_product_at: Optional[str] = None
        try:
            last_product_row = conn.execute(
                text(
                    """
                    SELECT MAX(id) AS last_product_id, MAX(created_at) AS last_product_created_at
                    FROM products
                    WHERE status = 'available' AND deleted_at IS NULL
                    """
                )
            ).mappings().first()
            if last_product_row is not None:
                try:
                    last_product_id = int(last_product_row.get("last_product_id") or 0)
                except Exception:
                    last_product_id = 0
                try:
                    ca = last_product_row.get("last_product_created_at")
                    if isinstance(ca, datetime):
                        last_product_at = ca.isoformat()
                    elif isinstance(ca, str):
                        last_product_at = ca
                except Exception:
                    last_product_at = None
        except ProgrammingError as e:
            if not (getattr(e.orig, "args", None) and len(e.orig.args) >= 1 and int(e.orig.args[0]) == 1146):
                raise

        seen_product_ids: Set[int] = set()
        category_scores: Dict[int, float] = {}
        seller_scores: Dict[int, float] = {}

        for idx, e in enumerate(events):
            occurred_at = e.get("occurred_at")
            if isinstance(occurred_at, str):
                try:
                    occurred_at = datetime.fromisoformat(occurred_at.replace("Z", "+00:00")).replace(tzinfo=None)
                except Exception:
                    occurred_at = now
            if not isinstance(occurred_at, datetime):
                occurred_at = now

            w = _event_weight(str(e.get("event_type") or ""))
            w *= _time_decay(occurred_at, now)
            if idx == 0:
                w *= 6.0
            elif idx < 3:
                w *= 4.0
            elif idx < 10:
                w *= 2.0

            pid = e.get("product_id")
            if pid is not None:
                try:
                    seen_product_ids.add(int(pid))
                except Exception:
                    pass

            cid = e.get("category_id")
            if cid is not None:
                try:
                    category_scores[int(cid)] = category_scores.get(int(cid), 0.0) + w
                except Exception:
                    pass

            sid = e.get("seller_id")
            if sid is not None:
                try:
                    seller_scores[int(sid)] = seller_scores.get(int(sid), 0.0) + w
                except Exception:
                    pass

        top_categories = [k for k, _ in sorted(category_scores.items(), key=lambda kv: kv[1], reverse=True)[:10]]
        top_sellers = [k for k, _ in sorted(seller_scores.items(), key=lambda kv: kv[1], reverse=True)[:10]]

        base_query = """
            SELECT
                p.id, p.seller_id, p.dormitory_id, p.category_id, p.condition_level_id,
                p.title, p.price, p.status, p.created_at,
                d.id AS dormitory__id, d.dormitory_name AS dormitory__dormitory_name,
                d.latitude AS dormitory__latitude, d.longitude AS dormitory__longitude,
                d.university_id AS dormitory__university_id,
                u.id AS seller__id, u.username AS seller__username, u.profile_picture AS seller__profile_picture,
                CASE WHEN pl.id IS NULL THEN 0 ELSE 1 END AS is_promoted
            FROM products p
            JOIN dormitories d ON d.id = p.dormitory_id
            JOIN users u ON u.id = p.seller_id
            LEFT JOIN promoted_listings pl ON pl.product_id = p.id AND pl.promoted_until > NOW()
            WHERE p.status = 'available' AND p.deleted_at IS NULL
        """

        params: Dict[str, Any] = {}
        where_parts: List[str] = []

        if seen_product_ids:
            seen_list = list(seen_product_ids)[:2000]
            placeholders = ", ".join([f":seen_{i}" for i in range(len(seen_list))])
            where_parts.append(f"p.id NOT IN ({placeholders})")
            for i, v in enumerate(seen_list):
                params[f"seen_{i}"] = v

        if top_categories or top_sellers:
            or_parts: List[str] = []
            if top_categories:
                placeholders = ", ".join([f":cat_{i}" for i in range(len(top_categories))])
                or_parts.append(f"p.category_id IN ({placeholders})")
                for i, v in enumerate(top_categories):
                    params[f"cat_{i}"] = v
            if top_sellers:
                placeholders = ", ".join([f":sel_{i}" for i in range(len(top_sellers))])
                or_parts.append(f"p.seller_id IN ({placeholders})")
                for i, v in enumerate(top_sellers):
                    params[f"sel_{i}"] = v

            if buyer_dormitory_id is not None:
                or_parts.append("p.dormitory_id = :buyer_dormitory_id")
                params["buyer_dormitory_id"] = buyer_dormitory_id
            if buyer_university_id is not None:
                or_parts.append("d.university_id = :buyer_university_id")
                params["buyer_university_id"] = buyer_university_id

            where_parts.append("(" + " OR ".join(or_parts) + " OR pl.id IS NOT NULL" + ")")

        where_sql = ""
        if where_parts:
            where_sql = " AND " + " AND ".join(where_parts)

        try:
            rows = conn.execute(
                text(base_query + where_sql + " ORDER BY p.created_at DESC LIMIT 600"),
                params,
            ).mappings().all()
        except ProgrammingError as e:
            if getattr(e.orig, "args", None) and len(e.orig.args) >= 1 and int(e.orig.args[0]) == 1146:
                table = _missing_table_name_from_programming_error(e) or "unknown"
                raise HTTPException(
                    status_code=503,
                    detail=f"Database '{os.environ.get('DB_DATABASE', 'suki_db')}' missing table: {table}. Check DB_* env or run Laravel migrations.",
                )
            raise

        if len(rows) < 200:
            try:
                rows_more = conn.execute(
                    text(base_query + " ORDER BY p.created_at DESC LIMIT 600"),
                    {},
                ).mappings().all()
            except ProgrammingError as e:
                if getattr(e.orig, "args", None) and len(e.orig.args) >= 1 and int(e.orig.args[0]) == 1146:
                    table = _missing_table_name_from_programming_error(e) or "unknown"
                    raise HTTPException(
                        status_code=503,
                        detail=f"Database '{os.environ.get('DB_DATABASE', 'suki_db')}' missing table: {table}. Check DB_* env or run Laravel migrations.",
                    )
                raise
            known = {int(r["id"]) for r in rows}
            for r in rows_more:
                pid = int(r["id"])
                if pid not in known and pid not in seen_product_ids:
                    rows.append(r)
                    known.add(pid)
                if len(rows) >= 600:
                    break

        buyer_coords = _parse_lat_lng(
            buyer_dorm.get("latitude") if buyer_dorm else None,
            buyer_dorm.get("longitude") if buyer_dorm else None,
            buyer_dorm.get("location") if buyer_dorm else None,
        )

        scored: List[Tuple[float, Dict[str, Any]]] = []
        for r in rows:
            score = 0.0

            cid = r.get("category_id")
            if cid is not None:
                try:
                    score += 1.5 * category_scores.get(int(cid), 0.0)
                except Exception:
                    pass

            sid = r.get("seller_id")
            if sid is not None:
                try:
                    score += 1.0 * seller_scores.get(int(sid), 0.0)
                except Exception:
                    pass

            if int(r.get("is_promoted") or 0) == 1:
                score += 3.0

            created_at = r.get("created_at")
            if isinstance(created_at, str):
                try:
                    created_at = datetime.fromisoformat(created_at.replace("Z", "+00:00")).replace(tzinfo=None)
                except Exception:
                    created_at = None
            if isinstance(created_at, datetime):
                age_days = max(0.0, (now - created_at).total_seconds() / 86400.0)
                score += 5.0 * math.exp(-age_days / 7.0)

            if buyer_dormitory_id is not None:
                if int(r.get("dormitory_id") or 0) == buyer_dormitory_id:
                    score += 50.0
                else:
                    uni = r.get("dormitory__university_id")
                    uni = int(uni) if uni is not None else None
                    if buyer_university_id is not None and uni == buyer_university_id:
                        score += 20.0

            product_coords = _parse_lat_lng(
                r.get("dormitory__latitude"),
                r.get("dormitory__longitude"),
                r.get("dormitory__location"),
            )
            distance_km: Optional[float] = None
            if buyer_coords is not None and product_coords is not None:
                distance_km = _haversine_km(buyer_coords, product_coords)
                score += 30.0 * math.exp(-distance_km / 2.0)

            r_dict = dict(r)
            r_dict["_distance_km"] = distance_km
            scored.append((score, r_dict))

        if low_behavior:
            def _local_rank_key(item: Tuple[float, Dict[str, Any]]) -> Tuple[int, float, float]:
                r = item[1]
                priority = 2
                if buyer_dormitory_id is not None and int(r.get("dormitory_id") or 0) == buyer_dormitory_id:
                    priority = 0
                else:
                    uni = r.get("dormitory__university_id")
                    uni = int(uni) if uni is not None else None
                    if buyer_university_id is not None and uni == buyer_university_id:
                        priority = 1

                distance = r.get("_distance_km")
                distance_sort = float(distance) if distance is not None else 1.0e9

                created_at = r.get("created_at")
                created_ts = 0.0
                if isinstance(created_at, str):
                    try:
                        created_at = datetime.fromisoformat(created_at.replace("Z", "+00:00")).replace(tzinfo=None)
                    except Exception:
                        created_at = None
                if isinstance(created_at, datetime):
                    created_ts = created_at.timestamp()

                return priority, distance_sort, -created_ts

            ranked = [r for _, r in sorted(scored, key=_local_rank_key)]
        else:
            scored.sort(key=lambda x: (x[0], x[1].get("created_at") or ""), reverse=True)
            ranked = [r for _, r in scored]

        deterministic_count = max(0, page_size - random_count)
        start = (page - 1) * deterministic_count if deterministic_count > 0 else 0
        base_items = ranked[start : start + deterministic_count] if deterministic_count > 0 else []

        base_ids = {int(p["id"]) for p in base_items}
        pool = [p for p in ranked if int(p["id"]) not in base_ids]

        if seed is None:
            seed_value = (
                user_id * 1000003 + page * 9176 + last_event_id * 1013 + last_product_id * 7919
            ) % (2**31 - 1)
            if seed_value <= 0:
                seed_value = 1
        else:
            seed_value = seed
        rng = random.Random(seed_value)

        random_items = pool[:]
        rng.shuffle(random_items)
        random_items = random_items[:random_count]

        combined = base_items + random_items
        combined_ids = [int(p["id"]) for p in combined]

        images = []
        tags = []
        if combined_ids:
            placeholders = ", ".join([f":pid_{i}" for i in range(len(combined_ids))])
            pid_params = {f"pid_{i}": v for i, v in enumerate(combined_ids)}

            try:
                images = conn.execute(
                    text(
                        f"""
                        SELECT product_id, image_url, image_thumbnail_url, is_primary
                        FROM product_images
                        WHERE product_id IN ({placeholders})
                        ORDER BY is_primary DESC, id ASC
                        """
                    ),
                    pid_params,
                ).mappings().all()
            except ProgrammingError as e:
                if not (getattr(e.orig, "args", None) and len(e.orig.args) >= 1 and int(e.orig.args[0]) == 1146):
                    raise
                images = []

            try:
                tags = conn.execute(
                    text(
                        f"""
                        SELECT pt.product_id, t.id, t.name
                        FROM product_tags pt
                        JOIN tags t ON t.id = pt.tag_id
                        WHERE pt.product_id IN ({placeholders})
                        ORDER BY t.id ASC
                        """
                    ),
                    pid_params,
                ).mappings().all()
            except ProgrammingError as e:
                if not (getattr(e.orig, "args", None) and len(e.orig.args) >= 1 and int(e.orig.args[0]) == 1146):
                    raise
                tags = []

    images_by_product = _group_by_key([dict(x) for x in images], "product_id")
    tag_rows_by_product = _group_by_key([dict(x) for x in tags], "product_id")

    payload_products = []
    for p in combined:
        pid = int(p["id"])
        imgs = images_by_product.get(pid, [])
        image_thumbnail_url = None
        if imgs:
            first = imgs[0]
            image_thumbnail_url = first.get("image_thumbnail_url")
        prod: Dict[str, Any] = {
            "title": p.get("title"),
            "price": float(p["price"]) if p.get("price") is not None else None,
            "status": p.get("status"),
            "created_at": p.get("created_at"),
            "category_id": p.get("category_id"),
            "condition_level_id": p.get("condition_level_id"),
            "is_promoted": int(p.get("is_promoted") or 0),
            "dormitory": {
                "id": p.get("dormitory__id"),
                "dormitory_name": p.get("dormitory__dormitory_name"),
                "university_id": p.get("dormitory__university_id"),
            },
            "image_thumbnail_url": image_thumbnail_url,
            "tags": [
                {"id": t.get("id"), "name": t.get("name")} for t in tag_rows_by_product.get(pid, [])
            ],
            "distance_km": p.get("_distance_km"),
        }
        payload_products.append(prod)

    return {
        "message": "Recommended products retrieved successfully",
        "page": page,
        "page_size": page_size,
        "random_count": random_count,
        "last_event_id": last_event_id,
        "last_event_at": last_event_at,
        "last_product_id": last_product_id,
        "last_product_at": last_product_at,
        "products": payload_products[:page_size],
    }
