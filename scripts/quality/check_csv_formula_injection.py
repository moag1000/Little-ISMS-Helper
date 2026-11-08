#!/usr/bin/env python3
"""
check_csv_formula_injection.py — Gate 84: spreadsheet cells go through CsvSanitizer.

A cell that starts with = + - @ (or a tab / CR) is executed as a formula when the
CSV / XLSX is opened in Excel or LibreOffice (CWE-1236). Record text — supplier
names, descriptions, titles — is user input. The sweep of 2026-11 found the DORA
register-of-information CSV and the FTE board CSV writing such cells raw, while
the other exporters used App\\Util\\CsvSanitizer::sanitize().

Every file in src/ (outside src/Command/, whose CSVs are operator-local) that
writes cells — fputcsv(), PhpSpreadsheet ->setCellValue*(), ->fromArray(), or a
hand-built csvRow()/csvLine()/toCsv() helper — must reference CsvSanitizer (or a
shared helper that does: csvCell / sanitizeCell / streamCsvExport). A file whose
cells are provably not record text (counters, translated labels) carries
`// @csv-sanitize-exempt: <reason>`.

    python3 scripts/quality/check_csv_formula_injection.py
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SRC = ROOT / "src"
SKIP_DIRS = (SRC / "Command",)

WRITES = re.compile(
    r"fputcsv\(|->setCellValue(?:Explicit)?(?:ByColumnAndRow)?\(|->fromArray\(\s*\$|"
    r"function\s+(?:csvRow|csvLine|toCsv|buildCsv)\s*\("
)
SANITIZED = re.compile(r"CsvSanitizer|csvCell\b|sanitizeCell\(|streamCsvExport\(")
EXEMPT = re.compile(r"@csv-sanitize-exempt:\s*\S")


def main() -> int:
    failures: list[str] = []
    for path in sorted(SRC.rglob("*.php")):
        if any(str(path).startswith(str(d)) for d in SKIP_DIRS):
            continue
        text = path.read_text(encoding="utf-8", errors="replace")
        match = WRITES.search(text)
        if match is None or SANITIZED.search(text) or EXEMPT.search(text):
            continue
        line = text.count("\n", 0, match.start()) + 1
        failures.append(f"{path.relative_to(ROOT)}:{line}: writes spreadsheet cells without CsvSanitizer")

    if failures:
        for failure in failures:
            print("FAIL " + failure)
        print(
            f"\ncheck_csv_formula_injection: {len(failures)} file(s). Wrap record text with "
            "App\\Util\\CsvSanitizer::sanitize(), or mark the file "
            "`// @csv-sanitize-exempt: <reason>` when no cell carries record text."
        )
        return 1
    print("check_csv_formula_injection: OK")
    return 0


if __name__ == "__main__":
    sys.exit(main())
