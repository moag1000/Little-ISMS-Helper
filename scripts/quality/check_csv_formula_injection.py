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
Since T2 a getter whose name only sounds numeric (`…Number`, `…Id`, `…Level`, …) passes only when
no declaration in src/ returns a string for it: `getAuthorityReferenceNumber()`,
`getEngagementNumber()`, `getCertNumber()` are free text. Hand-built CSV lines count as write
sites too: `fwrite($h, implode(';', $cells))` (or a variable filled with such an implode) checks
every cell like fputcsv() does.
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

# `->setValue()` is also Doctrine/PropertyAccessor vocabulary: only on a cell (`getCell(...)->setValue(`, `$cell->setValue(`).
CALL = re.compile(
    r"\bfputcsv\(|->setCellValue(?:Explicit)?(?:ByColumnAndRow)?\(|->fromArray\("
    r"|->insertOne\(|->insertAll\(|(?:getCell\([^()]*\)|\$cell\w*)->setValue(?:Explicit)?\("
)
FIRST_ARG = ("fromArray", "insertOne", "insertAll", "setValue")
SANITIZED = re.compile(r"CsvSanitizer|\bcsvCell\b|\bsanitize\w*\(|streamCsvExport\(")
EXEMPT = "@csv-sanitize-exempt:"
# Getters that cannot return free text.
SAFE_CALL = re.compile(
    r"^(getId|format|count|trans|round|is[A-Z]\w*|has[A-Z]\w*|getHas[A-Z]\w*|getIs[A-Z]\w*|"
    r"get\w*(Count|Score|Level|Id|Probability|Impact|Percentage|Percent|Number|Total|Days|Hours|Minutes|Rate|Amount|At|Date))$"
)
STRING = re.compile(r"'(?:\\.|[^'\\])*'|\"(?:\\.|[^\"\\])*\"")
TERMINAL_SAFE = re.compile(r"->\s*\w+\(\s*\)\s*(\?->|->)\s*(count|format)\(")
BARE_VARIABLE = re.compile(r"\$\w+(\[[^\]]*\])*")
FWRITE = re.compile(r"\bfwrite\(")
CSV_IMPLODE = re.compile(r"""\bimplode\(\s*(?:'(?:,|;|\\t|\|)'|"(?:,|;|\\t|\|)"|\$\w*(?:sep|delim)\w*)\s*,""", re.I)
DECLARED = re.compile(r"function\s+(get\w+)\s*\([^)]*\)\s*:\s*([?\\\w|]+)")
_TEXT_GETTERS: set[str] | None = None


def text_getters() -> set[str]:
    """Getters some declaration in src/ types as (nullable) string."""
    global _TEXT_GETTERS
    if _TEXT_GETTERS is None:
        _TEXT_GETTERS = set()
        for path in SRC.rglob("*.php"):
            for name, rtype in DECLARED.findall(path.read_text(encoding="utf-8", errors="replace")):
                if "string" in rtype.lower().split("|") or rtype.lower().lstrip("?") == "string":
                    _TEXT_GETTERS.add(name)
    return _TEXT_GETTERS


def safe_call(name: str) -> bool:
    if not SAFE_CALL.match(name):
        return False
    return name in ("getId", "format", "count", "trans", "round") or name not in text_getters()


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


def split_array(code: str) -> list[str] | None:
    """Elements of an array literal (`[a, b]` / `array(a, b)`), else None."""
    m = re.match(r"^(?:\[|array\()", code)
    return arguments(code, m.end()) if m else None


def split_concat(code: str) -> list[str]:
    """Top-level operands of a `.` concatenation (strings and brackets respected)."""
    parts, depth, quote, start = [], 0, None, 0
    for i, ch in enumerate(code):
        if quote:
            if ch == quote and code[i - 1] != "\\":
                quote = None
        elif ch in "'\"":
            quote = ch
        elif ch in "([{":
            depth += 1
        elif ch in ")]}":
            depth -= 1
        elif ch == "." and depth == 0 and code[i - 1:i + 2] != "..." and not code[i - 1:i].isdigit() \
                and not code[i + 1:i + 2].isdigit():
            parts.append(code[start:i])
            start = i + 1
    parts.append(code[start:])
    return parts if len(parts) > 1 else [code]


def value_problem(code: str, function: str, top: bool) -> str | None:
    """Why a cell value / array of cell values reaches the sheet unsanitised, else None."""
    code = code.strip()
    elements = split_array(code)
    if elements is not None:  # one sanitised element must not vouch for its siblings
        for element in elements:
            element = re.sub(r"^[^()\[\]]*?=>", "", STRING.sub("''", element)).strip() if "=>" in element else element
            problem = value_problem(element, function, False)
            if problem:
                return problem
        return None
    parts = split_concat(code)
    if len(parts) > 1:  # `sanitize($a) . $b->getTitle()`: every part counts, a sanitised sibling vouches for none
        for part in parts:
            problem = value_problem(part, function, False)
            if problem:
                return problem
        return None
    if SANITIZED.search(code):
        return None
    code = STRING.sub("''", code).strip()
    if re.match(r"^(?:\\)?json_encode\(", code):  # starts with { or [, never a formula
        return None
    # `->getEvidenceDocuments()->count()`, `->getReviewDate()?->format(…)`: the cell gets the number / date.
    code = TERMINAL_SAFE.sub(r"->\2(", code)
    for name in re.findall(r"->\s*(\w+)\s*\(", code):
        if not safe_call(name):
            return f"->{name}() reaches the cell unsanitised"
    if top and BARE_VARIABLE.fullmatch(code) and EXEMPT not in function:
        if not SANITIZED.search(function):
            return f"{code} is written in a function that never sanitises"
        # The function sanitises something, but maybe not this row: look at how the variable was filled.
        name = code[1:].split("[", 1)[0]
        names = {name}
        for loop in re.finditer(r"foreach\s*\(\s*\$(\w+)[^)]*?\bas\s+(?:\$\w+\s*=>\s*)?\$" + re.escape(name) + r"\b", function):
            names.add(loop.group(1))  # `foreach ($rows as $row)`: $row is filled where $rows is
        base = "(?:" + "|".join(re.escape(n) for n in names) + ")"
        for assign in re.finditer(r"\$" + base + r"(?:\[[^\]]*\])*\s*=(?![=>])\s*", function):
            rhs = arguments("(" + function[assign.end():] , 1)
            rhs_code = rhs[0] if rhs else ""
            rhs_code = rhs_code.split(";", 1)[0] if ";" in rhs_code else rhs_code
            problem = value_problem(rhs_code, function, False)
            if problem:
                return f"{code} is filled with unsanitised data ({problem})"
    return None


def fwrite_problem(text: str, match: re.Match[str]) -> str | None:
    """`fwrite($h, implode(';', $cells))`: a CSV line built by hand; every cell is checked."""
    args = arguments(text, match.end())
    if len(args) < 2:
        return None
    value = args[1].strip()
    function = enclosing_function(text, match.start())
    imp = CSV_IMPLODE.search(value)
    if imp is None:
        var = re.match(r"^\$(\w+)", value)
        if var is None:
            return None
        for assign in re.finditer(r"\$" + re.escape(var.group(1)) + r"\s*\.?=(?![=>])\s*([^;]*)", function):
            imp = CSV_IMPLODE.search(assign.group(1))
            if imp is not None:
                value = assign.group(1)
                break
        if imp is None:
            return None
    cells = arguments(value, imp.end())
    return value_problem(cells[0] if cells else "", function, True)


def site_problem(text: str, match: re.Match[str]) -> str | None:
    args = arguments(text, match.end())
    # `setCellValueExplicit($c, $v, DataType::TYPE_STRING)`: a string cell, never evaluated as a formula.
    if "Explicit" in match.group(0) and len(args) > 2 and re.search(r"TYPE_(STRING2?|INLINE)\b", args[2]):
        return None
    first = any(name in match.group(0) for name in FIRST_ARG) or text[max(match.start() - 2, 0):match.start()] == "->"
    value = args[0] if first else (args[1] if len(args) > 1 else "")
    return value_problem(value, enclosing_function(text, match.start()), True)


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
        for match in FWRITE.finditer(text):
            line = text.count("\n", 0, match.start()) + 1
            if EXEMPT in lines[line - 1] or (line > 1 and EXEMPT in lines[line - 2]):
                continue
            problem = fwrite_problem(text, match)
            if problem:
                sites += 1
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
