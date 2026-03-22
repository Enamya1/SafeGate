import base64
import json
import hmac
import math
import os
import random
import re
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta
from typing import Any, Dict, List, Optional, Set, Tuple

from fastapi import APIRouter, File, Form, Header, HTTPException, Query, Request, UploadFile
from sqlalchemy import create_engine, text
from sqlalchemy.engine import Engine
from sqlalchemy.exc import ProgrammingError

from app.ai_manager import AIModelManager
from app.visual_search import VisualSearchEngine

router = APIRouter()
py_router = APIRouter()

_engine: Optional[Engine] = None
_visual_search_engine: Optional[VisualSearchEngine] = None


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
    database = os.environ.get("DB_DATABASE", "XiaoWu")
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


def _get_visual_search_engine() -> VisualSearchEngine:
    global _visual_search_engine
    if _visual_search_engine is not None:
        return _visual_search_engine
    _visual_search_engine = VisualSearchEngine()
    return _visual_search_engine


def _laravel_get_json(authorization: str, path: str) -> Dict[str, Any]:
    configured = (os.environ.get("LARAVEL_BASE_URL") or "").strip().rstrip("/")
    base_candidates = [configured, "http://127.0.0.1:8000", "http://localhost:8000"]
    tried = set()
    last_error: Optional[Exception] = None

    for base_url in base_candidates:
        if not base_url or base_url in tried:
            continue
        tried.add(base_url)
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
        except Exception as e:
            last_error = e
            continue

    detail = "Could not reach Laravel"
    if last_error is not None:
        detail = f"Could not reach Laravel: {type(last_error).__name__}"
    raise HTTPException(status_code=502, detail=detail)


def _resolve_image_url(image_url: Optional[str]) -> Optional[str]:
    if not isinstance(image_url, str) or image_url.strip() == "":
        return None

    normalized = image_url.strip()
    if normalized.startswith("http://") or normalized.startswith("https://"):
        parsed = urllib.parse.urlparse(normalized)
        if parsed.netloc in {"localhost", "127.0.0.1", "localhost:80", "127.0.0.1:80"}:
            normalized = parsed.path or "/"
            if parsed.query:
                normalized = f"{normalized}?{parsed.query}"
            if parsed.fragment:
                normalized = f"{normalized}#{parsed.fragment}"
        else:
            return normalized

    base_url = (os.environ.get("LARAVEL_BASE_URL") or "http://127.0.0.1:8000").strip().rstrip("/")
    if not normalized.startswith("/"):
        normalized = f"/{normalized}"
    return f"{base_url}{normalized}"


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
    if t in {"view", "click", "search"}:
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


def _extract_price_cap(message: str) -> Optional[float]:
    patterns = [
        r"(?:under|below|less than|<=)\s*\$?\s*(\d+(?:\.\d+)?)",
        r"\$?\s*(\d+(?:\.\d+)?)\s*(?:or less|max(?:imum)?)",
    ]
    for pattern in patterns:
        m = re.search(pattern, message, re.IGNORECASE)
        if m:
            try:
                value = float(m.group(1))
                if value > 0:
                    return value
            except Exception:
                continue
    return None


def _extract_product_id(message: str) -> Optional[int]:
    patterns = [
        r"(?:product\s*id|id)\s*[:#]?\s*(\d+)",
        r"\bproduct\s+(\d+)\b",
    ]
    for pattern in patterns:
        m = re.search(pattern, message, re.IGNORECASE)
        if m:
            try:
                value = int(m.group(1))
                if value > 0:
                    return value
            except Exception:
                continue
    return None


def _normalize_text(value: str) -> str:
    return re.sub(r"\s+", " ", value.strip().lower())


def _load_category_names(conn) -> List[str]:
    try:
        rows = conn.execute(text("SELECT name FROM categories ORDER BY id ASC")).mappings().all()
    except Exception:
        return []
    out: List[str] = []
    for row in rows:
        name = row.get("name")
        if isinstance(name, str) and name.strip():
            out.append(name.strip())
    return out


def _infer_category_name(message: str, category_names: List[str]) -> Optional[str]:
    haystack = _normalize_text(message)
    for name in category_names:
        escaped = re.escape(_normalize_text(name))
        if re.search(rf"\b{escaped}\b", haystack):
            return name
    return None


def _visibility_clause(user_dormitory_id: Optional[int]) -> Tuple[str, Dict[str, Any]]:
    if user_dormitory_id is None:
        return "p.dormitory_id IS NULL", {}
    return "(p.dormitory_id = :user_dormitory_id OR p.dormitory_id IS NULL)", {"user_dormitory_id": user_dormitory_id}


def _serialize_product_row(row: Dict[str, Any]) -> Dict[str, Any]:
    created_at = row.get("created_at")
    if isinstance(created_at, datetime):
        created_at = created_at.isoformat()
    price = row.get("price")
    try:
        price = float(price) if price is not None else None
    except Exception:
        price = None
    currency = row.get("currency")
    if isinstance(currency, str) and currency.strip():
        currency = currency.strip().upper()
    else:
        currency = "CNY"
    return {
        "id": row.get("id"),
        "title": row.get("title"),
        "description": row.get("description"),
        "price": price,
        "currency": currency,
        "status": row.get("status"),
        "created_at": created_at,
        "category": row.get("category_name"),
        "condition_level": row.get("condition_level_name"),
        "image_thumbnail_url": row.get("image_thumbnail_url"),
        "tags": row.get("tags") or [],
    }


def _fetch_tags_for_products(conn, product_ids: List[int]) -> Dict[int, List[str]]:
    if not product_ids:
        return {}
    placeholders = ", ".join([f":pid_{i}" for i in range(len(product_ids))])
    params = {f"pid_{i}": v for i, v in enumerate(product_ids)}
    rows = conn.execute(
        text(
            f"""
            SELECT pt.product_id, t.name
            FROM product_tags pt
            JOIN tags t ON t.id = pt.tag_id
            WHERE pt.product_id IN ({placeholders})
            ORDER BY t.name ASC
            """
        ),
        params,
    ).mappings().all()
    tags_by_product: Dict[int, List[str]] = {}
    for row in rows:
        pid = row.get("product_id")
        name = row.get("name")
        if pid is None or not isinstance(name, str):
            continue
        pid_int = int(pid)
        tags_by_product.setdefault(pid_int, []).append(name)
    return tags_by_product


def _search_products(
    conn,
    keyword: str,
    user_dormitory_id: Optional[int],
    limit: int = 20,
    offset: int = 0,
) -> List[Dict[str, Any]]:
    visibility_sql, visibility_params = _visibility_clause(user_dormitory_id)
    rows = conn.execute(
        text(
            f"""
            SELECT
                p.id, p.title, p.description, p.price, p.currency, p.status, p.created_at,
                c.name AS category_name,
                cl.name AS condition_level_name,
                (
                    SELECT pi.image_thumbnail_url
                    FROM product_images pi
                    WHERE pi.product_id = p.id
                    ORDER BY pi.is_primary DESC, pi.id ASC
                    LIMIT 1
                ) AS image_thumbnail_url
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN condition_levels cl ON cl.id = p.condition_level_id
            WHERE p.status = 'available'
              AND p.deleted_at IS NULL
              AND {visibility_sql}
              AND (
                p.title LIKE :keyword_like
                OR p.description LIKE :keyword_like
                OR c.name LIKE :keyword_like
              )
            ORDER BY p.created_at DESC, p.id DESC
            LIMIT :limit OFFSET :offset
            """
        ),
        {
            **visibility_params,
            "keyword_like": f"%{keyword.strip()}%",
            "limit": int(limit),
            "offset": int(offset),
        },
    ).mappings().all()
    products = [dict(row) for row in rows]
    tags_by_product = _fetch_tags_for_products(conn, [int(p["id"]) for p in products])
    for product in products:
        pid = int(product["id"])
        product["tags"] = tags_by_product.get(pid, [])
    return [_serialize_product_row(product) for product in products]


def _search_by_price(
    conn,
    max_price: float,
    user_dormitory_id: Optional[int],
    category_name: Optional[str] = None,
    limit: int = 20,
) -> List[Dict[str, Any]]:
    visibility_sql, visibility_params = _visibility_clause(user_dormitory_id)
    category_sql = ""
    params: Dict[str, Any] = {
        **visibility_params,
        "max_price": max_price,
        "limit": int(limit),
    }
    if category_name:
        category_sql = " AND c.name = :category_name"
        params["category_name"] = category_name
    rows = conn.execute(
        text(
            f"""
            SELECT
                p.id, p.title, p.description, p.price, p.currency, p.status, p.created_at,
                c.name AS category_name,
                cl.name AS condition_level_name,
                (
                    SELECT pi.image_thumbnail_url
                    FROM product_images pi
                    WHERE pi.product_id = p.id
                    ORDER BY pi.is_primary DESC, pi.id ASC
                    LIMIT 1
                ) AS image_thumbnail_url
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN condition_levels cl ON cl.id = p.condition_level_id
            WHERE p.status = 'available'
              AND p.deleted_at IS NULL
              AND {visibility_sql}
              AND p.price <= :max_price
              {category_sql}
            ORDER BY p.price ASC, p.created_at DESC, p.id DESC
            LIMIT :limit
            """
        ),
        params,
    ).mappings().all()
    products = [dict(row) for row in rows]
    tags_by_product = _fetch_tags_for_products(conn, [int(p["id"]) for p in products])
    for product in products:
        pid = int(product["id"])
        product["tags"] = tags_by_product.get(pid, [])
    return [_serialize_product_row(product) for product in products]


def _search_by_category(
    conn,
    category_name: str,
    user_dormitory_id: Optional[int],
    limit: int = 20,
) -> List[Dict[str, Any]]:
    visibility_sql, visibility_params = _visibility_clause(user_dormitory_id)
    rows = conn.execute(
        text(
            f"""
            SELECT
                p.id, p.title, p.description, p.price, p.currency, p.status, p.created_at,
                c.name AS category_name,
                cl.name AS condition_level_name,
                (
                    SELECT pi.image_thumbnail_url
                    FROM product_images pi
                    WHERE pi.product_id = p.id
                    ORDER BY pi.is_primary DESC, pi.id ASC
                    LIMIT 1
                ) AS image_thumbnail_url
            FROM products p
            JOIN categories c ON c.id = p.category_id
            LEFT JOIN condition_levels cl ON cl.id = p.condition_level_id
            WHERE p.status = 'available'
              AND p.deleted_at IS NULL
              AND {visibility_sql}
              AND c.name = :category_name
            ORDER BY p.created_at DESC, p.id DESC
            LIMIT :limit
            """
        ),
        {
            **visibility_params,
            "category_name": category_name,
            "limit": int(limit),
        },
    ).mappings().all()
    products = [dict(row) for row in rows]
    tags_by_product = _fetch_tags_for_products(conn, [int(p["id"]) for p in products])
    for product in products:
        pid = int(product["id"])
        product["tags"] = tags_by_product.get(pid, [])
    return [_serialize_product_row(product) for product in products]


def _get_product(
    conn,
    product_id: int,
    user_dormitory_id: Optional[int],
) -> List[Dict[str, Any]]:
    visibility_sql, visibility_params = _visibility_clause(user_dormitory_id)
    row = conn.execute(
        text(
            f"""
            SELECT
                p.id, p.title, p.description, p.price, p.currency, p.status, p.created_at,
                c.name AS category_name,
                cl.name AS condition_level_name,
                (
                    SELECT pi.image_thumbnail_url
                    FROM product_images pi
                    WHERE pi.product_id = p.id
                    ORDER BY pi.is_primary DESC, pi.id ASC
                    LIMIT 1
                ) AS image_thumbnail_url
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN condition_levels cl ON cl.id = p.condition_level_id
            WHERE p.id = :product_id
              AND p.status = 'available'
              AND p.deleted_at IS NULL
              AND {visibility_sql}
            LIMIT 1
            """
        ),
        {
            **visibility_params,
            "product_id": int(product_id),
        },
    ).mappings().first()
    if row is None:
        return []
    product = dict(row)
    tags_by_product = _fetch_tags_for_products(conn, [int(product["id"])])
    product["tags"] = tags_by_product.get(int(product["id"]), [])
    return [_serialize_product_row(product)]


def _get_similar_products(
    conn,
    product_id: int,
    user_dormitory_id: Optional[int],
    limit: int = 10,
) -> List[Dict[str, Any]]:
    visibility_sql, visibility_params = _visibility_clause(user_dormitory_id)
    target = conn.execute(
        text(
            """
            SELECT id, category_id, price
            FROM products
            WHERE id = :product_id
              AND deleted_at IS NULL
            LIMIT 1
            """
        ),
        {"product_id": int(product_id)},
    ).mappings().first()
    if target is None:
        return []
    rows = conn.execute(
        text(
            f"""
            SELECT
                p.id, p.title, p.description, p.price, p.currency, p.status, p.created_at,
                c.name AS category_name,
                cl.name AS condition_level_name,
                (
                    SELECT pi.image_thumbnail_url
                    FROM product_images pi
                    WHERE pi.product_id = p.id
                    ORDER BY pi.is_primary DESC, pi.id ASC
                    LIMIT 1
                ) AS image_thumbnail_url,
                CASE
                    WHEN p.category_id = :target_category_id THEN 3
                    WHEN ABS(p.price - :target_price) <= (:target_price * 0.3) THEN 2
                    ELSE 1
                END AS similarity_score
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN condition_levels cl ON cl.id = p.condition_level_id
            WHERE p.id <> :product_id
              AND p.status = 'available'
              AND p.deleted_at IS NULL
              AND {visibility_sql}
            ORDER BY similarity_score DESC, p.created_at DESC, p.id DESC
            LIMIT :limit
            """
        ),
        {
            **visibility_params,
            "product_id": int(product_id),
            "target_category_id": target.get("category_id"),
            "target_price": float(target.get("price") or 0),
            "limit": int(limit),
        },
    ).mappings().all()
    products = [dict(row) for row in rows]
    tags_by_product = _fetch_tags_for_products(conn, [int(p["id"]) for p in products])
    for product in products:
        pid = int(product["id"])
        product["tags"] = tags_by_product.get(pid, [])
    return [_serialize_product_row(product) for product in products]


def _infer_function(
    message: str,
    conn,
    user_dormitory_id: Optional[int],
) -> Tuple[str, Dict[str, Any], List[Dict[str, Any]]]:
    category_names = _load_category_names(conn)
    normalized = _normalize_text(message)
    product_keywords = {
        "find",
        "search",
        "buy",
        "sell",
        "price",
        "cheap",
        "budget",
        "recommend",
        "suggest",
        "product",
        "item",
        "similar",
        "detail",
        "about",
        "laptop",
        "phone",
        "book",
        "keyboard",
        "exchange",
    }
    has_product_keyword = any(re.search(rf"\b{re.escape(keyword)}\b", normalized) for keyword in product_keywords)
    has_category_hint = any(re.search(rf"\b{re.escape(_normalize_text(name))}\b", normalized) for name in category_names)
    product_id = _extract_product_id(message)
    price_cap = _extract_price_cap(message)

    if not has_product_keyword and not has_category_hint and product_id is None and price_cap is None:
        return "general_chat", {}, []

    category_name = _infer_category_name(message, category_names)

    if "similar" in normalized and product_id is not None:
        args = {"product_id": product_id, "limit": 10}
        return "get_similar_products", args, _get_similar_products(conn, product_id, user_dormitory_id, limit=10)

    if ("detail" in normalized or "about" in normalized) and product_id is not None:
        args = {"product_id": product_id}
        return "get_product", args, _get_product(conn, product_id, user_dormitory_id)

    if price_cap is not None:
        args: Dict[str, Any] = {"max_price": price_cap, "limit": 20}
        if category_name:
            args["category"] = category_name
        return "search_by_price", args, _search_by_price(
            conn,
            max_price=price_cap,
            user_dormitory_id=user_dormitory_id,
            category_name=category_name,
            limit=20,
        )

    if category_name:
        args = {"category": category_name, "limit": 20}
        return "search_by_category", args, _search_by_category(conn, category_name, user_dormitory_id, limit=20)

    if product_id is not None:
        args = {"product_id": product_id}
        return "get_product", args, _get_product(conn, product_id, user_dormitory_id)

    keyword = message.strip()
    args = {"keyword": keyword, "limit": 20, "offset": 0}
    return "search_products", args, _search_products(conn, keyword, user_dormitory_id, limit=20, offset=0)


def _fallback_response_text(user_message: str, function_name: str, products: List[Dict[str, Any]]) -> str:
    if not products:
        return "I could not find matching products right now. Try adjusting your request."
    if function_name == "get_product":
        product = products[0]
        price = product.get("price")
        return f"{product.get('title')} is available for ${price}. Category: {product.get('category')}."
    top = products[:3]
    lines = []
    for p in top:
        lines.append(f"- {p.get('title')} (${p.get('price')})")
    return "I found these options:\n" + "\n".join(lines)


def _read_user_context_from_payload(payload: Dict[str, Any]) -> Optional[Dict[str, Any]]:
    ctx = payload.get("user_context")
    if not isinstance(ctx, dict):
        return None
    user_id = ctx.get("id")
    if user_id is None:
        return None
    try:
        user_id_int = int(user_id)
    except Exception:
        return None
    role = ctx.get("role")
    if role is not None and str(role).strip().lower() != "user":
        raise HTTPException(status_code=403, detail="Only users can access this endpoint")
    dormitory_raw = ctx.get("dormitory_id")
    dormitory_id = None
    if dormitory_raw not in (None, ""):
        try:
            dormitory_id = int(dormitory_raw)
        except Exception:
            dormitory_id = None
    return {"id": user_id_int, "dormitory_id": dormitory_id}


def _has_valid_internal_token(header_token: Optional[str]) -> bool:
    expected = (os.environ.get("PYTHON_INTERNAL_TOKEN") or "").strip()
    provided = (header_token or "").strip()
    if not expected or not provided:
        return False
    return hmac.compare_digest(provided, expected)


def _is_loopback_request(request: Request) -> bool:
    host = (request.client.host if request.client else "") or ""
    return host in {"127.0.0.1", "::1", "localhost"}


@router.post("/ai/respond")
def ai_respond(
    request: Request,
    payload: Dict[str, Any],
    authorization: Optional[str] = Header(default=None),
    x_internal_token: Optional[str] = Header(default=None),
) -> dict:
    if not authorization:
        raise HTTPException(status_code=401, detail="Missing Authorization header")

    message = payload.get("message")
    if not isinstance(message, str) or not message.strip():
        raise HTTPException(status_code=422, detail="message is required")

    message_type_raw = payload.get("message_type")
    message_type = "text" if not isinstance(message_type_raw, str) else message_type_raw.strip().lower()
    if message_type not in {"text", "voice"}:
        raise HTTPException(status_code=422, detail="message_type must be text or voice")

    internal_user = None
    if _has_valid_internal_token(x_internal_token):
        internal_user = _read_user_context_from_payload(payload)
    elif _is_loopback_request(request):
        internal_user = _read_user_context_from_payload(payload)
    if internal_user is not None:
        user_id = internal_user["id"]
        user_dormitory_id = internal_user["dormitory_id"]
    else:
        me = _laravel_get_json(authorization, "/api/user/me")
        user = me.get("user") if isinstance(me, dict) else None
        if not isinstance(user, dict) or not user.get("id"):
            raise HTTPException(status_code=401, detail="Invalid user")
        role = user.get("role")
        if role is not None and str(role).lower() != "user":
            raise HTTPException(status_code=403, detail="Only users can access this endpoint")
        user_id = int(user["id"])
        user_dormitory_id = user.get("dormitory_id")
        user_dormitory_id = int(user_dormitory_id) if user_dormitory_id else None

    engine = _get_db_engine()
    try:
        conn = engine.connect()
    except Exception:
        raise HTTPException(status_code=503, detail="Database unavailable")

    with conn:
        try:
            function_name, function_arguments, products = _infer_function(message, conn, user_dormitory_id)
        except ProgrammingError as e:
            if getattr(e.orig, "args", None) and len(e.orig.args) >= 1 and int(e.orig.args[0]) == 1146:
                table = _missing_table_name_from_programming_error(e) or "unknown"
                raise HTTPException(
                    status_code=503,
                    detail=f"Database '{os.environ.get('DB_DATABASE', 'XiaoWu')}' missing table: {table}. Check DB_* env or run Laravel migrations.",
                )
            raise

    function_calls = [
        {
            "name": function_name,
            "arguments": function_arguments,
            "result_count": len(products),
        }
    ]

    response_text = _fallback_response_text(message, function_name, products)
    ai_key = os.environ.get("AI_API_KEY", "").strip()
    if ai_key:
        try:
            manager = AIModelManager()
            if function_name == "general_chat":
                ai_result = manager.generate(
                    user_prompt=message,
                    system_prompt=(
                        "You are XiaoWu assistant. "
                        "Answer normal questions clearly and concisely. "
                        "If the user asks to find products, ask for product details like budget, category, or keywords."
                    ),
                )
            else:
                ai_result = manager.generate(
                    user_prompt=(
                        f"User request: {message}\n"
                        f"Function executed: {function_name}\n"
                        f"Function arguments: {json.dumps(function_arguments, ensure_ascii=False)}\n"
                        f"Result JSON: {json.dumps(products[:10], ensure_ascii=False)}"
                    ),
                    system_prompt=(
                        "You are a shopping assistant for XiaoWu. "
                        "Use only the provided function result. "
                        "Be concise and helpful."
                    ),
                )
            ai_content = ai_result.get("content")
            if isinstance(ai_content, str) and ai_content.strip():
                response_text = ai_content.strip()
        except Exception:
            pass
    elif function_name == "general_chat":
        response_text = "I can help with general questions and product search. Tell me what you need."

    prompt_tokens = max(1, len(message.split()))
    completion_tokens = max(1, len(response_text.split()))
    total_tokens = prompt_tokens + completion_tokens

    return {
        "response": response_text,
        "function_calls": function_calls,
        "products": products,
        "usage": {
            "total_tokens": total_tokens,
            "prompt_tokens": prompt_tokens,
            "completion_tokens": completion_tokens,
        },
        "message_type": message_type,
        "user_id": user_id,
    }



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


@py_router.post("/py/api/internal/visual-search/index")
async def internal_visual_search_index(
    request: Request,
    payload: Dict[str, Any],
    x_internal_token: Optional[str] = Header(default=None),
) -> dict:
    if not (_has_valid_internal_token(x_internal_token) or _is_loopback_request(request)):
        raise HTTPException(status_code=401, detail="Unauthorized internal request")

    product_id_raw = payload.get("product_id")
    product_image_id_raw = payload.get("product_image_id")
    image_url_raw = payload.get("image_url")
    image_bytes_base64_raw = payload.get("image_bytes_base64")
    has_image_url = isinstance(image_url_raw, str) and image_url_raw.strip() != ""
    has_image_bytes = isinstance(image_bytes_base64_raw, str) and image_bytes_base64_raw.strip() != ""
    if product_id_raw is None or product_image_id_raw is None or (not has_image_url and not has_image_bytes):
        raise HTTPException(status_code=422, detail="product_id, product_image_id, and image_url or image_bytes_base64 are required")

    try:
        product_id = int(product_id_raw)
        product_image_id = int(product_image_id_raw)
    except Exception:
        raise HTTPException(status_code=422, detail="product_id and product_image_id must be integers")

    engine = _get_db_engine()
    try:
        conn = engine.connect()
    except Exception:
        raise HTTPException(status_code=503, detail="Database unavailable")

    visual_engine = _get_visual_search_engine()
    with conn:
        try:
            if has_image_bytes:
                try:
                    image_bytes = base64.b64decode(str(image_bytes_base64_raw), validate=True)
                except Exception:
                    raise HTTPException(status_code=422, detail="Invalid image_bytes_base64")
                if len(image_bytes) == 0:
                    raise HTTPException(status_code=422, detail="image_bytes_base64 is empty")
                result = visual_engine.index_single_image_bytes(conn, product_id, product_image_id, image_bytes)
            else:
                result = visual_engine.index_single_image(conn, product_id, product_image_id, str(image_url_raw).strip())
            conn.commit()
        except ProgrammingError as e:
            if getattr(e.orig, "args", None) and len(e.orig.args) >= 1 and int(e.orig.args[0]) == 1146:
                table = visual_engine.missing_table_name_from_programming_error(e) or "unknown"
                raise HTTPException(
                    status_code=503,
                    detail=f"Database '{os.environ.get('DB_DATABASE', 'XiaoWu')}' missing table: {table}. Check DB_* env or run Laravel migrations.",
                )
            raise
        except ValueError as e:
            raise HTTPException(status_code=422, detail=str(e))
        except RuntimeError as e:
            raise HTTPException(status_code=503, detail=str(e))
        except urllib.error.HTTPError as e:
            body = e.read().decode("utf-8") if hasattr(e, "read") else ""
            raise HTTPException(status_code=422, detail=body or "Could not fetch image")
        except HTTPException:
            raise
        except Exception as e:
            raise HTTPException(status_code=500, detail=f"Indexing failed: {type(e).__name__}")

    return {
        "message": "Image embedding indexed successfully",
        "indexed": result,
    }


@py_router.post("/py/api/user/search/visual")
async def visual_search(
    request: Request,
    authorization: Optional[str] = Header(default=None),
    x_internal_token: Optional[str] = Header(default=None),
    x_user_id: Optional[str] = Header(default=None),
    x_user_role: Optional[str] = Header(default=None),
    x_user_dormitory_id: Optional[str] = Header(default=None),
    image: UploadFile = File(...),
    top_k: int = Form(default=12),
) -> dict:
    internal_trusted = _has_valid_internal_token(x_internal_token) or _is_loopback_request(request)

    user: Optional[Dict[str, Any]] = None
    if internal_trusted and x_user_id is not None and str(x_user_id).strip() != "":
        try:
            uid = int(str(x_user_id).strip())
        except Exception:
            raise HTTPException(status_code=422, detail="Invalid X-User-Id header")
        role_value = (x_user_role or "user").strip().lower()
        if role_value != "user":
            raise HTTPException(status_code=403, detail="Only users can access this endpoint")
        dormitory_value: Optional[int] = None
        if x_user_dormitory_id is not None and str(x_user_dormitory_id).strip() != "":
            try:
                dormitory_value = int(str(x_user_dormitory_id).strip())
            except Exception:
                dormitory_value = None
        user = {
            "id": uid,
            "role": role_value,
            "dormitory_id": dormitory_value,
        }
    else:
        if not authorization:
            raise HTTPException(status_code=401, detail="Missing Authorization header")

        me = _laravel_get_json(authorization, "/api/user/me")
        user = me.get("user") if isinstance(me, dict) else None
        if not isinstance(user, dict) or not user.get("id"):
            raise HTTPException(status_code=401, detail="Invalid user")
        role = user.get("role")
        if role is not None and str(role).lower() != "user":
            raise HTTPException(status_code=403, detail="Only users can access this endpoint")

    content_type = (image.content_type or "").lower()
    allowed_types = {"image/jpeg", "image/jpg", "image/png", "image/webp"}
    if content_type not in allowed_types:
        raise HTTPException(status_code=422, detail="Unsupported image format")

    image_bytes = await image.read()
    if not image_bytes:
        raise HTTPException(status_code=422, detail="Image file is empty")

    max_upload_mb = _get_env_int("VISUAL_SEARCH_MAX_UPLOAD_MB", 8)
    if len(image_bytes) > (max_upload_mb * 1024 * 1024):
        raise HTTPException(status_code=422, detail=f"Image exceeds {max_upload_mb} MB limit")

    engine = _get_db_engine()
    try:
        conn = engine.connect()
    except Exception:
        raise HTTPException(status_code=503, detail="Database unavailable")

    visual_engine = _get_visual_search_engine()
    with conn:
        try:
            result = visual_engine.search(conn, image_bytes, top_k=top_k)
        except ProgrammingError as e:
            if getattr(e.orig, "args", None) and len(e.orig.args) >= 1 and int(e.orig.args[0]) == 1146:
                table = visual_engine.missing_table_name_from_programming_error(e) or "unknown"
                raise HTTPException(
                    status_code=503,
                    detail=f"Database '{os.environ.get('DB_DATABASE', 'XiaoWu')}' missing table: {table}. Check DB_* env or run Laravel migrations.",
                )
            raise
        except ValueError as e:
            raise HTTPException(status_code=422, detail=str(e))
        except RuntimeError as e:
            raise HTTPException(status_code=503, detail=str(e))
        except Exception as e:
            raise HTTPException(status_code=500, detail=f"Visual search failed: {type(e).__name__}")

    return {
        "message": "Visual search completed successfully",
        "product_ids": result["product_ids"],
        "matches": result["matches"],
        "model_name": result["model_name"],
        "embedding_dim": result["embedding_dim"],
    }


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
                        detail=f"Database '{os.environ.get('DB_DATABASE', 'XiaoWu')}' missing table: {table}. Check DB_* env or run Laravel migrations.",
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
                p.title, p.price, p.currency, p.status, p.created_at,
                COALESCE(d_user.latitude, d_product.latitude) AS dormitory__latitude,
                COALESCE(d_user.longitude, d_product.longitude) AS dormitory__longitude,
                COALESCE(d_user.university_id, d_product.university_id) AS dormitory__university_id,
                cl.id AS condition_level__id, cl.name AS condition_level__name,
                cl.level AS condition_level__level,
                u.id AS seller__id, u.username AS seller__username, u.profile_picture AS seller__profile_picture,
                CASE WHEN pl.id IS NULL THEN 0 ELSE 1 END AS is_promoted
            FROM products p
            JOIN users u ON u.id = p.seller_id
            LEFT JOIN dormitories d_user ON d_user.id = u.dormitory_id
            LEFT JOIN dormitories d_product ON d_product.id = p.dormitory_id
            LEFT JOIN condition_levels cl ON cl.id = p.condition_level_id
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
                or_parts.append("COALESCE(d_user.university_id, d_product.university_id) = :buyer_university_id")
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
                    detail=f"Database '{os.environ.get('DB_DATABASE', 'XiaoWu')}' missing table: {table}. Check DB_* env or run Laravel migrations.",
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
                        detail=f"Database '{os.environ.get('DB_DATABASE', 'XiaoWu')}' missing table: {table}. Check DB_* env or run Laravel migrations.",
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
            image_thumbnail_url = _resolve_image_url(first.get("image_thumbnail_url"))
        seller_profile_picture = _resolve_image_url(p.get("seller__profile_picture"))
        prod: Dict[str, Any] = {
            "id": pid,
            "title": p.get("title"),
            "price": float(p["price"]) if p.get("price") is not None else None,
            "currency": (str(p.get("currency") or "CNY")).strip().upper(),
            "status": p.get("status"),
            "created_at": p.get("created_at"),
            "category_id": p.get("category_id"),
            "condition_level_id": p.get("condition_level_id"),
            "is_promoted": int(p.get("is_promoted") or 0),
            "seller": {
                "id": p.get("seller__id"),
                "username": p.get("seller__username"),
                "profile_picture": seller_profile_picture,
            },
            "dormitory": {
                "latitude": p.get("dormitory__latitude"),
                "longitude": p.get("dormitory__longitude"),
            },
            "condition_level": {
                "id": p.get("condition_level__id"),
                "name": p.get("condition_level__name"),
                "level": p.get("condition_level__level"),
            },
            "image_thumbnail_url": image_thumbnail_url,
            "tags": [
                {"id": t.get("id"), "name": t.get("name")} for t in tag_rows_by_product.get(pid, [])
            ],
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


@py_router.get("/py/api/user/recommendations/exchange-products")
def recommend_exchange_products(
    authorization: Optional[str] = Header(default=None),
    page: int = Query(default=1, ge=1),
    page_size: int = Query(default=10, ge=1, le=50),
    random_count: int = Query(default=3, ge=0, le=50),
    lookback_days: int = Query(default=30, ge=1, le=365),
    seed: Optional[int] = Query(default=None),
    exchange_type: Optional[str] = Query(default=None),
) -> dict:
    if not authorization:
        raise HTTPException(status_code=401, detail="Missing Authorization header")

    if random_count > page_size:
        random_count = page_size

    exchange_type_value = None
    if exchange_type is not None:
        exchange_type_value = str(exchange_type).strip()
        if exchange_type_value == "":
            exchange_type_value = None
    if exchange_type_value is not None and exchange_type_value not in {"exchange_only", "exchange_or_purchase"}:
        raise HTTPException(status_code=422, detail="Invalid exchange_type")

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
                        detail=f"Database '{os.environ.get('DB_DATABASE', 'XiaoWu')}' missing table: {table}. Check DB_* env or run Laravel migrations.",
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

        last_exchange_product_id = 0
        last_exchange_product_at: Optional[str] = None
        try:
            last_exchange_row = conn.execute(
                text(
                    """
                    SELECT MAX(ep.id) AS last_exchange_product_id, MAX(ep.created_at) AS last_exchange_product_created_at
                    FROM exchange_products ep
                    JOIN products p ON p.id = ep.product_id
                    WHERE ep.exchange_status = 'open'
                      AND (ep.expiration_date IS NULL OR ep.expiration_date > NOW())
                      AND p.status = 'available'
                      AND p.deleted_at IS NULL
                    """
                )
            ).mappings().first()
            if last_exchange_row is not None:
                try:
                    last_exchange_product_id = int(last_exchange_row.get("last_exchange_product_id") or 0)
                except Exception:
                    last_exchange_product_id = 0
                try:
                    ca = last_exchange_row.get("last_exchange_product_created_at")
                    if isinstance(ca, datetime):
                        last_exchange_product_at = ca.isoformat()
                    elif isinstance(ca, str):
                        last_exchange_product_at = ca
                except Exception:
                    last_exchange_product_at = None
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
                p.title, p.price, p.currency, p.status, p.created_at,
                ep.id AS exchange_product_id, ep.exchange_type, ep.exchange_status, ep.expiration_date,
                ep.target_product_category_id, ep.target_product_condition_id, ep.target_product_title,
                COALESCE(d_user.latitude, d_product.latitude) AS dormitory__latitude,
                COALESCE(d_user.longitude, d_product.longitude) AS dormitory__longitude,
                COALESCE(d_user.university_id, d_product.university_id) AS dormitory__university_id,
                cl.id AS condition_level__id, cl.name AS condition_level__name,
                cl.level AS condition_level__level,
                u.id AS seller__id, u.username AS seller__username, u.profile_picture AS seller__profile_picture,
                tcat.id AS target_category__id, tcat.name AS target_category__name,
                tcond.id AS target_condition__id, tcond.name AS target_condition__name,
                tcond.level AS target_condition__level,
                CASE WHEN pl.id IS NULL THEN 0 ELSE 1 END AS is_promoted
            FROM exchange_products ep
            JOIN products p ON p.id = ep.product_id
            JOIN users u ON u.id = p.seller_id
            LEFT JOIN dormitories d_user ON d_user.id = u.dormitory_id
            LEFT JOIN dormitories d_product ON d_product.id = p.dormitory_id
            LEFT JOIN condition_levels cl ON cl.id = p.condition_level_id
            LEFT JOIN categories tcat ON tcat.id = ep.target_product_category_id
            LEFT JOIN condition_levels tcond ON tcond.id = ep.target_product_condition_id
            LEFT JOIN promoted_listings pl ON pl.product_id = p.id AND pl.promoted_until > NOW()
            WHERE ep.exchange_status = 'open'
              AND (ep.expiration_date IS NULL OR ep.expiration_date > NOW())
              AND p.status = 'available' AND p.deleted_at IS NULL
        """

        params: Dict[str, Any] = {"current_user_id": user_id}
        where_parts: List[str] = ["p.seller_id <> :current_user_id"]

        if exchange_type_value is not None:
            where_parts.append("ep.exchange_type = :exchange_type")
            params["exchange_type"] = exchange_type_value

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
                or_parts.append("COALESCE(d_user.university_id, d_product.university_id) = :buyer_university_id")
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
                    detail=f"Database '{os.environ.get('DB_DATABASE', 'XiaoWu')}' missing table: {table}. Check DB_* env or run Laravel migrations.",
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
                        detail=f"Database '{os.environ.get('DB_DATABASE', 'XiaoWu')}' missing table: {table}. Check DB_* env or run Laravel migrations.",
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
                user_id * 1000003 + page * 9176 + last_event_id * 1013 + last_exchange_product_id * 7919
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

    payload_exchange_products = []
    for p in combined:
        pid = int(p["id"])
        imgs = images_by_product.get(pid, [])
        image_url = None
        image_thumbnail_url = None
        if imgs:
            first = imgs[0]
            image_url = _resolve_image_url(first.get("image_url"))
            image_thumbnail_url = _resolve_image_url(first.get("image_thumbnail_url"))
        product_images = [
            {
                "image_url": _resolve_image_url(img.get("image_url")),
                "image_thumbnail_url": _resolve_image_url(img.get("image_thumbnail_url")),
                "is_primary": bool(img.get("is_primary")),
            }
            for img in imgs
        ]
        seller_profile_picture = _resolve_image_url(p.get("seller__profile_picture"))

        expiration_date = p.get("expiration_date")
        if isinstance(expiration_date, datetime):
            expiration_date = expiration_date.isoformat()

        target_category_id = p.get("target_category__id")
        if target_category_id is not None:
            try:
                target_category_id = int(target_category_id)
            except Exception:
                pass
        target_condition_id = p.get("target_condition__id")
        if target_condition_id is not None:
            try:
                target_condition_id = int(target_condition_id)
            except Exception:
                pass

        exchange_product_id = p.get("exchange_product_id")
        if exchange_product_id is not None:
            try:
                exchange_product_id = int(exchange_product_id)
            except Exception:
                pass

        exchange_product = {
            "id": exchange_product_id,
            "exchange_type": p.get("exchange_type"),
            "exchange_status": p.get("exchange_status"),
            "expiration_date": expiration_date,
            "target_product_title": p.get("target_product_title"),
            "target_product_category": {
                "id": target_category_id,
                "name": p.get("target_category__name"),
            } if target_category_id is not None else None,
            "target_product_condition": {
                "id": target_condition_id,
                "name": p.get("target_condition__name"),
                "level": p.get("target_condition__level"),
            } if target_condition_id is not None else None,
        }

        prod: Dict[str, Any] = {
            "id": pid,
            "title": p.get("title"),
            "price": float(p["price"]) if p.get("price") is not None else None,
            "currency": (str(p.get("currency") or "CNY")).strip().upper(),
            "status": p.get("status"),
            "created_at": p.get("created_at"),
            "category_id": p.get("category_id"),
            "condition_level_id": p.get("condition_level_id"),
            "is_promoted": int(p.get("is_promoted") or 0),
            "seller": {
                "id": p.get("seller__id"),
                "username": p.get("seller__username"),
                "profile_picture": seller_profile_picture,
            },
            "dormitory": {
                "latitude": p.get("dormitory__latitude"),
                "longitude": p.get("dormitory__longitude"),
            },
            "condition_level": {
                "id": p.get("condition_level__id"),
                "name": p.get("condition_level__name"),
                "level": p.get("condition_level__level"),
            },
            "image_url": image_url,
            "image_thumbnail_url": image_thumbnail_url,
            "images": product_images,
            "tags": [
                {"id": t.get("id"), "name": t.get("name")} for t in tag_rows_by_product.get(pid, [])
            ],
        }
        payload_exchange_products.append({
            "exchange_product": exchange_product,
            "product": prod,
        })

    return {
        "message": "Recommended exchange products retrieved successfully",
        "page": page,
        "page_size": page_size,
        "random_count": random_count,
        "last_event_id": last_event_id,
        "last_event_at": last_event_at,
        "last_exchange_product_id": last_exchange_product_id,
        "last_exchange_product_at": last_exchange_product_at,
        "exchange_products": payload_exchange_products[:page_size],
    }


@py_router.get("/py/api/user/products/{product_id}/similar")
def similar_products(
    product_id: int,
    authorization: Optional[str] = Header(default=None),
    page: int = Query(default=1, ge=1),
    page_size: int = Query(default=10, ge=1, le=50),
) -> dict:
    if not authorization:
        raise HTTPException(status_code=401, detail="Missing Authorization header")

    me = _laravel_get_json(authorization, "/api/user/me")
    user = me.get("user") if isinstance(me, dict) else None
    if not isinstance(user, dict) or not user.get("id"):
        raise HTTPException(status_code=401, detail="Invalid user")
    role = user.get("role")
    if role is not None and str(role).lower() != "user":
        raise HTTPException(status_code=403, detail="Only users can access this endpoint")

    engine = _get_db_engine()

    try:
        conn = engine.connect()
    except Exception:
        raise HTTPException(status_code=503, detail="Database unavailable")

    with conn:
        try:
            base_product = conn.execute(
                text(
                    """
                    SELECT p.id, p.category_id, p.condition_level_id, p.dormitory_id, p.seller_id
                    FROM products p
                    WHERE p.id = :product_id AND p.deleted_at IS NULL
                    LIMIT 1
                    """
                ),
                {"product_id": product_id},
            ).mappings().first()
        except ProgrammingError as e:
            if getattr(e.orig, "args", None) and len(e.orig.args) >= 1 and int(e.orig.args[0]) == 1146:
                table = _missing_table_name_from_programming_error(e) or "unknown"
                raise HTTPException(
                    status_code=503,
                    detail=f"Database '{os.environ.get('DB_DATABASE', 'XiaoWu')}' missing table: {table}. Check DB_* env or run Laravel migrations.",
                )
            raise

        if not base_product:
            raise HTTPException(status_code=404, detail="Product not found")

        category_id = base_product.get("category_id")
        condition_level_id = base_product.get("condition_level_id")
        dormitory_id = base_product.get("dormitory_id")
        seller_id = base_product.get("seller_id")

        filters: List[str] = []
        params: Dict[str, Any] = {"product_id": product_id}

        if category_id is not None:
            filters.append("p.category_id = :category_id")
            params["category_id"] = category_id
        if condition_level_id is not None:
            filters.append("p.condition_level_id = :condition_level_id")
            params["condition_level_id"] = condition_level_id
        if dormitory_id is not None:
            filters.append("p.dormitory_id = :dormitory_id")
            params["dormitory_id"] = dormitory_id
        if seller_id is not None:
            filters.append("p.seller_id = :seller_id")
            params["seller_id"] = seller_id

        if not filters:
            filters.append("1 = 1")

        filter_sql = " OR ".join(filters)

        try:
            total_row = conn.execute(
                text(
                    f"""
                    SELECT COUNT(*) AS total
                    FROM products p
                    WHERE p.status = 'available' AND p.deleted_at IS NULL
                      AND p.id <> :product_id
                      AND ({filter_sql})
                    """
                ),
                params,
            ).mappings().first()
        except ProgrammingError as e:
            if getattr(e.orig, "args", None) and len(e.orig.args) >= 1 and int(e.orig.args[0]) == 1146:
                table = _missing_table_name_from_programming_error(e) or "unknown"
                raise HTTPException(
                    status_code=503,
                    detail=f"Database '{os.environ.get('DB_DATABASE', 'XiaoWu')}' missing table: {table}. Check DB_* env or run Laravel migrations.",
                )
            raise

        total = int(total_row.get("total") or 0) if total_row else 0
        total_pages = max(1, math.ceil(total / page_size)) if page_size > 0 else 1
        offset = (page - 1) * page_size

        try:
            rows = conn.execute(
                text(
                    f"""
                    SELECT
                        p.id, p.seller_id, p.dormitory_id, p.category_id, p.condition_level_id,
                        p.title, p.price, p.currency, p.status, p.created_at,
                        COALESCE(d_user.latitude, d_product.latitude) AS dormitory__latitude,
                        COALESCE(d_user.longitude, d_product.longitude) AS dormitory__longitude,
                        cl.id AS condition_level__id, cl.name AS condition_level__name,
                        cl.level AS condition_level__level,
                        CASE WHEN pl.id IS NULL THEN 0 ELSE 1 END AS is_promoted
                    FROM products p
                    JOIN users u ON u.id = p.seller_id
                    LEFT JOIN dormitories d_user ON d_user.id = u.dormitory_id
                    LEFT JOIN dormitories d_product ON d_product.id = p.dormitory_id
                    LEFT JOIN condition_levels cl ON cl.id = p.condition_level_id
                    LEFT JOIN promoted_listings pl ON pl.product_id = p.id AND pl.promoted_until > NOW()
                    WHERE p.status = 'available' AND p.deleted_at IS NULL
                      AND p.id <> :product_id
                      AND ({filter_sql})
                    ORDER BY p.created_at DESC, p.id DESC
                    LIMIT :limit OFFSET :offset
                    """
                ),
                {**params, "limit": page_size, "offset": offset},
            ).mappings().all()
        except ProgrammingError as e:
            if getattr(e.orig, "args", None) and len(e.orig.args) >= 1 and int(e.orig.args[0]) == 1146:
                table = _missing_table_name_from_programming_error(e) or "unknown"
                raise HTTPException(
                    status_code=503,
                    detail=f"Database '{os.environ.get('DB_DATABASE', 'XiaoWu')}' missing table: {table}. Check DB_* env or run Laravel migrations.",
                )
            raise

        product_ids = [int(r["id"]) for r in rows]
        images = []
        tags = []
        if product_ids:
            placeholders = ", ".join([f":pid_{i}" for i in range(len(product_ids))])
            pid_params = {f"pid_{i}": v for i, v in enumerate(product_ids)}

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
    for p in rows:
        pid = int(p["id"])
        imgs = images_by_product.get(pid, [])
        image_thumbnail_url = None
        if imgs:
            first = imgs[0]
            image_thumbnail_url = first.get("image_thumbnail_url")
        prod: Dict[str, Any] = {
            "id": pid,
            "title": p.get("title"),
            "price": float(p["price"]) if p.get("price") is not None else None,
            "currency": (str(p.get("currency") or "CNY")).strip().upper(),
            "status": p.get("status"),
            "created_at": p.get("created_at"),
            "category_id": p.get("category_id"),
            "condition_level_id": p.get("condition_level_id"),
            "is_promoted": int(p.get("is_promoted") or 0),
            "dormitory": {
                "latitude": p.get("dormitory__latitude"),
                "longitude": p.get("dormitory__longitude"),
            },
            "condition_level": {
                "id": p.get("condition_level__id"),
                "name": p.get("condition_level__name"),
                "level": p.get("condition_level__level"),
            },
            "image_thumbnail_url": image_thumbnail_url,
            "tags": [
                {"id": t.get("id"), "name": t.get("name")} for t in tag_rows_by_product.get(pid, [])
            ],
        }
        payload_products.append(prod)

    return {
        "message": "Similar products retrieved successfully",
        "product_id": product_id,
        "page": page,
        "page_size": page_size,
        "total": total,
        "total_pages": total_pages,
        "products": payload_products,
    }
