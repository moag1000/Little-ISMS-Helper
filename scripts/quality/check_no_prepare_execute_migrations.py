#!/usr/bin/env python3
"""
check_no_prepare_execute_migrations.py — Gate 22.

Forbids the `SET @sql := IF(...) ; PREPARE stmt FROM @sql ; EXECUTE …`
dynamic-SQL pattern in Doctrine migrations. Per CLAUDE.md Common
Pitfalls §6:

    17 existing migrations (Phase 8, Versions 20260418*, 20260419*,
    20260420140000) use dynamic SQL with SET @sql := IF(...) ;
    PREPARE stmt FROM @sql ; EXECUTE stmt ; DEALLOCATE for "idempotent"
    ALTER/CREATE. This pattern silently fails in Doctrine Migrations:
    the migration is recorded as `executed` but the actual DDL never
    runs. Symptoms: `Column not found` errors, missing tables.

New migrations must use plain `ALTER TABLE` / `CREATE TABLE IF NOT
EXISTS` directly.

Detects: PREPARE / EXECUTE / DEALLOCATE / `SET @sql` patterns inside
`migrations/Version*.php`.

Exit 0 = clean / baselined, Exit 1 = new violations.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MIG_DIR = ROOT / "migrations"

RE_PREPARE = re.compile(r"\bPREPARE\s+\w+\s+FROM\s+@", re.IGNORECASE)
RE_EXECUTE_STMT = re.compile(r"\bEXECUTE\s+\w+\s*;", re.IGNORECASE)
RE_DEALLOCATE = re.compile(r"\bDEALLOCATE\s+PREPARE\b", re.IGNORECASE)
RE_SET_AT_SQL = re.compile(r"SET\s+@sql\s*:?=", re.IGNORECASE)


RE_PHP_BLOCK_COMMENT = re.compile(r"/\*.*?\*/", re.DOTALL)
RE_PHP_LINE_COMMENT = re.compile(r"//[^\n]*")


def _strip_php_comments(text: str) -> str:
    text = RE_PHP_BLOCK_COMMENT.sub(lambda m: re.sub(r"[^\n]", " ", m.group(0)), text)
    return RE_PHP_LINE_COMMENT.sub(lambda m: " " * len(m.group(0)), text)


def scan() -> list[tuple[Path, int, str]]:
    findings: list[tuple[Path, int, str]] = []
    if not MIG_DIR.is_dir():
        return findings
    for f in sorted(MIG_DIR.glob("Version*.php")):
        text = _strip_php_comments(f.read_text(encoding="utf-8", errors="ignore"))
        for idx, raw in enumerate(text.splitlines(), start=1):
            for pat, label in (
                (RE_PREPARE, "PREPARE … FROM @"),
                (RE_EXECUTE_STMT, "EXECUTE stmt"),
                (RE_DEALLOCATE, "DEALLOCATE PREPARE"),
                (RE_SET_AT_SQL, "SET @sql :="),
            ):
                if pat.search(raw):
                    findings.append((f, idx, label))
                    break
    return findings


def load_baseline(path: Path | None) -> set[str]:
    if path is None or not path.exists():
        return set()
    return {
        s.strip() for s in path.read_text(encoding="utf-8").splitlines()
        if s.strip() and not s.strip().startswith("#")
    }


def _rel(p: Path) -> Path:
    try:
        return p.relative_to(ROOT)
    except ValueError:
        return Path(p.name)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--baseline", type=Path, default=None)
    ap.add_argument("--write-baseline", type=Path, default=None)
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    findings = scan()
    keys = [f"{_rel(p)}:{ln}" for p, ln, _ in findings]

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_no_prepare_execute_migrations.py baseline\n")
            for k in keys:
                fh.write(k + "\n")
        print(f"check_no_prepare_execute_migrations: wrote {len(keys)} entries")
        return 0

    baseline = load_baseline(args.baseline)
    new = [(p, ln, lbl) for (p, ln, lbl), k in zip(findings, keys) if k not in baseline]
    total = len(findings)
    baselined = total - len(new)

    if not new:
        if not args.quiet:
            print(f"check_no_prepare_execute_migrations: OK — {total} legacy, {baselined} baselined.")
        else:
            print(f"check_no_prepare_execute_migrations: OK ({total}, all baselined)")
        return 0

    print("check_no_prepare_execute_migrations: VIOLATIONS\n")
    for p, ln, lbl in new:
        print(f"FAIL {_rel(p)}:{ln}: dynamic-SQL pattern '{lbl}'")
    print(f"\ncheck_no_prepare_execute_migrations: {len(new)} new ({baselined} baselined, {total} total).")
    print("Fix: replace dynamic SQL with direct DDL:")
    print("  ALTER TABLE x ADD COLUMN y INT NOT NULL DEFAULT 0;")
    print("  CREATE TABLE IF NOT EXISTS z (...);")
    return 1


if __name__ == "__main__":
    sys.exit(main())
