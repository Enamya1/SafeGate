import json
import os
import time
import urllib.error
import urllib.request
from typing import Any, Dict, List, Optional


class AIModelManager:
    def __init__(self) -> None:
        self.provider = os.environ.get("AI_PROVIDER", "openai").strip().lower()
        self.api_key = os.environ.get("AI_API_KEY", "")
        self.base_url = os.environ.get("AI_BASE_URL", "https://api.openai.com/v1").rstrip("/")
        self.model = os.environ.get("AI_MODEL", "gpt-4.1-mini")
        self.timeout = self._get_env_int("AI_TIMEOUT_SECONDS", 20)
        self.temperature = self._get_env_float("AI_TEMPERATURE", 0.2)

    def _get_env_int(self, name: str, default: int) -> int:
        raw = os.environ.get(name)
        if raw is None or raw == "":
            return default
        try:
            return int(raw)
        except Exception:
            return default

    def _get_env_float(self, name: str, default: float) -> float:
        raw = os.environ.get(name)
        if raw is None or raw == "":
            return default
        try:
            return float(raw)
        except Exception:
            return default

    def build_messages(
        self,
        system_prompt: Optional[str],
        user_prompt: str,
        history: Optional[List[Dict[str, Any]]] = None,
    ) -> List[Dict[str, str]]:
        messages: List[Dict[str, str]] = []
        if system_prompt:
            messages.append({"role": "system", "content": system_prompt})
        if history:
            for item in history:
                role = str(item.get("role") or "").strip().lower()
                content = str(item.get("content") or "")
                if role and content:
                    messages.append({"role": role, "content": content})
        messages.append({"role": "user", "content": user_prompt})
        return messages

    def generate(
        self,
        user_prompt: str,
        system_prompt: Optional[str] = None,
        history: Optional[List[Dict[str, Any]]] = None,
        extra: Optional[Dict[str, Any]] = None,
    ) -> Dict[str, Any]:
        if not self.api_key:
            raise RuntimeError("AI_API_KEY is not configured")

        payload: Dict[str, Any] = {
            "model": self.model,
            "messages": self.build_messages(system_prompt, user_prompt, history),
            "temperature": self.temperature,
        }
        if extra:
            payload.update(extra)

        start = time.time()
        response_text = self._request(payload)
        elapsed_ms = int((time.time() - start) * 1000)

        return {
            "provider": self.provider,
            "model": self.model,
            "content": response_text,
            "response_ms": elapsed_ms,
        }

    def _request(self, payload: Dict[str, Any]) -> str:
        if self.provider == "openai":
            return self._request_openai(payload)
        return self._request_openai(payload)

    def _request_openai(self, payload: Dict[str, Any]) -> str:
        url = f"{self.base_url}/chat/completions"
        body = json.dumps(payload).encode("utf-8")
        req = urllib.request.Request(
            url,
            data=body,
            headers={
                "Content-Type": "application/json",
                "Authorization": f"Bearer {self.api_key}",
            },
            method="POST",
        )
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                data = json.loads(resp.read().decode("utf-8"))
        except urllib.error.HTTPError as e:
            raw = e.read().decode("utf-8") if hasattr(e, "read") else ""
            raise RuntimeError(raw or f"AI request failed: {e.code}")
        except Exception:
            raise RuntimeError("AI request failed")

        choices = data.get("choices") if isinstance(data, dict) else None
        if not choices:
            return ""
        msg = choices[0].get("message") if isinstance(choices[0], dict) else None
        content = msg.get("content") if isinstance(msg, dict) else None
        return content or ""
