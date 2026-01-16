import json
import os
import urllib.error
import urllib.request
from typing import Optional

from fastapi import APIRouter, Header, HTTPException

router = APIRouter()


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

    base_url = os.environ.get("LARAVEL_BASE_URL", "http://127.0.0.1:8000").rstrip("/")
    url = f"{base_url}/api/user/me"

    req = urllib.request.Request(
        url,
        headers={
            "Accept": "application/json",
            "Authorization": authorization,
        },
        method="GET",
    )

    try:
        with urllib.request.urlopen(req, timeout=5) as resp:
            body = resp.read().decode("utf-8")
            data = json.loads(body) if body else {}
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8") if hasattr(e, "read") else ""
        try:
            data = json.loads(body) if body else {}
        except Exception:
            data = {}
        raise HTTPException(status_code=e.code, detail=data or {"message": "Laravel request failed"})
    except Exception:
        raise HTTPException(status_code=502, detail="Could not reach Laravel")

    user = data.get("user") if isinstance(data, dict) else None
    username = None

    if isinstance(user, dict):
        username = user.get("username") or user.get("full_name") or user.get("email")

    if not username:
        username = "user"

    return {"message": f"hi {username}"}
