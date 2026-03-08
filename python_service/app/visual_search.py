from __future__ import annotations

import hashlib
import io
import os
import threading
from dataclasses import dataclass
from datetime import datetime
from typing import Any, Dict, List, Optional, Tuple
import urllib.request
from sqlalchemy import text
from sqlalchemy.exc import ProgrammingError

try:
    import cv2
except ModuleNotFoundError:
    cv2 = None

try:
    import faiss
except ModuleNotFoundError:
    faiss = None

try:
    import numpy as np
except ModuleNotFoundError:
    np = None

try:
    import open_clip
except ModuleNotFoundError:
    open_clip = None

try:
    import torch
except ModuleNotFoundError:
    torch = None

try:
    from PIL import Image
except ModuleNotFoundError:
    Image = None


def _safe_env_int(name: str, default: int) -> int:
    raw = os.environ.get(name)
    if raw is None or raw == "":
        return default
    try:
        return int(raw)
    except Exception:
        return default


def _safe_env_float(name: str, default: float) -> float:
    raw = os.environ.get(name)
    if raw is None or raw == "":
        return default
    try:
        return float(raw)
    except Exception:
        return default


def _resolve_image_url(image_url: str) -> str:
    if image_url.startswith("http://") or image_url.startswith("https://"):
        return image_url

    base_url = (os.environ.get("LARAVEL_BASE_URL") or "http://127.0.0.1:8000").strip().rstrip("/")
    if not image_url.startswith("/"):
        image_url = f"/{image_url}"
    return f"{base_url}{image_url}"


def _download_image_bytes(image_url: str, timeout_seconds: int) -> bytes:
    url = _resolve_image_url(image_url)
    req = urllib.request.Request(url, method="GET")
    with urllib.request.urlopen(req, timeout=timeout_seconds) as resp:
        return resp.read()


def _decode_rgb_image(image_bytes: bytes) -> Image.Image:
    if Image is None:
        raise RuntimeError("Missing dependency: Pillow")
    image = Image.open(io.BytesIO(image_bytes))
    return image.convert("RGB")


def _image_quality_ok(image_bytes: bytes) -> Tuple[bool, str]:
    min_width = _safe_env_int("VISUAL_SEARCH_MIN_WIDTH", 160)
    min_height = _safe_env_int("VISUAL_SEARCH_MIN_HEIGHT", 160)
    blur_threshold = _safe_env_float("VISUAL_SEARCH_MIN_BLUR_VARIANCE", 50.0)

    if cv2 is None or np is None:
        if Image is None:
            return False, "Missing dependencies: opencv-python and Pillow"
        try:
            with Image.open(io.BytesIO(image_bytes)) as img:
                w, h = img.size
        except Exception:
            return False, "Invalid image data"
        if w < min_width or h < min_height:
            return False, f"Image is too small. Minimum is {min_width}x{min_height}"
        return True, ""

    np_image = np.frombuffer(image_bytes, dtype=np.uint8)
    decoded = cv2.imdecode(np_image, cv2.IMREAD_COLOR)
    if decoded is None:
        return False, "Invalid image data"

    h, w = decoded.shape[:2]
    if w < min_width or h < min_height:
        return False, f"Image is too small. Minimum is {min_width}x{min_height}"

    gray = cv2.cvtColor(decoded, cv2.COLOR_BGR2GRAY)
    variance = cv2.Laplacian(gray, cv2.CV_64F).var()
    if variance < blur_threshold:
        return False, "Image is too blurry"

    return True, ""


def _vector_to_json(vector: np.ndarray) -> str:
    return np.array2string(vector, separator=",", max_line_width=1_000_000).replace("\n", "")


def _json_to_vector(raw: str) -> np.ndarray:
    stripped = raw.strip()
    if stripped.startswith("[") and stripped.endswith("]"):
        stripped = stripped[1:-1]
    arr = np.fromstring(stripped, sep=",", dtype=np.float32)
    return arr


@dataclass
class _FaissSnapshot:
    cache_key: str
    index: Any
    product_ids: List[int]
    image_ids: List[int]


class VisualSearchEngine:
    def __init__(self) -> None:
        self.model_name = (os.environ.get("VISUAL_SEARCH_CLIP_MODEL") or "ViT-B-32").strip()
        self.model_pretrained = (os.environ.get("VISUAL_SEARCH_CLIP_PRETRAINED") or "laion2b_s34b_b79k").strip()
        self.max_index_candidates = _safe_env_int("VISUAL_SEARCH_MAX_INDEX_CANDIDATES", 20_000)
        self.image_download_timeout_seconds = _safe_env_int("VISUAL_SEARCH_IMAGE_TIMEOUT_SECONDS", 12)
        self.query_top_k_max = _safe_env_int("VISUAL_SEARCH_TOP_K_MAX", 50)
        self.min_similarity_score = _safe_env_float("VISUAL_SEARCH_MIN_SCORE", 0.12)
        self._lock = threading.Lock()
        self._snapshot: Optional[_FaissSnapshot] = None
        self._model = None
        self._preprocess = None
        self._device = None

    @staticmethod
    def _ensure_dependencies_for_embedding() -> None:
        missing: List[str] = []
        if np is None:
            missing.append("numpy")
        if torch is None:
            missing.append("torch")
        if open_clip is None:
            missing.append("open-clip-torch")
        if Image is None:
            missing.append("Pillow")
        if missing:
            raise RuntimeError(f"Missing dependencies: {', '.join(missing)}")

    @staticmethod
    def _ensure_dependencies_for_search() -> None:
        VisualSearchEngine._ensure_dependencies_for_embedding()
        if faiss is None:
            raise RuntimeError("Missing dependency: faiss-cpu")

    def _get_device(self) -> str:
        self._ensure_dependencies_for_embedding()
        configured = (os.environ.get("VISUAL_SEARCH_DEVICE") or "").strip().lower()
        if configured in {"cpu", "cuda"}:
            if configured == "cuda" and not torch.cuda.is_available():
                return "cpu"
            return configured
        return "cuda" if torch.cuda.is_available() else "cpu"

    def _lazy_load_model(self) -> None:
        if self._model is not None and self._preprocess is not None and self._device is not None:
            return
        with self._lock:
            if self._model is not None and self._preprocess is not None and self._device is not None:
                return
            self._device = self._get_device()
            model, _, preprocess = open_clip.create_model_and_transforms(
                self.model_name,
                pretrained=self.model_pretrained,
                device=self._device,
            )
            model.eval()
            self._model = model
            self._preprocess = preprocess

    def compute_embedding(self, image_bytes: bytes) -> np.ndarray:
        self._ensure_dependencies_for_embedding()
        is_ok, reason = _image_quality_ok(image_bytes)
        if not is_ok:
            raise ValueError(reason)

        self._lazy_load_model()
        image = _decode_rgb_image(image_bytes)
        image_tensor = self._preprocess(image).unsqueeze(0).to(self._device)
        with torch.no_grad():
            image_features = self._model.encode_image(image_tensor)
            image_features = image_features / image_features.norm(dim=-1, keepdim=True)
        vector = image_features[0].detach().cpu().numpy().astype(np.float32)
        return vector

    def index_single_image(
        self,
        conn,
        product_id: int,
        product_image_id: int,
        image_url: str,
    ) -> Dict[str, Any]:
        image_bytes = _download_image_bytes(image_url, timeout_seconds=self.image_download_timeout_seconds)
        embedding = self.compute_embedding(image_bytes)
        fingerprint = hashlib.sha256(image_bytes).hexdigest()

        conn.execute(
            text(
                """
                INSERT INTO product_image_embeddings (
                    product_id,
                    product_image_id,
                    model_name,
                    embedding_dim,
                    embedding_vector,
                    image_fingerprint,
                    indexed_at,
                    created_at,
                    updated_at
                ) VALUES (
                    :product_id,
                    :product_image_id,
                    :model_name,
                    :embedding_dim,
                    :embedding_vector,
                    :image_fingerprint,
                    NOW(),
                    NOW(),
                    NOW()
                )
                ON DUPLICATE KEY UPDATE
                    product_id = VALUES(product_id),
                    model_name = VALUES(model_name),
                    embedding_dim = VALUES(embedding_dim),
                    embedding_vector = VALUES(embedding_vector),
                    image_fingerprint = VALUES(image_fingerprint),
                    indexed_at = NOW(),
                    updated_at = NOW()
                """
            ),
            {
                "product_id": int(product_id),
                "product_image_id": int(product_image_id),
                "model_name": self.model_name,
                "embedding_dim": int(embedding.shape[0]),
                "embedding_vector": _vector_to_json(embedding),
                "image_fingerprint": fingerprint,
            },
        )

        with self._lock:
            self._snapshot = None

        return {
            "product_id": int(product_id),
            "product_image_id": int(product_image_id),
            "embedding_dim": int(embedding.shape[0]),
            "model_name": self.model_name,
            "indexed_at": datetime.utcnow().isoformat(),
        }

    def _build_faiss_snapshot(self, conn) -> _FaissSnapshot:
        self._ensure_dependencies_for_search()
        rows = conn.execute(
            text(
                """
                SELECT
                    pie.product_id,
                    pie.product_image_id,
                    pie.embedding_vector,
                    pie.updated_at
                FROM product_image_embeddings pie
                JOIN products p ON p.id = pie.product_id
                WHERE p.status = 'available'
                  AND p.deleted_at IS NULL
                  AND pie.embedding_vector IS NOT NULL
                  AND pie.model_name = :model_name
                ORDER BY pie.updated_at DESC, pie.id DESC
                LIMIT :limit_rows
                """
            ),
            {
                "model_name": self.model_name,
                "limit_rows": int(self.max_index_candidates),
            },
        ).mappings().all()

        vectors: List[np.ndarray] = []
        product_ids: List[int] = []
        image_ids: List[int] = []
        latest_update = ""

        for row in rows:
            raw_vector = row.get("embedding_vector")
            if not isinstance(raw_vector, str) or raw_vector.strip() == "":
                continue
            vec = _json_to_vector(raw_vector)
            if vec.size == 0:
                continue
            norm = np.linalg.norm(vec)
            if norm == 0:
                continue
            vec = (vec / norm).astype(np.float32)
            vectors.append(vec)
            product_ids.append(int(row["product_id"]))
            image_ids.append(int(row["product_image_id"]))
            updated_at = row.get("updated_at")
            if isinstance(updated_at, datetime):
                updated_raw = updated_at.isoformat()
                if updated_raw > latest_update:
                    latest_update = updated_raw

        if len(vectors) == 0:
            empty_index = faiss.IndexFlatIP(1)
            return _FaissSnapshot(cache_key="empty", index=empty_index, product_ids=[], image_ids=[])

        dim = int(vectors[0].shape[0])
        matrix = np.stack(vectors).astype(np.float32)
        index = faiss.IndexFlatIP(dim)
        index.add(matrix)
        cache_key = f"{len(vectors)}:{latest_update}:{dim}"
        return _FaissSnapshot(cache_key=cache_key, index=index, product_ids=product_ids, image_ids=image_ids)

    def _get_snapshot(self, conn) -> _FaissSnapshot:
        with self._lock:
            snapshot = self._snapshot
        if snapshot is not None:
            return snapshot
        fresh = self._build_faiss_snapshot(conn)
        with self._lock:
            self._snapshot = fresh
        return fresh

    def search(self, conn, query_image_bytes: bytes, top_k: int) -> Dict[str, Any]:
        self._ensure_dependencies_for_search()
        if top_k < 1:
            top_k = 1
        if top_k > self.query_top_k_max:
            top_k = self.query_top_k_max

        snapshot = self._get_snapshot(conn)
        if len(snapshot.product_ids) == 0:
            return {
                "model_name": self.model_name,
                "embedding_dim": 0,
                "matches": [],
                "product_ids": [],
            }

        query_vector = self.compute_embedding(query_image_bytes)
        query = np.expand_dims(query_vector, axis=0).astype(np.float32)
        k = min(top_k * 4, len(snapshot.product_ids))
        scores, indices = snapshot.index.search(query, k)

        best_by_product: Dict[int, Dict[str, Any]] = {}
        for rank, idx in enumerate(indices[0].tolist()):
            if idx < 0:
                continue
            score = float(scores[0][rank])
            product_id = snapshot.product_ids[idx]
            image_id = snapshot.image_ids[idx]
            if score < self.min_similarity_score:
                continue
            existing = best_by_product.get(product_id)
            if existing is None or score > float(existing["score"]):
                best_by_product[product_id] = {
                    "product_id": int(product_id),
                    "product_image_id": int(image_id),
                    "score": score,
                }

        ranked = sorted(best_by_product.values(), key=lambda item: item["score"], reverse=True)[:top_k]
        return {
            "model_name": self.model_name,
            "embedding_dim": int(query_vector.shape[0]),
            "matches": ranked,
            "product_ids": [int(item["product_id"]) for item in ranked],
        }

    @staticmethod
    def missing_table_name_from_programming_error(e: ProgrammingError) -> Optional[str]:
        if not getattr(e, "orig", None) or not getattr(e.orig, "args", None) or len(e.orig.args) < 2:
            return None
        msg = str(e.orig.args[1])
        marker = "Table '"
        idx = msg.find(marker)
        if idx == -1:
            return None
        rest = msg[idx + len(marker):]
        end = rest.find("'")
        if end == -1:
            return None
        full = rest[:end]
        if "." in full:
            return full.split(".", 1)[1]
        return full
