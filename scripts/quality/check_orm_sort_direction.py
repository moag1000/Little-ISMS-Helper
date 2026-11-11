#!/usr/bin/env python3
"""Gate 81: Doctrine ORM sort directions are SortDirection cases, not strings.

doctrine/orm 3.7 deprecates "ASC"/"DESC" strings (issue 11313) in
QueryBuilder::orderBy()/addOrderBy() and in #[ORM\\OrderBy]; every call logs a
deprecation at runtime or during cache warmup. Use \\SortDirection::Ascending /
\\SortDirection::Descending (polyfilled by symfony/polyfill-php86 below PHP 8.6).

T4: every place a string direction can sit in a call is covered, not only a literal last argument: a direction kept in a
variable (`$dir = 'DESC'; ->orderBy('a.x', $dir)`), written inside the sort string (`orderBy("a.x DESC")`), a named
argument (`orderBy(sort: 'a.x', order: 'ASC')`) and a ternary (`$f ? 'ASC' : 'DESC'`).

Only literal string directions are flagged. The DBAL QueryBuilder still takes
strings; mark such a line with `// @dbal-order` to exempt it. No baseline.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from php_source import matching_end, split_args, strip_comments  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]
# Matched on the whole file (calls and attributes may span lines). A literal string or Criteria::ASC/DESC
# (which is the same string) as the direction.
DIRECTION = r"(?:['\"](?:ASC|DESC)['\"]|(?:\\?Doctrine\\Common\\Collections\\)?Criteria::(?:ASC|DESC)\b)"
CALL = re.compile(r"(?:->|::)(?:orderBy|addOrderBy)\([^;]*?,\s*" + DIRECTION + r"\s*\)", re.IGNORECASE | re.DOTALL)
ATTRIBUTE = re.compile(r"#\[(?:\\?Doctrine\\ORM\\Mapping\\|ORM\\)?OrderBy\(\s*\[[^\]]*=>\s*" + DIRECTION, re.IGNORECASE | re.DOTALL)

CALL_OPEN = re.compile(r"(?:->|::)\s*(?:orderBy|addOrderBy)\s*\(", re.I)
LITERAL_DIR = re.compile(r"""(?<![\w$])['"](?:ASC|DESC)['"](?![\w$])""", re.I)
# "a.x DESC" / "a.x ASC, a.y DESC": the direction inside the sort string.
SORT_STRING_DIR = re.compile(r"""^\s*['"][^'"]*\s(?:ASC|DESC)\b[^'"]*['"]\s*$""", re.I)
NAMED_DIR = re.compile(r"^\s*(?:order|direction|sort|dir)\s*:\s*(.*)$", re.I | re.S)
DIR_VAR_ASSIGN = re.compile(r"(\$\w+)\s*=(?![=>])([^;]*);")


def call_findings(text: str) -> list[tuple[int, int]]:
    """(first, last) offsets of orderBy()/addOrderBy() calls that carry a string direction in any form."""
    code = strip_comments(text)
    dir_vars = {m.group(1) for m in DIR_VAR_ASSIGN.finditer(code) if LITERAL_DIR.search(m.group(2))}
    out = []
    for m in CALL_OPEN.finditer(code):
        open_pos = m.end() - 1
        end = matching_end(code, open_pos)
        args = split_args(code[open_pos + 1:end - 1])
        if not args:
            continue
        positional = [a for a in args if not re.match(r"^\s*\w+\s*:(?!:)", a)]
        named = [a for a in args if re.match(r"^\s*\w+\s*:(?!:)", a)]
        bad = False
        if positional and positional[0] is args[0] and SORT_STRING_DIR.match(args[0]):
            bad = True
        for a in positional[1:]:
            if LITERAL_DIR.search(a) or re.sub(r"\s", "", a) in dir_vars or re.search(DIRECTION, a, re.I):
                bad = True
        for a in named:
            n = NAMED_DIR.match(a)
            if n and (LITERAL_DIR.search(n.group(1)) or n.group(1).strip() in dir_vars or re.search(DIRECTION, n.group(1), re.I)):
                bad = True
            n = re.match(r"^\s*\w+\s*:\s*(.*)$", a, re.S)
            if n and SORT_STRING_DIR.match(n.group(1)):
                bad = True
        if bad:
            out.append((m.start(), end))
    return out


def main() -> int:
    quiet = "--quiet" in sys.argv
    findings: list[str] = []
    for path in sorted((ROOT / "src").rglob("*.php")):
        text = path.read_text(encoding="utf-8", errors="ignore")
        lines = text.splitlines()
        spans = [(m.start(), m.end()) for pattern in (CALL, ATTRIBUTE) for m in pattern.finditer(text)]
        spans += call_findings(text)
        reported: set[int] = set()
        for start, stop in spans:
            first = text.count("\n", 0, start) + 1
            last = text.count("\n", 0, stop) + 1
            if first in reported or any("@dbal-order" in lines[i - 1] for i in range(first, last + 1)):
                continue
            reported.add(first)
            findings.append(f"{path.relative_to(ROOT)}:{first}: {lines[first - 1].strip()}")

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
