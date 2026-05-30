"""VDA-ISA 6.0.2 workbook extractor. Pure parse fns + stdlib xlsx reader.

The xlsx reader (added in a later task) uses zipfile + xml only (no openpyxl)
because the workbook is a standard OOXML zip. Pure functions below are
unit-tested with string fixtures.
"""
import re

# Map a VDA-ISA standard label to our internal framework code (None = not tracked).
_STD_MAP = [
    (re.compile(r"ISO\s*27001:?2022", re.I), "ISO27001"),
    (re.compile(r"ISO\s*27001:?2013", re.I), "ISO27001-2013"),
    (re.compile(r"NIST\s*CSF", re.I), "NIST-CSF"),
    (re.compile(r"BSI.*Grundschutz", re.I), "BSI-GRUNDSCHUTZ"),
    (re.compile(r"BSI.*200-2", re.I), "BSI-200-2"),
    (re.compile(r"NIST\s*SP\s*800-53", re.I), "NIST-800-53"),
]
_NONE_TOKENS = {"", "keine", "none", "n/a", "-"}


def normalize_standard(label):
    label = (label or "").strip()
    for rx, code in _STD_MAP:
        if rx.search(label):
            return code
    return None


def parse_references(cell):
    """Return list of (standard_label, clause) from a 'Verweisung'-cell."""
    text = (cell or "").strip()
    if text.lower() in _NONE_TOKENS:
        return []
    out = []
    for line in text.splitlines():
        line = line.strip()
        if not line or ":" not in line:
            continue
        std, clauses = line.split(":", 1)
        # a trailing version colon (e.g. 'ISO 27001:2022') is part of the label:
        # re-join when the right side starts with a 4-digit year + a second colon
        m = re.match(r"\s*(\d{4})\s*:\s*(.*)$", clauses)
        if m:
            std = f"{std.strip()}:{m.group(1)}"
            clauses = m.group(2)
        for clause in clauses.split(","):
            clause = clause.strip()
            if clause and clause.lower() not in _NONE_TOKENS:
                out.append((std.strip(), clause))
    return out


def parse_evidence(cell):
    """Split a 'Mögliche Nachweise'-cell into a clean list of examples."""
    text = (cell or "").strip()
    if text.lower() in _NONE_TOKENS:
        return []
    parts = re.split(r"[;,]", text)
    return [p.strip() for p in parts if p.strip()]
