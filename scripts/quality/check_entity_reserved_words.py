#!/usr/bin/env python3
"""
check_entity_reserved_words.py — Forbid MariaDB/MySQL reserved keywords as column names.

Doctrine quotes reserved keywords in the initial `CREATE TABLE` migration but
does NOT auto-quote them in subsequent DML (INSERT/UPDATE). The result:

    SQLSTATE[42000]: Syntax error or access violation: 1064 You have an
    error in your SQL syntax... near 'references, ...'

Production hit `ThreatIntelligence.references` on 2026-05-26; the earlier
`Vulnerability.references` was renamed to `vuln_references` in
Version20251127154814. This gate prevents the next recurrence.

Detection: any `#[ORM\\Column]` property whose effective column name
(camelCase → snake_case, OR explicit `name:` override) falls in the
reserved-word list. Fix: either rename the property OR override with
`name: '<safe_name>'` (the migration tracks the rename).
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ENTITY_DIR = ROOT / "src" / "Entity"

# Subset of MySQL/MariaDB reserved words that real entity column names tend to
# collide with. Source: MariaDB Reserved Words doc (https://mariadb.com/kb/en/reserved-words/).
# Trimmed to the common ones; expand as new collisions appear.
RESERVED = frozenset({
    "accessible", "add", "all", "alter", "analyze", "and", "as", "asc",
    "before", "between", "by", "call", "case", "cascade", "change",
    "check", "collate", "column", "condition", "constraint", "create",
    "cross", "current_date", "current_time", "current_timestamp",
    "default", "delete", "desc", "describe", "distinct", "drop", "each",
    "else", "elseif", "exists", "explain", "false", "fetch", "for",
    "force", "foreign", "from", "fulltext", "grant", "group", "having",
    "high_priority", "if", "ignore", "in", "index", "infile", "inner",
    "insert", "interval", "into", "is", "join", "key", "keys", "kill",
    "leave", "left", "like", "limit", "lines", "load", "localtime",
    "lock", "long", "loop", "low_priority", "match", "modifies",
    "natural", "not", "no_write_to_binlog", "null", "numeric", "on",
    "optimize", "option", "optionally", "or", "order", "out", "outer",
    "outfile", "primary", "procedure", "purge", "range", "read",
    "read_only", "read_write", "reads", "references", "rename", "repeat",
    "replace", "require", "restrict", "return", "returning", "rows",
    "schema", "schemas", "select", "set", "show", "spatial", "sql",
    "starting", "table", "tables", "terminated", "then", "to", "trailing",
    "true", "union", "unique", "unlock", "update", "usage", "use",
    "using", "values", "varying", "when", "where", "while", "window",
    "with", "write", "zerofill",
})

RE_COLUMN_PROP = re.compile(
    r"#\[ORM\\Column\((?P<attrs>[^)]*)\)\]"
    r"(?:\s*#\[[^\]]+\][^\n]*\n)*"
    r"\s*private\s+(?:\?(?:readonly\s+)?[\w\\]+)\s+\$(?P<name>\w+)",
    re.MULTILINE,
)
RE_NAME_OVERRIDE = re.compile(r"name\s*:\s*['\"](?P<col>[^'\"]+)['\"]")
OPT_OUT_MARKER = "@reserved-name-allowed"


def camel_to_snake(name: str) -> str:
    return re.sub(r"(?<!^)(?=[A-Z])", "_", name).lower()


def find_violations(path: Path) -> list[tuple[int, str, str]]:
    text = path.read_text(encoding="utf-8")
    head = "\n".join(text.splitlines()[:30])
    if OPT_OUT_MARKER in head:
        return []

    violations: list[tuple[int, str, str]] = []
    for m in RE_COLUMN_PROP.finditer(text):
        attrs = m.group("attrs")
        nm = RE_NAME_OVERRIDE.search(attrs)
        if nm:
            col = nm.group("col").strip("`")
        else:
            col = camel_to_snake(m.group("name"))
        if col.lower() in RESERVED:
            line_no = text[: m.start("name")].count("\n") + 1
            violations.append((line_no, m.group("name"), col))
    return violations


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--baseline", type=Path, default=None)
    parser.add_argument("--quiet", action="store_true")
    parser.add_argument("--write-baseline", type=Path, default=None)
    args = parser.parse_args()

    baseline: set[str] = set()
    if args.baseline and args.baseline.exists():
        for line in args.baseline.read_text().splitlines():
            line = line.strip()
            if line and not line.startswith("#"):
                baseline.add(line.split("  ", 1)[0])

    all_findings: list[str] = []
    for php in sorted(ENTITY_DIR.rglob("*.php")):
        rel = php.relative_to(ROOT).as_posix()
        for line_no, prop, col in find_violations(php):
            all_findings.append(f"{rel}:{line_no}  ${prop} → '{col}' (reserved)")

    if args.write_baseline:
        with args.write_baseline.open("w") as fh:
            fh.write("# check_entity_reserved_words.py baseline\n")
            fh.write("# Format: <relative-path>:<line>  <signature>\n")
            for line in all_findings:
                fh.write(line + "\n")
        print(f"check_entity_reserved_words: wrote {len(all_findings)} entries")
        return 0

    new_violations: list[str] = []
    baselined_count = 0
    for line in all_findings:
        prefix = line.split("  ", 1)[0]
        if prefix in baseline:
            baselined_count += 1
        else:
            new_violations.append(line)

    if new_violations:
        if not args.quiet:
            print("check_entity_reserved_words: VIOLATIONS\n")
        for v in new_violations:
            print(f"FAIL {v}")
        print(
            f"\ncheck_entity_reserved_words: {len(new_violations)} new violation(s) "
            f"({baselined_count} baselined, {len(all_findings)} total)."
        )
        print("Fix: rename property OR override with `#[ORM\\Column(name: '<safe_name>', ...)]`.")
        print("Pattern: vulnerabilities.references → vuln_references (see Version20251127154814).")
        return 1

    if not args.quiet:
        print(f"check_entity_reserved_words: OK ({len(all_findings)}, all baselined)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
