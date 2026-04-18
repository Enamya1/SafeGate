import os
from pathlib import Path

from fastapi import FastAPI

def _load_env_file(env_path: Path) -> None:
    if not env_path.exists():
        return
    for raw_line in env_path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip().strip('"').strip("'")
        if key:
            os.environ.setdefault(key, value)

# function dayl l7wa ( run the loc.env)
def _load_local_env() -> None:
    python_service_root = Path(__file__).resolve().parents[1]
    project_root = python_service_root.parent
    _load_env_file(python_service_root / ".env")
    _load_env_file(project_root / "xiaowu" / ".env")


_load_local_env()

from app.api.router import py_router, router as api_router
from app.api.router import _get_db_engine
from app.recommendation_engine import start_trending_batch_updater

app = FastAPI(title="XiaoWu Python Service")


@app.on_event("startup")
def _startup_trending_updater() -> None:
    start_trending_batch_updater(_get_db_engine())


@app.get("/health")
def health() -> dict:
    return {"status": "ok"}


app.include_router(api_router, prefix="/api")
app.include_router(py_router)
