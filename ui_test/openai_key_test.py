import json
import os
import sys
import urllib.request
import urllib.error

QWEN_API_KEY = os.environ.get("QWEN_API_KEY", "sk-ceb4ec6bf8ca48d4a0ab2955c04eaaf5")
MODEL = "qwen-turbo"


def call_qwen(message: str) -> dict:
    url = "https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions"
    payload = {
        "model": MODEL,
        "messages": [{"role": "user", "content": message}],
    }
    data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(
        url,
        data=data,
        headers={
            "Authorization": f"Bearer {QWEN_API_KEY}",
            "Content-Type": "application/json",
        },
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=30) as resp:
        return json.loads(resp.read().decode("utf-8"))


def extract_text(response: dict) -> str:
    choices = response.get("choices", [])
    if not choices:
        return ""
    message = choices[0].get("message", {})
    return message.get("content", "") or ""


def main() -> int:
    if not QWEN_API_KEY:
        print("Set the QWEN_API_KEY environment variable before running.")
        return 2
    try:
        response = call_qwen("Hello! Please reply with a short confirmation.")
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="ignore")
        print(f"HTTPError {exc.code}: {body}")
        return 1
    except Exception as exc:
        print(f"Request failed: {exc}")
        return 1
    text = extract_text(response)
    if text:
        print(text)
        return 0
    print("No text output received. Raw response:")
    print(json.dumps(response, indent=2))
    return 1


if __name__ == "__main__":
    sys.exit(main())
