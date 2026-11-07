#!/usr/bin/env python3
"""Gate 81: Doctrine ORM sort directions are SortDirection cases, not strings.

doctrine/orm 3.7 deprecates "ASC"/"DESC" strings (issue 11313) in
QueryBuilder::orderBy()/addOrderBy() and in #[ORM\\OrderBy]; every call logs a
deprecation at runtime or during cache warmup. Use \\SortDirection::Ascending /
\\SortDirection::Descending (polyfilled by symfony/polyfill-php86 below PHP 8.6).

Only literal string directions are flagged. The DBAL QueryBuilder still takes
strings; mark such a line with `// @dbal-order` to exempt it. No baseline.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CALL = re.compile(r"(?:->|::)(?:orderBy|addOrderBy)\([^;]*?,\s*['\"](?:ASC|DESC)['\"]\s*\)", re.IGNORECASE)
ATTRIBUTE = re.compile(r"#\[ORM\\OrderBy\(\[[^\]]*=>\s*['\"](?:ASC|DESC)['\"]", re.IGNORECASE)


def main() -> int:
    quiet = "--quiet" in sys.argv
    findings: list[str] = []
    for path in sorted((ROOT / "src").rglob("*.php")):
        for no, line in enumerate(path.read_text(encoding="utf-8", errors="ignore").splitlines(), 1):
            if "@dbal-order" in line:
                continue
            if CALL.search(line) or ATTRIBUTE.search(line):
                findings.append(f"{path.relative_to(ROOT)}:{no}: {line.strip()}")

    if findings:
        for finding in findings:
            print(f"FAIL {finding}")
        print(
            f"\ncheck_orm_sort_direction: {len(findings)} string sort direction(s). "
            "Use \\SortDirection::Ascending / \\SortDirection::Descending (doctrine/orm issue 11313)."
        )
        return 1

    if not quiet:
        print("check_orm_sort_direction: OK")
    return 0


if __name__ == "__main__":
    sys.exit(main())
