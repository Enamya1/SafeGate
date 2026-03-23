import argparse
import csv
import json
import math
import os
import re
import statistics
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from datetime import datetime
from typing import Any


@dataclass
class EndpointCase:
    method: str
    route_uri: str
    resolved_path: str
    key: str
    middleware: list[str]
    action: str
    name: str
    query: dict[str, Any]
    body: Any
    headers: dict[str, str]
    timeout_seconds: float


def load_json_file(path: str) -> dict[str, Any]:
    with open(path, "r", encoding="utf-8") as f:
        return json.load(f)


def resolve_token_value(raw: Any) -> str:
    if raw is None:
        return ""
    if isinstance(raw, str):
        value = raw.strip()
        if value.startswith("@file:"):
            file_path = value[len("@file:"):].strip()
            if file_path and os.path.exists(file_path):
                with open(file_path, "r", encoding="utf-8") as f:
                    return f.read().strip()
            return ""
        return value
    return str(raw).strip()


def ensure_dir(path: str) -> None:
    os.makedirs(path, exist_ok=True)


def run_route_list(laravel_project_path: str) -> list[dict[str, Any]]:
    cmd = ["php", "artisan", "route:list", "--json"]
    result = subprocess.run(
        cmd,
        cwd=laravel_project_path,
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
        check=True,
    )
    payload = json.loads(result.stdout)
    if isinstance(payload, list):
        return payload
    raise RuntimeError("Unexpected route:list --json payload")


def split_methods(method_field: str) -> list[str]:
    methods = []
    for raw in method_field.split("|"):
        m = raw.strip().upper()
        if m and m not in {"HEAD"}:
            methods.append(m)
    return methods


def normalize_route_path(route_uri: str) -> str:
    route_uri = route_uri.strip()
    if route_uri == "/":
        return "/"
    if route_uri.startswith("/"):
        return route_uri
    return "/" + route_uri


def apply_path_params(path: str, path_params: dict[str, Any]) -> tuple[str, list[str]]:
    missing = []

    def repl(match: re.Match[str]) -> str:
        key = match.group(1)
        if key in path_params:
            return str(path_params[key])
        missing.append(key)
        return "1"

    resolved = re.sub(r"\{([^}/]+)\}", repl, path)
    return resolved, missing


def percentile(values: list[float], p: float) -> float:
    if not values:
        return 0.0
    if len(values) == 1:
        return values[0]
    idx = (len(values) - 1) * p
    lo = math.floor(idx)
    hi = math.ceil(idx)
    if lo == hi:
        return values[lo]
    fraction = idx - lo
    return values[lo] + (values[hi] - values[lo]) * fraction


def to_request_data(method: str, body: Any) -> tuple[bytes | None, str | None]:
    if method in {"GET", "DELETE"}:
        return None, None
    if body is None:
        return None, None
    encoded = json.dumps(body, ensure_ascii=False).encode("utf-8")
    return encoded, "application/json"


def build_headers(base_headers: dict[str, str], extra_headers: dict[str, str], auth_token: str) -> dict[str, str]:
    headers = dict(base_headers)
    headers.update(extra_headers)
    if auth_token:
        headers["Authorization"] = f"Bearer {auth_token}"
    return headers


def endpoint_key(method: str, path: str) -> str:
    return f"{method.upper()} {path}"


def route_to_cases(routes: list[dict[str, Any]], cfg: dict[str, Any]) -> list[EndpointCase]:
    benchmark_cfg = cfg.get("benchmark", {})
    include_methods = set(m.upper() for m in benchmark_cfg.get("include_methods", ["GET", "POST", "PUT", "PATCH", "DELETE"]))
    skip_patterns = [re.compile(p) for p in benchmark_cfg.get("skip_patterns", [])]
    path_params = cfg.get("path_params", {})
    base_headers = cfg.get("headers", {"Accept": "application/json"})
    auth_token = resolve_token_value(cfg.get("auth", {}).get("bearer_token", ""))
    auth_by_path = cfg.get("auth", {}).get("auth_by_path", [])
    overrides = cfg.get("endpoint_overrides", {})
    timeout_seconds = float(benchmark_cfg.get("timeout_seconds", 15))
    cases: list[EndpointCase] = []

    for route in routes:
        method_field = str(route.get("method", ""))
        uri = str(route.get("uri", "")).strip()
        if not method_field or not uri:
            continue
        if uri.startswith("_ignition") or uri.startswith("sanctum/csrf-cookie"):
            continue
        route_path = normalize_route_path(uri)
        methods = split_methods(method_field)
        for method in methods:
            if method not in include_methods:
                continue
            key = endpoint_key(method, route_path)
            if any(p.search(key) for p in skip_patterns):
                continue
            override = overrides.get(key, {})
            if override.get("skip") is True:
                continue
            resolved_path, _ = apply_path_params(route_path, {**path_params, **override.get("path_params", {})})
            query = override.get("query", {})
            body = override.get("body")
            resolved_auth_token = auth_token
            for rule in auth_by_path:
                pattern = str(rule.get("path_regex", "")).strip()
                if not pattern:
                    continue
                if re.search(pattern, resolved_path):
                    resolved_auth_token = resolve_token_value(rule.get("bearer_token", ""))
                    break

            headers = build_headers(base_headers, override.get("headers", {}), resolved_auth_token)
            if override.get("auth_token") is not None:
                headers = build_headers(base_headers, override.get("headers", {}), resolve_token_value(override.get("auth_token")))
            case = EndpointCase(
                method=method,
                route_uri=route_path,
                resolved_path=resolved_path,
                key=key,
                middleware=list(route.get("middleware", []) if isinstance(route.get("middleware"), list) else []),
                action=str(route.get("action", "")),
                name=str(route.get("name", "")),
                query=query if isinstance(query, dict) else {},
                body=body,
                headers=headers,
                timeout_seconds=float(override.get("timeout_seconds", timeout_seconds)),
            )
            cases.append(case)
    return cases


def execute_case(base_url: str, case: EndpointCase) -> dict[str, Any]:
    url = base_url.rstrip("/") + case.resolved_path
    if case.query:
        url += "?" + urllib.parse.urlencode(case.query, doseq=True)
    payload, content_type = to_request_data(case.method, case.body)
    request = urllib.request.Request(url=url, data=payload, method=case.method)
    for k, v in case.headers.items():
        request.add_header(k, str(v))
    if content_type:
        request.add_header("Content-Type", content_type)

    started = time.perf_counter()
    status_code = 0
    ok = False
    body_size = 0
    error = ""
    try:
        with urllib.request.urlopen(request, timeout=case.timeout_seconds) as resp:
            status_code = int(resp.status)
            raw = resp.read()
            body_size = len(raw)
            ok = 200 <= status_code < 400
    except urllib.error.HTTPError as e:
        status_code = int(e.code)
        raw = e.read()
        body_size = len(raw)
        ok = False
        error = f"HTTPError:{e.code}"
    except Exception as e:
        status_code = 0
        body_size = 0
        ok = False
        error = f"{type(e).__name__}:{e}"
    elapsed_ms = (time.perf_counter() - started) * 1000
    return {
        "ok": ok,
        "status_code": status_code,
        "elapsed_ms": round(elapsed_ms, 3),
        "body_size_bytes": body_size,
        "error": error,
        "url": url,
    }


def summarize_runs(runs: list[dict[str, Any]]) -> dict[str, Any]:
    elapsed = sorted(float(r["elapsed_ms"]) for r in runs)
    status_hist: dict[str, int] = {}
    error_hist: dict[str, int] = {}
    for run in runs:
        s = str(run["status_code"])
        status_hist[s] = status_hist.get(s, 0) + 1
        if run["error"]:
            error_hist[run["error"]] = error_hist.get(run["error"], 0) + 1
    return {
        "runs": len(runs),
        "ok_runs": sum(1 for r in runs if r["ok"]),
        "avg_ms": round(statistics.mean(elapsed), 3) if elapsed else 0.0,
        "min_ms": round(min(elapsed), 3) if elapsed else 0.0,
        "max_ms": round(max(elapsed), 3) if elapsed else 0.0,
        "p50_ms": round(percentile(elapsed, 0.50), 3) if elapsed else 0.0,
        "p95_ms": round(percentile(elapsed, 0.95), 3) if elapsed else 0.0,
        "status_histogram": status_hist,
        "error_histogram": error_hist,
    }


def write_json(path: str, payload: Any) -> None:
    with open(path, "w", encoding="utf-8") as f:
        json.dump(payload, f, ensure_ascii=False, indent=2)


def write_csv(path: str, rows: list[dict[str, Any]]) -> None:
    if not rows:
        with open(path, "w", encoding="utf-8", newline="") as f:
            f.write("")
        return
    fieldnames = list(rows[0].keys())
    with open(path, "w", encoding="utf-8", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(rows)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--config", default="config.json")
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()

    cfg_path = os.path.abspath(args.config)
    if not os.path.exists(cfg_path):
        print(f"Config file not found: {cfg_path}")
        return 2

    cfg = load_json_file(cfg_path)
    laravel_cfg = cfg.get("laravel", {})
    base_url = str(cfg.get("base_url", laravel_cfg.get("base_url", ""))).strip()
    if not base_url:
        print("base_url is required in config")
        return 2
    project_path = str(laravel_cfg.get("project_path", "")).strip()
    if not project_path:
        print("laravel.project_path is required in config")
        return 2

    routes = run_route_list(project_path)
    cases = route_to_cases(routes, cfg)
    now_tag = datetime.now().strftime("%Y_%m_%d_%H_%M_%S")
    output_dir = os.path.abspath(str(cfg.get("output_dir", "reports")))
    ensure_dir(output_dir)

    discovered_path = os.path.join(output_dir, f"discovered_routes_{now_tag}.json")
    write_json(discovered_path, [{"method": c.method, "route_uri": c.route_uri, "resolved_path": c.resolved_path, "key": c.key, "name": c.name} for c in cases])

    if args.dry_run:
        print(f"Dry run completed. Discovered {len(cases)} benchmark cases.")
        print(f"Route snapshot: {discovered_path}")
        return 0

    benchmark_cfg = cfg.get("benchmark", {})
    warmup_runs = int(benchmark_cfg.get("warmup_runs", 1))
    measured_runs = int(benchmark_cfg.get("measured_runs", 3))

    endpoint_results = []
    run_rows = []

    for idx, case in enumerate(cases, start=1):
        print(f"[{idx}/{len(cases)}] {case.key} -> {case.resolved_path}")
        for _ in range(max(0, warmup_runs)):
            execute_case(base_url, case)
        runs = [execute_case(base_url, case) for _ in range(max(1, measured_runs))]
        summary = summarize_runs(runs)
        endpoint_result = {
            "key": case.key,
            "method": case.method,
            "route_uri": case.route_uri,
            "resolved_path": case.resolved_path,
            "name": case.name,
            "action": case.action,
            "summary": summary,
            "runs": runs,
        }
        endpoint_results.append(endpoint_result)
        for run_idx, run in enumerate(runs, start=1):
            run_rows.append({
                "key": case.key,
                "method": case.method,
                "route_uri": case.route_uri,
                "resolved_path": case.resolved_path,
                "run_index": run_idx,
                "status_code": run["status_code"],
                "ok": run["ok"],
                "elapsed_ms": run["elapsed_ms"],
                "body_size_bytes": run["body_size_bytes"],
                "error": run["error"],
                "url": run["url"],
            })

    endpoint_results_sorted = sorted(endpoint_results, key=lambda x: x["summary"]["avg_ms"], reverse=True)
    aggregate = {
        "generated_at": datetime.now().isoformat(),
        "base_url": base_url,
        "total_cases": len(endpoint_results_sorted),
        "cases_with_success": sum(1 for r in endpoint_results_sorted if r["summary"]["ok_runs"] > 0),
        "slowest_10_by_avg_ms": [
            {
                "key": r["key"],
                "avg_ms": r["summary"]["avg_ms"],
                "p95_ms": r["summary"]["p95_ms"],
                "status_histogram": r["summary"]["status_histogram"],
                "error_histogram": r["summary"]["error_histogram"],
            }
            for r in endpoint_results_sorted[:10]
        ],
        "results": endpoint_results_sorted,
    }

    json_path = os.path.join(output_dir, f"endpoint_benchmark_{now_tag}.json")
    csv_path = os.path.join(output_dir, f"endpoint_benchmark_runs_{now_tag}.csv")
    write_json(json_path, aggregate)
    write_csv(csv_path, run_rows)

    print(f"Benchmark completed. Cases: {len(endpoint_results_sorted)}")
    print(f"JSON report: {json_path}")
    print(f"CSV runs: {csv_path}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
