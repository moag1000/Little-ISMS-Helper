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


import zipfile

_CRIT_RX = re.compile(r"^\d+(\.\d+)+$")  # e.g. 1.1.1


def locate_columns(header_grid):
    """Find column letters by header label across header rows.

    Only 'references' and 'evidence' are located by label. 'criterion' stays None
    by design: the VDA-ISA criterion-number column has no stable header label, so
    build_records detects it heuristically (per-row scan for an N.N.N pattern).
    """
    cols = {"criterion": None, "references": None, "evidence": None}
    for row in header_grid:
        for letter, text in row.items():
            t = (text or "").strip().lower()
            if cols["references"] is None and t.startswith("verweisung auf andere normen"):
                cols["references"] = letter
            elif cols["evidence"] is None and t.startswith("mögliche nachweise"):
                cols["evidence"] = letter
    return cols


def build_records(data_grid, cols):
    """One record per data row that has a criterion-number in cols['criterion'].

    Each record's 'references' list contains raw (standard_label, clause) tuples
    exactly as returned by parse_references — normalization is a downstream concern.
    """
    out = []
    crit_col = cols.get("criterion")
    for row in data_grid:
        crit = (row.get(crit_col) or "").strip() if crit_col else ""
        if not crit:
            # fall back: find any cell that looks like a criterion number
            crit = next((v.strip() for v in row.values() if _CRIT_RX.match((v or "").strip())), "")
        if not crit or not _CRIT_RX.match(crit):
            continue
        out.append({
            "criterion": crit,
            "references": parse_references(row.get(cols.get("references") or "", "") or ""),
            "evidence": parse_evidence(row.get(cols.get("evidence") or "", "") or ""),
        })
    return out


def _col_letter(cell_ref):
    return re.match(r"([A-Z]+)", cell_ref).group(1)


def read_sheet_grid(xlsx_path, sheet_name):
    """Return list of {col_letter: value} dicts for every row of a sheet.
    Stdlib-only OOXML reader."""
    z = zipfile.ZipFile(xlsx_path)
    shared = []
    if "xl/sharedStrings.xml" in z.namelist():
        ss = z.read("xl/sharedStrings.xml").decode("utf-8", "ignore")
        # each <si> may hold multiple <t> runs -> concatenate per <si>
        for si in re.findall(r"<si>(.*?)</si>", ss, re.S):
            shared.append("".join(re.findall(r"<t[^>]*>(.*?)</t>", si, re.S)))
    wb = z.read("xl/workbook.xml").decode("utf-8", "ignore")
    rels = dict(re.findall(r'<Relationship Id="(rId\d+)"[^>]*Target="([^"]+)"',
                           z.read("xl/_rels/workbook.xml.rels").decode("utf-8", "ignore")))
    target = None
    for nm, rid in re.findall(r'<sheet [^>]*name="([^"]+)"[^>]*r:id="(rId\d+)"', wb):
        if nm == sheet_name:
            target = rels[rid]
    if target is None:
        raise ValueError(f"sheet {sheet_name!r} not found")
    data = z.read("xl/" + target).decode("utf-8", "ignore")
    grid = []
    for row_xml in re.findall(r"<row[^>]*>(.*?)</row>", data, re.S):
        row = {}
        for cell_xml in re.findall(r"<c\b[^>]*>.*?</c>|<c\b[^>]*/>", row_xml, re.S):
            mref = re.search(r'r="([A-Z]+)\d+"', cell_xml)
            if not mref:
                continue
            letter = mref.group(1)
            mv = re.search(r"<v>(.*?)</v>", cell_xml, re.S)
            if mv:
                val = mv.group(1)
                if re.search(r'\bt="s"', cell_xml):  # shared-string index
                    try:
                        val = shared[int(val)]
                    except (ValueError, IndexError):
                        pass
            else:
                # inline string: <c t="inlineStr"><is><t>..</t></is></c>
                runs = re.findall(r"<t[^>]*>(.*?)</t>", cell_xml, re.S)
                if not runs:
                    continue
                val = "".join(runs)
            # unescape common XML entities
            val = (val.replace("&amp;", "&").replace("&lt;", "<")
                      .replace("&gt;", ">").replace("&#10;", "\n").replace("&#13;", ""))
            row[letter] = val
        if row:
            grid.append(row)
    return grid


def _prev_col(letter):
    """Return the column letter immediately to the left (e.g. P -> O, AA -> Z)."""
    if not letter:
        return None
    last = letter[-1]
    prefix = letter[:-1]
    if last == "A":
        if not prefix:
            return None  # A has no predecessor
        prev_prefix = _prev_col(prefix)
        return (prev_prefix if prev_prefix else "") + "Z"
    return prefix + chr(ord(last) - 1)


def _anchor_cols_to_data(cols, data_grid):
    """VDA-ISA workbooks have a one-column left-shift between the header label
    row and the actual data rows (a merged-cell layout artifact). Verify each
    located column has data in criterion rows (rows that have a criterion-number
    cell); if not, try the immediately preceding column."""
    # Only consider actual data rows (rows that have a criterion number)
    criterion_rows = [
        row for row in data_grid
        if any(_CRIT_RX.match((v or "").strip()) for v in row.values())
    ]
    anchored = dict(cols)
    for key in ("references", "evidence"):
        col = anchored.get(key)
        if col is None:
            continue
        has_data = any(row.get(col, "").strip() for row in criterion_rows)
        if not has_data:
            left = _prev_col(col)
            if left and any(row.get(left, "").strip() for row in criterion_rows):
                anchored[key] = left
    return anchored


def extract_workbook(xlsx_path, sheet_name="Informationssicherheit"):
    """High-level: grid -> located columns -> records."""
    grid = read_sheet_grid(xlsx_path, sheet_name)
    header_grid = grid[:8]   # VDA-ISA header band
    cols = locate_columns(header_grid)
    cols = _anchor_cols_to_data(cols, grid)
    return build_records(grid, cols)
