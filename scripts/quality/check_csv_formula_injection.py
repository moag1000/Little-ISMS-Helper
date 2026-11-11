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
sys.path.insert(0, str(Path(__file__).resolve().parent))
from php_source import class_methods, matching_end, strip_comments  # noqa: E402

SRC = ROOT / "src"

# `->setValue()` is also Doctrine/PropertyAccessor vocabulary: only on a cell (`getCell(...)->setValue(`, `$cell->setValue(`).
CALL = re.compile(
    r"\bfputcsv\(|->setCellValue(?:Explicit)?(?:ByColumnAndRow)?\(|->fromArray\("
    r"|->insertOne\(|->insertAll\(|(?:getCell\w*\((?:[^()]|\([^()]*\))*\)|\$cell\w*)\s*->\s*setValue(?:Explicit)?\("
)
FIRST_ARG = ("fromArray", "insertOne", "insertAll", "setValue")
# Only the helpers that really neutralise a leading = + - @: App\\Util\\CsvSanitizer, EntityDataExporter::csvCell,
# the bulk-export trait and ExcelExportService's own formula guard. A `sanitizeHtml()` look-alike does not count.
SANITIZED = re.compile(r"\bCsvSanitizer\b|\bcsvCell\b|\bstreamCsvExport\(|\bsanitizeFormulaInjection\(")
EXEMPT = "@csv-sanitize-exempt:"
# A marker counts only with a reason after the colon (T4: a bare marker silenced a site without saying why).
EXEMPT_VALID = re.compile(r"@csv-sanitize-exempt:[ \t]*[^\s*/]")
# Sinks the call regex cannot see: `$cb = fputcsv(...)` (first-class callable) and `$f = 'fputcsv'` (variable function,
# `array_map('fputcsv', …)`). The cell values never meet a call site, so the site itself is the finding.
INDIRECT_SINK = re.compile(r"\bfputcsv\s*\(\s*\.\.\.\s*\)|['\"]\\?fputcsv['\"]")
# `$w = $sheet->getCell('A1'); $w->setValue(…)`: any ->setValue( in a file that works with PhpSpreadsheet.
SPREADSHEET_FILE = re.compile(r"PhpOffice\\PhpSpreadsheet")
SET_VALUE = re.compile(r"->\s*setValue(?:Explicit)?\(")
# Getters that cannot return free text.
SAFE_CALL = re.compile(
    r"^(getId|format|count|trans|round|is[A-Z]\w*|has[A-Z]\w*|getHas[A-Z]\w*|getIs[A-Z]\w*|"
    r"get\w*(Count|Score|Level|Id|Probability|Impact|Percentage|Percent|Number|Total|Days|Hours|Minutes|Rate|Amount|At|Date))$"
)
STRING = re.compile(r"'(?:\\.|[^'\\])*'|\"(?:\\.|[^\"\\])*\"")
TERMINAL_SAFE = re.compile(r"->\s*\w+\(\s*\)\s*(\?->|->)\s*(count|format)\(")
BARE_VARIABLE = re.compile(r"\$\w+(\[[^\]]*\])*")
# Where a hand-built CSV line leaves: fwrite()/fputs(), file_put_contents(), a response body, echo.
SERVICE_CALL = re.compile(r"\$this->\w+->\w+\((?:[^()]|\([^()]*\))*\)")
FWRITE = re.compile(r"\bfwrite\(|\bfputs\(|\bfile_put_contents\(|->setContent\(|\bnew\s+\\?(?:\w+\\)*Response\(|\becho\b")
IMPLODE = re.compile(r"\b(?:implode|join)\(")
CSV_HINT = re.compile(r"csv|\.xlsx|separator|delimiter", re.I)
# A string literal that only holds separators / quotes / line ends: `';'`, `",\\n"`, `'";"'`.
SEPARATOR_LITERAL = re.compile(r"""^(['"])[\s\\rnt"',;|]*[,;|\\t][\s\\rnt"',;|]*\1$""")
DECLARED = re.compile(r"function\s+(get\w+)\s*\([^)]*\)\s*(?::\s*([?\\\w|]+))?\s*[{;]")
PROPERTY = re.compile(r"(?<![\w$])\$(?!this\b)\w+(?:\??->\w+)*?\??->\s*(\w+)\b(?!\s*\(|\s*\??->)")
_TEXT_GETTERS: set[str] | None = None


def text_getters() -> set[str]:
    """Getters some declaration in src/ types as (nullable) string."""
    global _TEXT_GETTERS
    if _TEXT_GETTERS is None:
        _TEXT_GETTERS = set()
        for path in SRC.rglob("*.php"):
            for name, rtype in DECLARED.findall(strip_comments(path.read_text(encoding="utf-8", errors="replace"))):
                kinds = rtype.lower().lstrip("?").split("|")
                # untyped and `mixed` may return anything, so they count as text
                if not rtype or "string" in kinds or "mixed" in kinds:
                    _TEXT_GETTERS.add(name)
    return _TEXT_GETTERS


def safe_property(name: str) -> bool:
    """A public property read (`$row->total`) is as safe as the getter it stands for."""
    return name in ("id", "count") or safe_call("get" + name[:1].upper() + name[1:])


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
    """The named method around pos (closures stay inside it, so assignments before them are seen)."""
    best: tuple[int, int] | None = None
    for m in re.finditer(r"\bfunction\s+&?\s*\w+\s*\(", text):
        if m.start() > pos:
            break
        paren = m.end() - 1
        i = matching_end(text, paren)
        while i < len(text) and text[i] not in "{;":
            i += 1
        end = matching_end(text, i) if i < len(text) and text[i] == "{" else i
        if m.start() <= pos < end and (best is None or m.start() < best[0]):
            best = (m.start(), end)
    if best:
        return text[best[0]:best[1]]
    start = text.rfind("function ", 0, pos)
    end = text.find("\n    }\n", pos)
    return text[max(start, 0):end if end > 0 else len(text)]


def statement_end(text: str, start: int) -> int:
    """Offset of the `;` that ends the statement beginning at start (strings and brackets respected)."""
    depth, i, n = 0, start, len(text)
    while i < n:
        c = text[i]
        if c in "'\"":
            i += 1
            while i < n and text[i] != c:
                i += 2 if text[i] == "\\" else 1
        elif c in "([{":
            depth += 1
        elif c in ")]}":
            depth -= 1
            if depth < 0:
                return i
        elif c == ";" and depth == 0:
            return i
        i += 1
    return n


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
    for name in PROPERTY.findall(code):  # `$row->name` on a DTO / public property is the same as the getter
        if not safe_property(name):
            return f"->{name} (property) reaches the cell unsanitised"
    if BARE_VARIABLE.fullmatch(code) and not EXEMPT_VALID.search(function):
        if top and not SANITIZED.search(function):
            return f"{code} is written in a function that never sanitises"
        # The function sanitises something, but maybe not this row: look at how the variable was filled.
        name = code[1:].split("[", 1)[0]
        names = {name}
        for loop in re.finditer(r"foreach\s*\(\s*\$(\w+)[^)]*?\bas\s+(?:\$\w+\s*=>\s*)?\$" + re.escape(name) + r"\b", function):
            names.add(loop.group(1))  # `foreach ($rows as $row)`: $row is filled where $rows is
        base = "(?:" + "|".join(re.escape(n) for n in names) + ")"
        for assign in re.finditer(r"\$" + base + r"(?:\[[^\]]*\])*\s*=(?![=>])\s*", function):
            rhs_code = function[assign.end():statement_end(function, assign.end())]
            if rhs_code.strip() == code:
                continue
            if "[" in code and SERVICE_CALL.fullmatch(rhs_code.strip()):
                continue  # `$report['plans']['total']` of `$report = $this->service->getReport()`: aggregates, not traced
            problem = value_problem(rhs_code, function, False)
            if problem:
                return f"{code} is filled with unsanitised data ({problem})"
    return None


def sink_value(text: str, match: re.Match[str]) -> str | None:
    """The text a sink writes: fwrite($h, X), file_put_contents($p, X), setContent(X), new Response(X), echo X."""
    word = match.group(0)
    if word == "echo":
        return text[match.end():statement_end(text, match.end())].strip()
    args = arguments(text, match.end())
    if word.startswith(("fwrite", "fputs", "file_put_contents")):
        return args[1].strip() if len(args) > 1 else None
    return args[0].strip() if args else None


def line_problem(value: str, function: str, seen: frozenset[str] = frozenset()) -> str | None:
    """A hand-built line: `implode(<any separator>, $cells)`, `$a . ';' . $b`, `sprintf('%s;%s', …)`, or a variable of those."""
    value = value.strip()
    for part in split_concat(value):  # `implode(';', $cells) . "\n"`; an implode nested in another call is not the line
        bare = re.sub(r"^\(\s*string\s*\)\s*", "", part.strip())
        imp = IMPLODE.match(bare)
        if imp is not None:
            args = arguments(bare, imp.end())
            return value_problem(args[-1] if args else "", function, True)
    parts = split_concat(value)
    if len(parts) > 1 and any(SEPARATOR_LITERAL.match(part.strip()) for part in parts):
        for part in parts:
            if not SEPARATOR_LITERAL.match(part.strip()):
                problem = value_problem(part, function, False)
                if problem:
                    return problem
        return None
    sp = re.match(r"^(?:v?sprintf)\(\s*(['\"])(.*?)\1\s*,", value, re.S)
    if sp and re.search(r"%[-+0-9.$']*[sd]\s*[,;|\\t]", sp.group(2)):
        call = arguments(value, sp.end() - 0 if False else value.index("(") + 1)
        for cell in call[1:]:
            problem = value_problem(cell, function, False)
            if problem:
                return problem
        return None
    var = re.fullmatch(r"\$(\w+)", value)
    if var and var.group(1) not in seen:
        for assign in re.finditer(r"\$" + re.escape(var.group(1)) + r"\s*\.?=(?![=>])\s*", function):
            rhs = function[assign.end():statement_end(function, assign.end())]
            problem = line_problem(rhs, function, seen | {var.group(1)})
            if problem:
                return problem
    return None


def fwrite_problem(text: str, match: re.Match[str]) -> str | None:
    """A CSV line built by hand and written with fwrite / echo / file_put_contents / a response body."""
    value = sink_value(text, match)
    if not value:
        return None
    function = enclosing_function(text, match.start())
    if match.group(0) not in ("fwrite(", "fputs(") and not CSV_HINT.search(text):
        return None  # echo / a response body / a plain file in a file that has nothing to do with CSV
    return line_problem(value, function)


def site_problem(text: str, match: re.Match[str]) -> str | None:
    args = arguments(text, match.end())
    # `setCellValueExplicit($c, $v, DataType::TYPE_STRING)`: a string cell, never evaluated as a formula.
    if "Explicit" in match.group(0) and any(re.search(r"TYPE_(STRING2?|INLINE)\b", a) for a in args[1:]):
        return None
    first = any(name in match.group(0) for name in FIRST_ARG) or text[max(match.start() - 2, 0):match.start()] == "->"
    value = args[0] if first else (args[1] if len(args) > 1 else "")
    return value_problem(value, enclosing_function(text, match.start()), True)


def code_with_markers(raw: str) -> str:
    """The file without comments, but with every `@csv-sanitize-exempt: <reason>` marker kept where it stood."""
    code = list(strip_comments(raw))
    for m in EXEMPT_VALID.finditer(raw):
        code[m.start():m.end()] = list(m.group(0))
    return "".join(code)


def exempt_at(lines: list[str], line: int) -> bool:
    return bool(EXEMPT_VALID.search(lines[line - 1]) or (line > 1 and EXEMPT_VALID.search(lines[line - 2])))


def check_file(rel: str, raw: str) -> tuple[list[str], int]:
    """(failures, write sites) of one PHP file."""
    failures: list[str] = []
    sites = 0
    lines = raw.split("\n")
    text = code_with_markers(raw)
    matches = list(CALL.finditer(text))
    if SPREADSHEET_FILE.search(text):
        known = {m.end() for m in matches}  # same `(` = same call
        matches += [m for m in SET_VALUE.finditer(text) if m.end() not in known]
    for match in matches:
        sites += 1
        line = text.count("\n", 0, match.start()) + 1
        if exempt_at(lines, line):
            continue
        problem = site_problem(text, match)
        if problem:
            failures.append(f"{rel}:{line}: {problem}")
    for match in INDIRECT_SINK.finditer(text):
        sites += 1
        line = text.count("\n", 0, match.start()) + 1
        if not exempt_at(lines, line):
            failures.append(f"{rel}:{line}: fputcsv used as a callable / by name — cell values are not traced; "
                            "call it directly with CsvSanitizer::sanitize() on record text")
    for match in FWRITE.finditer(text):
        line = text.count("\n", 0, match.start()) + 1
        if exempt_at(lines, line):
            continue
        problem = fwrite_problem(text, match)
        if problem:
            sites += 1
            failures.append(f"{rel}:{line}: {problem}")
    return failures, sites


def main() -> int:
    failures: list[str] = []
    sites = 0
    for path in sorted(SRC.rglob("*.php")):
        found, count = check_file(str(path.relative_to(ROOT)), path.read_text(encoding="utf-8", errors="replace"))
        failures.extend(found)
        sites += count

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
