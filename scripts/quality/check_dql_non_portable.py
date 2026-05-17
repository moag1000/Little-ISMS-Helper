#!/usr/bin/env python3
"""
check_dql_non_portable.py — Gate 37.

Forbids non-portable date / time functions inside DQL strings:
`YEAR()`, `MONTH()`, `DAY()`, `DATE_FORMAT()`, `DATEDIFF()`,
`UNIX_TIMESTAMP()`, `NOW()`, `CURDATE()`.

These compile only against MySQL/MariaDB. Postgres, SQLite (CI test
DB) and SQL Server reject them, so DQL queries that use them break
test environments AND production migration to another driver. The
portable pattern is to pre-compute a `\\DateTimeImmutable` range in
PHP and bind it as a parameter.

Recurring fix:
  - 46e253fa fix(dora): YEAR DQL → date-range
  - 21719828 fix(dora): replace unsupported YEAR() DQL with date-range comparison

Conservative: only scans DQL strings inside Repository / Service
files, NOT raw SQL (executeStatement / Connection::query). Migrations
intentionally write driver-specific SQL.

Exit 0 = clean / baselined, Exit 1 = new violations.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SRC = ROOT / "src"

# Functions banned from DQL — uppercase + word-boundaried.
BANNED = re.compile(
    r"\b(YEAR|MONTH|DAY|DATE_FORMAT|DATEDIFF|UNIX_TIMESTAMP|NOW|CURDATE|HOUR|MINUTE|SECOND|"
    r"TIMEDIFF|FROM_UNIXTIME|STR_TO_DATE)\s*\("
)
# Heuristic: only flag inside a DQL string. DQL appears as the argument to
# createQuery(...) or in QueryBuilder->expr()->... — we scan PHP files
# under src/ (Repositories + Services) but skip migrations + raw SQL files.
SKIP_DIRS = {"Migrations", "migrations"}
RE_PHP_BLOCK_COMMENT = re.compile(r"/\*.*?\*/", re.DOTALL)
RE_PHP_LINE_COMMENT = re.compile(r"//[^\n]*")


def _strip_php_comments(text: str) -> str:
    text = RE_PHP_BLOCK_COMMENT.sub(lambda m: re.sub(r"[^\n]", " ", m.group(0)), text)
    return RE_PHP_LINE_COMMENT.sub(lambda m: " " * len(m.group(0)), text)


def scan() -> list[tuple[Path, int, str, str]]:
    findings: list[tuple[Path, int, str, str]] = []
    for php in SRC.rglob("*.php"):
        if any(seg in SKIP_DIRS for seg in php.parts):
            continue
        # Only check repository or service files where DQL lives.
        if not any(seg in {"Repository", "Service"} for seg in php.parts):
            continue
        text = _strip_php_comments(php.read_text(encoding="utf-8", errors="ignore"))
        # createQuery(...) literal-string arg OR ->select('foo, YEAR(...)') etc.
        # We just scan any string-literal that contains DQL-shaped tokens
        # (FROM/SELECT/UPDATE/DELETE) AND a banned function call.
        for sm in re.finditer(r"(?P<q>['\"])(?P<body>(?:\\.|(?!(?P=q)).)*?)(?P=q)", text, re.DOTALL):
            body = sm.group("body")
            if not body:
                continue
            if not re.search(r"\b(SELECT|UPDATE|DELETE|FROM)\b", body, re.IGNORECASE):
                continue
            fm = BANNED.search(body)
            if fm:
                ln = text.count("\n", 0, sm.start()) + 1
                findings.append((php, ln, fm.group(1), body[:60].replace("\n", " ")))
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
    keys = [f"{_rel(p)}:{ln}:{fn}" for p, ln, fn, _ in findings]

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_dql_non_portable.py baseline\n")
            for k in keys:
                fh.write(k + "\n")
        print(f"check_dql_non_portable: wrote {len(keys)} entries")
        return 0

    baseline = load_baseline(args.baseline)
    new = [(p, ln, fn, snip) for (p, ln, fn, snip), k in zip(findings, keys) if k not in baseline]
    total = len(findings)
    baselined = total - len(new)

    if not new:
        if not args.quiet:
            print(f"check_dql_non_portable: OK — {total} legacy, {baselined} baselined.")
        else:
            print(f"check_dql_non_portable: OK ({total}, all baselined)")
        return 0

    print("check_dql_non_portable: VIOLATIONS\n")
    for p, ln, fn, snip in new:
        print(f"FAIL {_rel(p)}:{ln}: non-portable DQL function `{fn}()` in: {snip}")
    print(f"\ncheck_dql_non_portable: {len(new)} new ({baselined} baselined, {total} total).")
    print("Fix: pre-compute the date range in PHP and bind it as a parameter:")
    print("    $start = new \\DateTimeImmutable(\"$year-01-01\");")
    print("    $end   = new \\DateTimeImmutable(\"$year-12-31 23:59:59\");")
    print("    ->andWhere('x.created BETWEEN :s AND :e')->setParameter('s', $start)->setParameter('e', $end)")
    return 1


if __name__ == "__main__":
    sys.exit(main())
