#!/usr/bin/env python3
"""
check_csv_formula_injection.py — Gate 84: spreadsheet cells go through CsvSanitizer.

A cell that starts with = + - @ (or a tab / CR) is executed as a formula when the
CSV / XLSX is opened in Excel or LibreOffice (CWE-1236). Record text — supplier
names, descriptions, titles — is user input. The sweep of 2026-11 found the DORA
register-of-information CSV and the FTE board CSV writing such cells raw, while
the other exporters used App\\Util\\CsvSanitizer::sanitize().

Since S6 (2026-11) the gate checks every WRITE SITE, not every file: a file that
sanitised one column passed even when the next column wrote record text raw
(the BfDI VVT export wrote transfer safeguards unsanitised that way). It covers
all of src/, src/Command/ included — operators open those CSVs in Excel too.

A site is one call of fputcsv(), PhpSpreadsheet ->setCellValue*() or ->fromArray().
Its cell value (2nd argument; 1st for fromArray) fails when it
  * calls a record getter (`->getTitle()`, `?->getName()`, …) without passing it
    through CsvSanitizer::sanitize() / a sanitize*() / csvCell() helper — getters
    that cannot carry text are allowed: ids, counts, scores, numbers, dates
    (`->format()`), booleans (`is*`/`has*`), translations (`->trans()`); or
  * is a bare variable (`$row`, `$value`, `$data[...]`) in a function that never
    sanitises.
Literals, constants and arithmetic pass. A site whose value is provably not record
text carries `// @csv-sanitize-exempt: <reason>` on the line or the line above; the
marker anywhere in a function covers its bare-variable sites (summary sheets of
counters), never a record getter.

    python3 scripts/quality/check_csv_formula_injection.py
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SRC = ROOT / "src"

CALL = re.compile(r"\bfputcsv\(|->setCellValue(?:Explicit)?(?:ByColumnAndRow)?\(|->fromArray\(")
SANITIZED = re.compile(r"CsvSanitizer|\bcsvCell\b|\bsanitize\w*\(|streamCsvExport\(")
EXEMPT = "@csv-sanitize-exempt:"
# Getters that cannot return free text.
SAFE_CALL = re.compile(
    r"^(getId|format|count|trans|round|is[A-Z]\w*|has[A-Z]\w*|getHas[A-Z]\w*|getIs[A-Z]\w*|"
    r"get\w*(Count|Score|Id|Probability|Impact|Percentage|Percent|Number|Total|Days|Hours|Minutes|Rate|Amount|At|Date))$"
)
STRING = re.compile(r"'(?:\\.|[^'\\])*'|\"(?:\\.|[^\"\\])*\"")
TERMINAL_SAFE = re.compile(r"->\s*\w+\(\s*\)\s*(\?->|->)\s*(count|format)\(")
BARE_VARIABLE = re.compile(r"\$\w+(\[[^\]]*\])*")


def arguments(text: str, start: int) -> list[str]:
    """Top-level arguments of the call whose `(` ends right before `start`."""
    depth, current, out, quote = 1, [], [], None
    i = start
    while i < len(text):
        ch = text[i]
        if quote:
            current.append(ch)
            if ch == "\\" and i + 1 < len(text):
                current.append(text[i + 1])
                i += 2
                continue
            if ch == quote:
                quote = None
        elif ch in "'\"":
            quote = ch
            current.append(ch)
        elif ch in "([{":
            depth += 1
            current.append(ch)
        elif ch in ")]}":
            depth -= 1
            if depth == 0:
                out.append("".join(current))
                return out
            current.append(ch)
        elif ch == "," and depth == 1:
            out.append("".join(current))
            current = []
        else:
            current.append(ch)
        i += 1
    return out


def enclosing_function(text: str, pos: int) -> str:
    start = text.rfind("function ", 0, pos)
    end = text.find("\n    }\n", pos)
    return text[max(start, 0):end if end > 0 else len(text)]


def site_problem(text: str, match: re.Match[str]) -> str | None:
    args = arguments(text, match.end())
    value = args[0] if "fromArray" in match.group(0) else (args[1] if len(args) > 1 else "")
    if SANITIZED.search(value):
        return None
    code = STRING.sub("''", value).strip()
    # `->getEvidenceDocuments()->count()`, `->getReviewDate()?->format(…)`: the cell gets the number / date.
    code = TERMINAL_SAFE.sub(r"->\2(", code)
    for name in re.findall(r"->\s*(\w+)\s*\(", code):
        if not SAFE_CALL.match(name):
            return f"->{name}() reaches the cell unsanitised"
    function = enclosing_function(text, match.start())
    if BARE_VARIABLE.fullmatch(code) and not SANITIZED.search(function) and EXEMPT not in function:
        return f"{code} is written in a function that never sanitises"
    return None


def main() -> int:
    failures: list[str] = []
    sites = 0
    for path in sorted(SRC.rglob("*.php")):
        text = path.read_text(encoding="utf-8", errors="replace")
        lines = text.split("\n")
        for match in CALL.finditer(text):
            sites += 1
            line = text.count("\n", 0, match.start()) + 1
            if EXEMPT in lines[line - 1] or (line > 1 and EXEMPT in lines[line - 2]):
                continue
            problem = site_problem(text, match)
            if problem:
                failures.append(f"{path.relative_to(ROOT)}:{line}: {problem}")

    if failures:
        for failure in failures:
            print("FAIL " + failure)
        print(
            f"\ncheck_csv_formula_injection: {len(failures)} of {sites} write site(s). Wrap record text with "
            "App\\Util\\CsvSanitizer::sanitize(), or mark the site "
            "`// @csv-sanitize-exempt: <reason>` when the cell carries no record text."
        )
        return 1
    print(f"check_csv_formula_injection: OK ({sites} write sites)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
