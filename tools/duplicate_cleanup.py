from __future__ import annotations

import argparse
import hashlib
import json
import os
import shutil
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Dict, Iterable, List, Sequence, Set, Tuple


@dataclass
class FileDuplicateGroup:
    hash_value: str
    size: int
    original: Path
    duplicates: List[Path]


@dataclass
class DirectoryDuplicateGroup:
    signature: str
    original: Path
    duplicates: List[Path]


def utc_now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def format_bytes(value: int) -> str:
    unit = 1024.0
    labels = ["B", "KB", "MB", "GB", "TB", "PB"]
    amount = float(value)
    for label in labels:
        if amount < unit or label == labels[-1]:
            if label == "B":
                return f"{int(amount)} {label}"
            return f"{amount:.2f} {label}"
        amount /= unit
    return f"{value} B"


def normalize(path: Path) -> Path:
    return path.resolve()


def is_under(path: Path, parent: Path) -> bool:
    try:
        path.relative_to(parent)
        return True
    except ValueError:
        return False


def stable_original(paths: Sequence[Path]) -> Path:
    return sorted(paths, key=lambda p: (p.stat().st_mtime, len(p.parts), str(p).lower()))[0]


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(prog="duplicate_cleanup", description="Detect and remove duplicate files and directories.")
    parser.add_argument("--root", type=Path, default=Path.cwd(), help="Project root path to scan.")
    parser.add_argument("--apply", action="store_true", help="Apply removals. Without this flag, the tool only reports.")
    parser.add_argument(
        "--report-dir",
        type=Path,
        default=None,
        help="Directory where reports and logs are written. Defaults to <root>/.dedupe_reports.",
    )
    parser.add_argument(
        "--exclude-name",
        action="append",
        default=[],
        help="Directory names to exclude from scanning. Can be repeated.",
    )
    parser.add_argument(
        "--exclude-path",
        action="append",
        default=[],
        help="Relative paths from root to exclude from scanning. Can be repeated.",
    )
    parser.add_argument(
        "--follow-symlinks",
        action="store_true",
        help="Follow symbolic links during scanning.",
    )
    return parser.parse_args()


def collect_file_candidates(
    root: Path,
    excluded_names: Set[str],
    excluded_paths: Set[Path],
    follow_symlinks: bool,
) -> List[Path]:
    files: List[Path] = []
    for current_root, dirnames, filenames in os.walk(root, topdown=True, followlinks=follow_symlinks):
        current_path = normalize(Path(current_root))
        keep_dirs: List[str] = []
        for dirname in dirnames:
            if dirname in excluded_names:
                continue
            full_dir = normalize(current_path / dirname)
            if any(is_under(full_dir, excluded) for excluded in excluded_paths):
                continue
            keep_dirs.append(dirname)
        dirnames[:] = keep_dirs

        if any(is_under(current_path, excluded) for excluded in excluded_paths):
            continue

        for filename in filenames:
            candidate = normalize(current_path / filename)
            if any(is_under(candidate, excluded) for excluded in excluded_paths):
                continue
            if not follow_symlinks and candidate.is_symlink():
                continue
            if candidate.is_file():
                files.append(candidate)
    return files


def file_sha256(path: Path, chunk_size: int = 1024 * 1024) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        while True:
            chunk = handle.read(chunk_size)
            if not chunk:
                break
            digest.update(chunk)
    return digest.hexdigest()


def build_file_duplicate_groups(files: Iterable[Path]) -> Tuple[List[FileDuplicateGroup], Dict[Path, str]]:
    by_size: Dict[int, List[Path]] = {}
    for file_path in files:
        size = file_path.stat().st_size
        by_size.setdefault(size, []).append(file_path)

    file_hashes: Dict[Path, str] = {}
    groups: List[FileDuplicateGroup] = []

    for size, size_group in by_size.items():
        if len(size_group) < 2:
            continue
        by_hash: Dict[str, List[Path]] = {}
        for file_path in size_group:
            hash_value = file_sha256(file_path)
            file_hashes[file_path] = hash_value
            by_hash.setdefault(hash_value, []).append(file_path)
        for hash_value, hash_group in by_hash.items():
            if len(hash_group) < 2:
                continue
            original = stable_original(hash_group)
            duplicates = [path for path in sorted(hash_group, key=lambda p: str(p).lower()) if path != original]
            groups.append(FileDuplicateGroup(hash_value=hash_value, size=size, original=original, duplicates=duplicates))

    groups.sort(key=lambda group: str(group.original).lower())
    return groups, file_hashes


def build_directory_signatures(
    root: Path,
    excluded_names: Set[str],
    excluded_paths: Set[Path],
    file_hashes: Dict[Path, str],
    follow_symlinks: bool,
) -> Dict[Path, str]:
    signatures: Dict[Path, str] = {}
    for current_root, dirnames, filenames in os.walk(root, topdown=False, followlinks=follow_symlinks):
        current_path = normalize(Path(current_root))
        if any(is_under(current_path, excluded) for excluded in excluded_paths):
            continue

        file_entries: List[Tuple[str, str]] = []
        for filename in sorted(filenames):
            file_path = normalize(current_path / filename)
            if any(is_under(file_path, excluded) for excluded in excluded_paths):
                continue
            hash_value = file_hashes.get(file_path)
            if hash_value is None and file_path.is_file():
                hash_value = file_sha256(file_path)
                file_hashes[file_path] = hash_value
            if hash_value is not None:
                file_entries.append((filename, hash_value))

        subdir_entries: List[Tuple[str, str]] = []
        for dirname in sorted(dirnames):
            if dirname in excluded_names:
                continue
            subdir = normalize(current_path / dirname)
            if any(is_under(subdir, excluded) for excluded in excluded_paths):
                continue
            sub_signature = signatures.get(subdir)
            if sub_signature is not None:
                subdir_entries.append((dirname, sub_signature))

        payload = {"files": file_entries, "dirs": subdir_entries}
        digest = hashlib.sha256(json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")).hexdigest()
        signatures[current_path] = digest

    return signatures


def build_directory_duplicate_groups(root: Path, signatures: Dict[Path, str]) -> List[DirectoryDuplicateGroup]:
    by_signature: Dict[str, List[Path]] = {}
    for directory, signature in signatures.items():
        if directory == root:
            continue
        by_signature.setdefault(signature, []).append(directory)

    groups: List[DirectoryDuplicateGroup] = []
    for signature, dirs in by_signature.items():
        if len(dirs) < 2:
            continue
        original = stable_original(dirs)
        duplicates = [path for path in sorted(dirs, key=lambda p: str(p).lower()) if path != original]
        groups.append(DirectoryDuplicateGroup(signature=signature, original=original, duplicates=duplicates))

    groups.sort(key=lambda group: str(group.original).lower())
    return groups


def collapse_directory_removals(candidates: Iterable[Path]) -> List[Path]:
    selected: List[Path] = []
    for candidate in sorted(set(candidates), key=lambda p: (len(p.parts), str(p).lower())):
        if any(is_under(candidate, parent) for parent in selected):
            continue
        selected.append(candidate)
    return selected


def tree_size_bytes(path: Path) -> int:
    if path.is_file():
        return path.stat().st_size
    total = 0
    for current_root, _, filenames in os.walk(path):
        current_path = Path(current_root)
        for filename in filenames:
            full_path = current_path / filename
            if full_path.is_file():
                total += full_path.stat().st_size
    return total


def write_json(path: Path, payload: dict) -> None:
    path.write_text(json.dumps(payload, indent=2, ensure_ascii=False), encoding="utf-8")


def write_jsonl(path: Path, rows: List[dict]) -> None:
    content = "\n".join(json.dumps(row, ensure_ascii=False) for row in rows)
    if content:
        content += "\n"
    path.write_text(content, encoding="utf-8")


def main() -> int:
    args = parse_args()

    root = normalize(args.root)
    report_dir = normalize(args.report_dir) if args.report_dir else normalize(root / ".dedupe_reports")
    report_dir.mkdir(parents=True, exist_ok=True)

    default_excluded_names = {
        ".git",
        ".venv",
        "venv",
        "__pycache__",
        "node_modules",
        "vendor",
        ".idea",
        ".vscode",
        ".dedupe_reports",
    }
    excluded_names = default_excluded_names.union(set(args.exclude_name))
    excluded_paths = {normalize(root / relative) for relative in args.exclude_path}
    excluded_paths.add(report_dir)

    files = collect_file_candidates(
        root=root,
        excluded_names=excluded_names,
        excluded_paths=excluded_paths,
        follow_symlinks=args.follow_symlinks,
    )

    file_groups, file_hashes = build_file_duplicate_groups(files)
    directory_signatures = build_directory_signatures(
        root=root,
        excluded_names=excluded_names,
        excluded_paths=excluded_paths,
        file_hashes=file_hashes,
        follow_symlinks=args.follow_symlinks,
    )
    directory_groups = build_directory_duplicate_groups(root=root, signatures=directory_signatures)

    duplicate_dirs = collapse_directory_removals(
        candidate for group in directory_groups for candidate in group.duplicates
    )
    duplicate_dir_set = set(duplicate_dirs)

    duplicate_files: List[Path] = []
    for group in file_groups:
        for duplicate_file in group.duplicates:
            if any(is_under(duplicate_file, duplicate_dir) for duplicate_dir in duplicate_dir_set):
                continue
            duplicate_files.append(duplicate_file)
    duplicate_files = sorted(set(duplicate_files), key=lambda p: str(p).lower())

    duplicate_file_bytes = sum(path.stat().st_size for path in duplicate_files if path.exists())
    duplicate_dir_bytes = sum(tree_size_bytes(path) for path in duplicate_dirs if path.exists())
    reclaimable_bytes = duplicate_file_bytes + duplicate_dir_bytes

    run_id = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    analysis_path = report_dir / f"duplicate-analysis-{run_id}.json"
    removal_log_path = report_dir / f"duplicate-removal-{run_id}.jsonl"
    summary_path = report_dir / f"duplicate-summary-{run_id}.json"

    analysis_payload = {
        "run_id": run_id,
        "generated_at": utc_now_iso(),
        "root": str(root),
        "mode": "apply" if args.apply else "dry_run",
        "excluded_names": sorted(excluded_names),
        "excluded_paths": [str(path) for path in sorted(excluded_paths, key=lambda p: str(p).lower())],
        "file_duplicate_groups": [
            {
                "hash": group.hash_value,
                "size_bytes": group.size,
                "original": str(group.original),
                "duplicates": [str(path) for path in group.duplicates],
            }
            for group in file_groups
        ],
        "directory_duplicate_groups": [
            {
                "signature": group.signature,
                "original": str(group.original),
                "duplicates": [str(path) for path in group.duplicates],
            }
            for group in directory_groups
        ],
        "planned_duplicate_files": [str(path) for path in duplicate_files],
        "planned_duplicate_directories": [str(path) for path in duplicate_dirs],
        "planned_reclaimable_bytes": reclaimable_bytes,
    }
    write_json(analysis_path, analysis_payload)

    removal_rows: List[dict] = []
    removed_file_count = 0
    removed_dir_count = 0
    reclaimed_bytes = 0

    if args.apply:
        for directory in sorted(duplicate_dirs, key=lambda p: (len(p.parts), str(p).lower()), reverse=True):
            size_bytes = tree_size_bytes(directory) if directory.exists() else 0
            row = {
                "timestamp": utc_now_iso(),
                "item_type": "directory",
                "path": str(directory),
                "size_bytes": size_bytes,
                "status": "removed",
                "error": None,
            }
            try:
                if directory.exists():
                    shutil.rmtree(directory)
                removed_dir_count += 1
                reclaimed_bytes += size_bytes
            except Exception as exc:
                row["status"] = "failed"
                row["error"] = f"{type(exc).__name__}: {exc}"
            removal_rows.append(row)

        for file_path in duplicate_files:
            size_bytes = file_path.stat().st_size if file_path.exists() else 0
            row = {
                "timestamp": utc_now_iso(),
                "item_type": "file",
                "path": str(file_path),
                "size_bytes": size_bytes,
                "status": "removed",
                "error": None,
            }
            try:
                if file_path.exists():
                    file_path.unlink()
                removed_file_count += 1
                reclaimed_bytes += size_bytes
            except Exception as exc:
                row["status"] = "failed"
                row["error"] = f"{type(exc).__name__}: {exc}"
            removal_rows.append(row)
    else:
        for directory in duplicate_dirs:
            removal_rows.append(
                {
                    "timestamp": utc_now_iso(),
                    "item_type": "directory",
                    "path": str(directory),
                    "size_bytes": tree_size_bytes(directory) if directory.exists() else 0,
                    "status": "planned",
                    "error": None,
                }
            )
        for file_path in duplicate_files:
            removal_rows.append(
                {
                    "timestamp": utc_now_iso(),
                    "item_type": "file",
                    "path": str(file_path),
                    "size_bytes": file_path.stat().st_size if file_path.exists() else 0,
                    "status": "planned",
                    "error": None,
                }
            )

    write_jsonl(removal_log_path, removal_rows)

    if not args.apply:
        removed_file_count = len(duplicate_files)
        removed_dir_count = len(duplicate_dirs)
        reclaimed_bytes = reclaimable_bytes

    summary_payload = {
        "run_id": run_id,
        "generated_at": utc_now_iso(),
        "root": str(root),
        "mode": "apply" if args.apply else "dry_run",
        "duplicate_file_groups": len(file_groups),
        "duplicate_directory_groups": len(directory_groups),
        "duplicate_files_eliminated": removed_file_count,
        "duplicate_directories_eliminated": removed_dir_count,
        "total_duplicates_eliminated": removed_file_count + removed_dir_count,
        "disk_space_reclaimed_bytes": reclaimed_bytes,
        "disk_space_reclaimed_human": format_bytes(reclaimed_bytes),
        "analysis_report_path": str(analysis_path),
        "removal_log_path": str(removal_log_path),
    }
    write_json(summary_path, summary_payload)

    print(json.dumps(summary_payload, indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
