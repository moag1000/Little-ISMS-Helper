# EU Framework Mapping Audit — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a deterministic Layer-1 audit of EU framework mappings + a TISAX workbook extractor, then run a specialist multi-agent Layer-2 audit, producing an auditor-defensible backlog of mapping fixes/additions and a finding table.

**Architecture:** Two layers. Layer 1 = dependency-free Python (stdlib only — `zipfile`/`xml` for xlsx, `csv`, `json`) computing countable metrics (coverage, symmetry, provenance, suspects) into per-framework dossiers. Layer 2 = a `Workflow` script that pipelines each EU framework through the correct specialist persona (correctness audit + breadth proposal), with an adversarial-verify stage and a reasoned-hypothesis human queue. Synthesis merges both into a backlog CSV + finding-table markdown.

**Tech Stack:** Python 3.14 (stdlib only — no openpyxl/pandas; pytest 9.0.2 for tests), existing `fixtures/mappings/public/*.csv` (12-column schema), VDA-ISA 6.0.2 xlsx workbook, Claude `Workflow` tool with `isms-specialist`/`dpo-specialist`/`bsi-specialist` agent types.

**Spec:** `docs/superpowers/specs/2026-05-30-eu-framework-mapping-audit-design.md`

---

## File Structure

| File | Responsibility |
|---|---|
| `scripts/quality/mapping_audit/__init__.py` | Package marker |
| `scripts/quality/mapping_audit/metrics.py` | Pure functions: coverage, symmetry, provenance, suspects (no IO) |
| `scripts/quality/mapping_audit/io.py` | Thin IO: read mapping CSVs, read catalog manifest, write dossier JSON |
| `scripts/quality/mapping_audit/tisax_extract.py` | Pure parse fns + xlsx-reading shell for the VDA-ISA workbook |
| `scripts/quality/mapping_audit/synthesis.py` | Merge agent results → backlog CSV + finding-table markdown |
| `scripts/quality/audit_eu_mappings.py` | CLI entrypoint: Layer-1 dossiers + TISAX extract |
| `fixtures/audit/eu_catalog_manifest.yaml` | Canonical target-requirement-ID catalogs per EU framework (denominators) |
| `scripts/workflows/eu_mapping_audit.workflow.js` | Layer-2 Workflow orchestration script |
| `scripts/quality/mapping_audit/tests/test_metrics.py` | pytest for metrics.py |
| `scripts/quality/mapping_audit/tests/test_tisax_extract.py` | pytest for tisax_extract.py |
| `scripts/quality/mapping_audit/tests/test_synthesis.py` | pytest for synthesis.py |
| `var/audit/<pair>_dossier.json` | Runtime: Layer-1 output (gitignored) |
| `var/audit/eu_mapping_backlog.csv` | Runtime: backlog (gitignored, regenerable) |
| `docs/compliance/eu-mapping-audit-findings.md` | Tracked: finding table (force-add, `docs/` is gitignored) |

Notes:
- `var/` is gitignored → runtime artifacts not committed. The finding table goes to tracked `docs/`.
- Pure functions live in `metrics.py`/`tisax_extract.py` so tests use in-memory fixtures, not files on disk.
- The TISAX extractor locates columns by **header-label match** (the merged-header layout makes fixed column indices fragile).

---

## Task 1: Coverage metric (pure)

**Files:**
- Create: `scripts/quality/mapping_audit/__init__.py` (empty)
- Create: `scripts/quality/mapping_audit/metrics.py`
- Create: `scripts/quality/mapping_audit/tests/__init__.py` (empty)
- Test: `scripts/quality/mapping_audit/tests/test_metrics.py`

- [ ] **Step 1: Create package markers**

```bash
mkdir -p scripts/quality/mapping_audit scripts/quality/mapping_audit/tests
touch scripts/quality/mapping_audit/__init__.py scripts/quality/mapping_audit/tests/__init__.py
```

- [ ] **Step 2: Write the failing test**

```python
# scripts/quality/mapping_audit/tests/test_metrics.py
from scripts.quality.mapping_audit import metrics


def test_coverage_counts_distinct_target_reqs_with_at_least_one_mapping():
    mappings = [
        {"target_requirement_id": "A.5.1"},
        {"target_requirement_id": "A.5.1"},  # duplicate target, counts once
        {"target_requirement_id": "A.5.2"},
    ]
    catalog = ["A.5.1", "A.5.2", "A.5.3", "A.5.4"]
    result = metrics.coverage(mappings, catalog)
    assert result["mapped_count"] == 2
    assert result["catalog_count"] == 4
    assert result["coverage_pct"] == 50.0
    assert result["unmapped"] == ["A.5.3", "A.5.4"]


def test_coverage_empty_catalog_is_zero_not_crash():
    result = metrics.coverage([], [])
    assert result["coverage_pct"] == 0.0
    assert result["unmapped"] == []
```

- [ ] **Step 3: Run test to verify it fails**

Run: `python3 -m pytest scripts/quality/mapping_audit/tests/test_metrics.py -v`
Expected: FAIL — `ModuleNotFoundError` / `AttributeError: module 'metrics' has no attribute 'coverage'`

- [ ] **Step 4: Implement `coverage`**

```python
# scripts/quality/mapping_audit/metrics.py
"""Pure metric functions for EU mapping audit. No IO, no side effects."""


def coverage(mappings, catalog):
    """Share of catalog target requirements that have >=1 mapping."""
    mapped = {m["target_requirement_id"] for m in mappings}
    catalog_set = list(dict.fromkeys(catalog))  # de-dup, keep order
    covered = [c for c in catalog_set if c in mapped]
    unmapped = [c for c in catalog_set if c not in mapped]
    catalog_count = len(catalog_set)
    pct = round(100.0 * len(covered) / catalog_count, 1) if catalog_count else 0.0
    return {
        "mapped_count": len(covered),
        "catalog_count": catalog_count,
        "coverage_pct": pct,
        "unmapped": unmapped,
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `python3 -m pytest scripts/quality/mapping_audit/tests/test_metrics.py -v`
Expected: PASS (2 passed)

- [ ] **Step 6: Commit**

```bash
git add scripts/quality/mapping_audit/__init__.py scripts/quality/mapping_audit/metrics.py scripts/quality/mapping_audit/tests/__init__.py scripts/quality/mapping_audit/tests/test_metrics.py
git commit -m "feat(audit): EU-mapping coverage metric (pure fn + tests)"
```

---

## Task 2: Symmetry, provenance, suspect metrics (pure)

**Files:**
- Modify: `scripts/quality/mapping_audit/metrics.py`
- Test: `scripts/quality/mapping_audit/tests/test_metrics.py`

- [ ] **Step 1: Add failing tests**

```python
# append to scripts/quality/mapping_audit/tests/test_metrics.py
def test_bidirectional_symmetry_share_of_AtoB_with_matching_BtoA():
    forward = [
        {"source_requirement_id": "Art.21.2.a", "target_requirement_id": "A.5.1"},
        {"source_requirement_id": "Art.21.2.b", "target_requirement_id": "A.5.2"},
    ]
    reverse = [  # B->A direction (only A.5.1 -> Art.21.2.a present)
        {"source_requirement_id": "A.5.1", "target_requirement_id": "Art.21.2.a"},
    ]
    result = metrics.bidirectional_symmetry(forward, reverse)
    assert result["forward_count"] == 2
    assert result["symmetric_count"] == 1
    assert result["symmetry_pct"] == 50.0


def test_provenance_completeness_counts_rows_with_source_and_url():
    rows = [
        {"source_catalog": "enisa_nis2_annex_c_2024", "provenance_url": "https://x"},
        {"source_catalog": "enisa_nis2_annex_c_2024", "provenance_url": ""},
        {"source_catalog": "", "provenance_url": ""},
    ]
    result = metrics.provenance_completeness(rows)
    assert result["complete"] == 1
    assert result["total"] == 3
    assert result["complete_pct"] == 33.3


def test_suspects_flag_full_or_exceeds_with_low_confidence_or_no_rationale():
    rows = [
        {"mapping_percentage": "100", "confidence": "low", "rationale": "x", "target_requirement_id": "A.5.1", "source_requirement_id": "Art.21.2.a"},
        {"mapping_percentage": "120", "confidence": "high", "rationale": "", "target_requirement_id": "A.5.2", "source_requirement_id": "Art.21.2.b"},
        {"mapping_percentage": "60", "confidence": "low", "rationale": "x", "target_requirement_id": "A.5.3", "source_requirement_id": "Art.21.2.c"},
    ]
    result = metrics.suspects(rows)
    reasons = {(s["source_requirement_id"], s["reason"]) for s in result}
    assert ("Art.21.2.a", "high_pct_low_confidence") in reasons
    assert ("Art.21.2.b", "high_pct_no_rationale") in reasons
    assert len(result) == 2  # the 60% row is not suspect
```

- [ ] **Step 2: Run to verify fail**

Run: `python3 -m pytest scripts/quality/mapping_audit/tests/test_metrics.py -v`
Expected: FAIL — `AttributeError` for `bidirectional_symmetry`/`provenance_completeness`/`suspects`

- [ ] **Step 3: Implement the three functions**

```python
# append to scripts/quality/mapping_audit/metrics.py
def bidirectional_symmetry(forward, reverse):
    """Share of forward A->B pairs that have a matching reverse B->A pair."""
    reverse_pairs = {(r["source_requirement_id"], r["target_requirement_id"]) for r in reverse}
    symmetric = 0
    for f in forward:
        # forward pair (src->tgt) is symmetric if reverse has (tgt->src)
        if (f["target_requirement_id"], f["source_requirement_id"]) in reverse_pairs:
            symmetric += 1
    total = len(forward)
    pct = round(100.0 * symmetric / total, 1) if total else 0.0
    return {"forward_count": total, "symmetric_count": symmetric, "symmetry_pct": pct}


def provenance_completeness(rows):
    """A row is provenance-complete when it has both a source_catalog and a URL."""
    complete = sum(
        1 for r in rows
        if (r.get("source_catalog") or "").strip() and (r.get("provenance_url") or "").strip()
    )
    total = len(rows)
    pct = round(100.0 * complete / total, 1) if total else 0.0
    return {"complete": complete, "total": total, "complete_pct": pct}


def suspects(rows):
    """Flag 100%/exceeds mappings that lack confidence or rationale backing."""
    out = []
    for r in rows:
        try:
            pct = int(r.get("mapping_percentage") or 0)
        except ValueError:
            pct = 0
        if pct < 100:
            continue
        base = {
            "source_requirement_id": r.get("source_requirement_id", ""),
            "target_requirement_id": r.get("target_requirement_id", ""),
            "mapping_percentage": pct,
        }
        if (r.get("confidence") or "").strip().lower() == "low":
            out.append({**base, "reason": "high_pct_low_confidence"})
        elif not (r.get("rationale") or "").strip():
            out.append({**base, "reason": "high_pct_no_rationale"})
    return out
```

- [ ] **Step 4: Run to verify pass**

Run: `python3 -m pytest scripts/quality/mapping_audit/tests/test_metrics.py -v`
Expected: PASS (5 passed)

- [ ] **Step 5: Commit**

```bash
git add scripts/quality/mapping_audit/metrics.py scripts/quality/mapping_audit/tests/test_metrics.py
git commit -m "feat(audit): symmetry, provenance, suspect metrics (pure fns + tests)"
```

---

## Task 3: EU catalog manifest (data)

**Files:**
- Create: `fixtures/audit/eu_catalog_manifest.yaml`

The manifest enumerates the canonical target-requirement IDs that each EU framework's coverage is measured against (the denominator). TISAX is filled by the extractor (Task 6), so it is left as an empty list with a `source: workbook` marker.

- [ ] **Step 1: Create the manifest**

```yaml
# fixtures/audit/eu_catalog_manifest.yaml
# Canonical requirement-ID catalogs (denominators) for EU-framework mapping coverage.
# IDs MUST match the source_requirement_id / target_requirement_id values used in
# fixtures/mappings/public/*.csv. Keep in sync when a catalog row is added.
NIS2:
  source: enisa_nis2_2024
  requirements:
    - Art.21.2.a
    - Art.21.2.b
    - Art.21.2.c
    - Art.21.2.d
    - Art.21.2.e
    - Art.21.2.f
    - Art.21.2.g
    - Art.21.2.h
    - Art.21.2.i
    - Art.21.2.j
DORA:
  source: eu_2022_2554
  requirements:
    - Art.5
    - Art.6
    - Art.7
    - Art.8
    - Art.9
    - Art.10
    - Art.11
    - Art.12
    - Art.13
    - Art.14
    - Art.17
    - Art.18
    - Art.19
    - Art.28
    - Art.29
    - Art.30
GDPR:
  source: eu_2016_679
  requirements:
    - Art.5
    - Art.6
    - Art.25
    - Art.30
    - Art.32
    - Art.33
    - Art.34
    - Art.35
    - Art.37
TISAX:
  source: workbook   # filled by tisax_extract (Task 6) — leave empty here
  requirements: []
```

> NOTE for the implementer: these ID lists are the *known* catalog scope at plan time. Task 8's specialist stage explicitly re-checks whether the catalog is complete against the official source — if the specialist finds a missing canonical requirement (e.g. a DORA article not listed), that becomes a finding and the manifest is extended. Do not treat this list as exhaustive; treat it as the audited baseline.

- [ ] **Step 2: Commit**

```bash
git add -f fixtures/audit/eu_catalog_manifest.yaml
git commit -m "feat(audit): EU framework catalog manifest (coverage denominators)"
```

(`fixtures/audit/` may be under the `docs`/`var` ignore patterns — verify with `git check-ignore fixtures/audit/eu_catalog_manifest.yaml`; if ignored, `-f` as shown. `fixtures/` is normally tracked, so `-f` is likely unnecessary — drop it if `git add` succeeds.)

---

## Task 4: IO layer — load mappings, load manifest, write dossier

**Files:**
- Create: `scripts/quality/mapping_audit/io.py`
- Test: `scripts/quality/mapping_audit/tests/test_io.py`

The manifest is YAML, but to stay dependency-free we parse the manifest's simple `key:` + `- item` structure with a tiny hand-rolled reader (no PyYAML). Mapping CSVs use stdlib `csv`.

- [ ] **Step 1: Write the failing test**

```python
# scripts/quality/mapping_audit/tests/test_io.py
import json
from scripts.quality.mapping_audit import io as audit_io


def test_read_mapping_csv_skips_comment_and_header(tmp_path):
    csv_file = tmp_path / "nis2_iso27001_v1.csv"
    csv_file.write_text(
        "# comment line\n"
        "source_framework,source_requirement_id,target_framework,target_requirement_id,mapping_percentage,mapping_type,confidence,bidirectional,rationale,source_catalog,validated_at,validated_by\n"
        'NIS2,Art.21.2.a,ISO27001,A.5.1,90,partial,high,true,"x",enisa,2026-04-17,Consultant\n',
        encoding="utf-8",
    )
    rows = audit_io.read_mapping_csv(str(csv_file))
    assert len(rows) == 1
    assert rows[0]["source_requirement_id"] == "Art.21.2.a"
    assert rows[0]["mapping_percentage"] == "90"


def test_read_manifest_parses_requirements_per_framework(tmp_path):
    man = tmp_path / "m.yaml"
    man.write_text(
        "NIS2:\n  source: enisa\n  requirements:\n    - Art.21.2.a\n    - Art.21.2.b\n"
        "TISAX:\n  source: workbook\n  requirements: []\n",
        encoding="utf-8",
    )
    cat = audit_io.read_catalog_manifest(str(man))
    assert cat["NIS2"]["requirements"] == ["Art.21.2.a", "Art.21.2.b"]
    assert cat["TISAX"]["requirements"] == []


def test_write_dossier_roundtrips(tmp_path):
    out = tmp_path / "nis2_iso27001_dossier.json"
    audit_io.write_dossier(str(out), {"pair": "NIS2_ISO27001", "coverage_pct": 50.0})
    loaded = json.loads(out.read_text(encoding="utf-8"))
    assert loaded["coverage_pct"] == 50.0
```

- [ ] **Step 2: Run to verify fail**

Run: `python3 -m pytest scripts/quality/mapping_audit/tests/test_io.py -v`
Expected: FAIL — `ModuleNotFoundError: scripts.quality.mapping_audit.io`

- [ ] **Step 3: Implement io.py**

```python
# scripts/quality/mapping_audit/io.py
"""Thin IO for the EU mapping audit. Stdlib only (csv, json)."""
import csv
import json


def read_mapping_csv(path):
    """Read a public mapping CSV, skipping leading '#' comment lines."""
    with open(path, encoding="utf-8") as fh:
        lines = [ln for ln in fh if not ln.lstrip().startswith("#")]
    reader = csv.DictReader(lines)
    return [dict(row) for row in reader]


def read_catalog_manifest(path):
    """Parse the tiny manifest dialect: 'Framework:' blocks with 'source:' and
    'requirements:' (a '- item' list or '[]'). No PyYAML dependency."""
    catalog = {}
    current = None
    in_reqs = False
    with open(path, encoding="utf-8") as fh:
        for raw in fh:
            line = raw.rstrip("\n")
            if not line.strip() or line.lstrip().startswith("#"):
                continue
            if not line.startswith(" "):  # top-level "Framework:"
                current = line.rstrip(":").strip()
                catalog[current] = {"source": "", "requirements": []}
                in_reqs = False
            elif line.strip().startswith("source:"):
                catalog[current]["source"] = line.split(":", 1)[1].strip()
                in_reqs = False
            elif line.strip().startswith("requirements:"):
                rest = line.split(":", 1)[1].strip()
                in_reqs = rest != "[]"
            elif in_reqs and line.strip().startswith("- "):
                catalog[current]["requirements"].append(line.strip()[2:].strip())
    return catalog


def write_dossier(path, data):
    import os
    os.makedirs(os.path.dirname(path) or ".", exist_ok=True)
    with open(path, "w", encoding="utf-8") as fh:
        json.dump(data, fh, ensure_ascii=False, indent=2)
```

- [ ] **Step 4: Run to verify pass**

Run: `python3 -m pytest scripts/quality/mapping_audit/tests/test_io.py -v`
Expected: PASS (3 passed)

- [ ] **Step 5: Commit**

```bash
git add scripts/quality/mapping_audit/io.py scripts/quality/mapping_audit/tests/test_io.py
git commit -m "feat(audit): IO layer for mapping CSV + catalog manifest + dossier"
```

---

## Task 5: TISAX extractor — pure parse functions

**Files:**
- Create: `scripts/quality/mapping_audit/tisax_extract.py`
- Test: `scripts/quality/mapping_audit/tests/test_tisax_extract.py`

The VDA-ISA "Verweisung auf andere Normen" cell contains multi-standard references like:
`"ISO 27001:2013: A.5.1.1, A.5.1.2\nISO 27001:2022: A.5.1\nISA/IEC 62443: 1.1.1\nNIST CSF 1.1: ID.GV-1"`.
`parse_references` turns one cell into `(standard, clause)` pairs. `parse_evidence` splits the "Mögliche Nachweise" cell into a list.

- [ ] **Step 1: Write the failing test**

```python
# scripts/quality/mapping_audit/tests/test_tisax_extract.py
from scripts.quality.mapping_audit import tisax_extract as tx


def test_parse_references_splits_multistandard_cell():
    cell = (
        "ISO 27001:2013: A.5.1.1, A.5.1.2\n"
        "ISO 27001:2022: A.5.1\n"
        "ISA/IEC 62443: 1.1.1\n"
        "NIST CSF 1.1: ID.GV-1"
    )
    refs = tx.parse_references(cell)
    assert ("ISO 27001:2022", "A.5.1") in refs
    assert ("ISO 27001:2013", "A.5.1.1") in refs
    assert ("ISO 27001:2013", "A.5.1.2") in refs
    assert ("ISA/IEC 62443", "1.1.1") in refs
    assert ("NIST CSF 1.1", "ID.GV-1") in refs


def test_parse_references_empty_cell_is_empty():
    assert tx.parse_references("") == []
    assert tx.parse_references("keine") == []


def test_parse_evidence_splits_examples():
    cell = "Schulungsplan, Schulungsregister; e-Learnings"
    ev = tx.parse_evidence(cell)
    assert ev == ["Schulungsplan", "Schulungsregister", "e-Learnings"]


def test_normalize_standard_maps_to_framework_code():
    assert tx.normalize_standard("ISO 27001:2022") == "ISO27001"
    assert tx.normalize_standard("NIST CSF 1.1") == "NIST-CSF"
    assert tx.normalize_standard("BSI IT-Grundschutz-Compendium") == "BSI-GRUNDSCHUTZ"
    assert tx.normalize_standard("ISA/IEC 62443") is None  # not a tracked framework
```

- [ ] **Step 2: Run to verify fail**

Run: `python3 -m pytest scripts/quality/mapping_audit/tests/test_tisax_extract.py -v`
Expected: FAIL — `ModuleNotFoundError`

- [ ] **Step 3: Implement parse functions**

```python
# scripts/quality/mapping_audit/tisax_extract.py
"""VDA-ISA 6.0.2 workbook extractor. Pure parse fns + stdlib xlsx reader.

The xlsx reader uses zipfile + xml only (no openpyxl) because the workbook is a
standard OOXML zip. Pure functions below are unit-tested with string fixtures.
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
```

- [ ] **Step 4: Run to verify pass**

Run: `python3 -m pytest scripts/quality/mapping_audit/tests/test_tisax_extract.py -v`
Expected: PASS (4 passed)

- [ ] **Step 5: Commit**

```bash
git add scripts/quality/mapping_audit/tisax_extract.py scripts/quality/mapping_audit/tests/test_tisax_extract.py
git commit -m "feat(audit): TISAX VDA-ISA reference/evidence parse fns (pure + tests)"
```

---

## Task 6: TISAX extractor — xlsx reading shell + column auto-locate

**Files:**
- Modify: `scripts/quality/mapping_audit/tisax_extract.py`
- Test: `scripts/quality/mapping_audit/tests/test_tisax_extract.py`

The reader: open the xlsx zip, read `sharedStrings.xml`, resolve the `Informationssicherheit` sheet, scan header rows to find the column letters for the criterion number, the "Verweisung auf andere Normen" cell, and the "Mögliche Nachweise" cell (label-match, not fixed index), then yield one record per data row. Tests cover the column-locate logic with a small in-memory cell grid (the zip-reading wrapper itself is exercised by the live run in Task 7, not unit-tested — crafting a valid xlsx in a test is not worth the cost).

- [ ] **Step 1: Add failing test for `locate_columns`**

```python
# append to scripts/quality/mapping_audit/tests/test_tisax_extract.py
def test_locate_columns_finds_by_header_label():
    # header_grid: list of rows, each row is dict col_letter -> text
    header_grid = [
        {"A": "header", "C": "Nr.", "K": "Verweisung auf andere Normen", "M": "Mögliche Nachweise (nicht verbindlich)"},
    ]
    cols = tx.locate_columns(header_grid)
    assert cols["references"] == "K"
    assert cols["evidence"] == "M"


def test_build_records_uses_located_columns():
    cols = {"criterion": "B", "references": "K", "evidence": "M"}
    data_grid = [
        {"B": "1.1.1", "K": "ISO 27001:2022: A.5.1", "M": "Richtlinie, Intranet"},
        {"B": "", "K": "", "M": ""},  # blank criterion -> skipped
    ]
    records = tx.build_records(data_grid, cols)
    assert len(records) == 1
    rec = records[0]
    assert rec["criterion"] == "1.1.1"
    assert ("ISO 27001:2022", "A.5.1") in rec["references"]
    assert rec["evidence"] == ["Richtlinie", "Intranet"]
```

- [ ] **Step 2: Run to verify fail**

Run: `python3 -m pytest scripts/quality/mapping_audit/tests/test_tisax_extract.py -v`
Expected: FAIL — `AttributeError: locate_columns` / `build_records`

- [ ] **Step 3: Implement locate/build + xlsx reader**

```python
# append to scripts/quality/mapping_audit/tisax_extract.py
import zipfile

_CRIT_RX = re.compile(r"^\d+(\.\d+)+$")  # e.g. 1.1.1


def locate_columns(header_grid):
    """Find column letters by header label across header rows."""
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
    """One record per data row that has a criterion-number in cols['criterion']."""
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
            "references": parse_references(row.get(cols.get("references"), "")),
            "evidence": parse_evidence(row.get(cols.get("evidence"), "")),
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
        for c in re.findall(r'<c[^>]*r="([A-Z]+\d+)"[^>]*?(?:/>|>(.*?)</c>)', row_xml, re.S):
            ref, body = c
            mv = re.search(r"<v>(.*?)</v>", body)
            if not mv:
                continue
            val = mv.group(1)
            if 't="s"' in row_xml[:0] or re.search(r'r="' + ref + r'"[^>]*t="s"', row_xml):
                try:
                    val = shared[int(val)]
                except (ValueError, IndexError):
                    pass
            row[_col_letter(ref)] = val
        if row:
            grid.append(row)
    return grid


def extract_workbook(xlsx_path, sheet_name="Informationssicherheit"):
    """High-level: grid -> located columns -> records."""
    grid = read_sheet_grid(xlsx_path, sheet_name)
    header_grid = grid[:8]   # VDA-ISA header band
    cols = locate_columns(header_grid)
    return build_records(grid, cols)
```

- [ ] **Step 4: Run to verify pass**

Run: `python3 -m pytest scripts/quality/mapping_audit/tests/test_tisax_extract.py -v`
Expected: PASS (6 passed)

- [ ] **Step 5: Smoke-test against the real workbook**

Run:
```bash
python3 -c "
from scripts.quality.mapping_audit import tisax_extract as tx
recs = tx.extract_workbook('/Users/michaelbanda/Downloads/ISA6_DE_6.0.2.xlsx')
print('records:', len(recs))
iso = [r for r in recs if any(s=='ISO27001' for s,_ in r['references'])]
print('with ISO27001 ref:', len(iso))
print('sample:', recs[0] if recs else None)
"
```
Expected: non-zero record count, a sizeable share carrying an `ISO27001` reference, sample shows criterion + references + evidence. If `references` are empty everywhere, the column-locate failed → inspect `locate_columns` against the real header band and adjust the label match. Do NOT proceed until references extract correctly (this is the TISAX ground-truth — it must be real).

- [ ] **Step 6: Commit**

```bash
git add scripts/quality/mapping_audit/tisax_extract.py scripts/quality/mapping_audit/tests/test_tisax_extract.py
git commit -m "feat(audit): TISAX workbook xlsx reader + column auto-locate"
```

---

## Task 7: CLI entrypoint — Layer-1 dossiers + TISAX catalog

**Files:**
- Create: `scripts/quality/audit_eu_mappings.py`

This wires Tasks 1-6 into runnable dossiers. It also writes a derived TISAX catalog (criterion IDs from the workbook) so TISAX coverage becomes computable, and emits TISAX-derived official mapping rows (criterion → ISO27001/NIST-CSF/BSI clause) as a candidate CSV for Wave 1 re-import.

- [ ] **Step 1: Implement the CLI**

```python
#!/usr/bin/env python3
"""Layer-1 EU mapping audit CLI.

Usage:
  python3 scripts/quality/audit_eu_mappings.py \
      --mappings-dir fixtures/mappings/public \
      --manifest fixtures/audit/eu_catalog_manifest.yaml \
      --workbook /Users/michaelbanda/Downloads/ISA6_DE_6.0.2.xlsx \
      --out var/audit
Outputs per pair: var/audit/<pair>_dossier.json
Plus: var/audit/tisax_workbook_mappings_candidate.csv
"""
import argparse
import csv
import glob
import os

from scripts.quality.mapping_audit import io as audit_io
from scripts.quality.mapping_audit import metrics
from scripts.quality.mapping_audit import tisax_extract as tx

# Which CSV files feed which EU target framework (forward direction into ISO/other).
EU_PAIRS = {
    "NIS2": ["nis2_iso27001_v1.csv"],
    "DORA": ["dora_iso27001_v1.csv", "dora_iso27005_v1.csv", "dora_iso22301_v1.csv"],
    "GDPR": ["gdpr_iso27701_v1.csv"],
}


def _pct_int(row):
    try:
        return int(row.get("mapping_percentage") or 0)
    except ValueError:
        return 0


def build_dossier(framework, csv_files, mappings_dir, catalog):
    rows = []
    for f in csv_files:
        path = os.path.join(mappings_dir, f)
        if os.path.exists(path):
            rows.extend(audit_io.read_mapping_csv(path))
    # coverage is measured on the EU framework's own requirements as source side
    cat_reqs = catalog.get(framework, {}).get("requirements", [])
    # treat the EU-framework requirement as the "covered" axis: a req is covered
    # if it appears as source_requirement_id in >=1 mapping row.
    source_view = [{"target_requirement_id": r["source_requirement_id"]} for r in rows]
    cov = metrics.coverage(source_view, cat_reqs)
    prov = metrics.provenance_completeness(rows)
    susp = metrics.suspects(rows)
    pct_values = [_pct_int(r) for r in rows]
    return {
        "framework": framework,
        "csv_files": csv_files,
        "row_count": len(rows),
        "coverage": cov,
        "provenance": prov,
        "suspects": susp,
        "pct_histogram": {
            "weak_0_49": sum(1 for p in pct_values if p < 50),
            "partial_50_99": sum(1 for p in pct_values if 50 <= p < 100),
            "full_100": sum(1 for p in pct_values if p == 100),
            "exceeds_101_plus": sum(1 for p in pct_values if p > 100),
        },
    }


def write_tisax_candidate(records, out_dir):
    path = os.path.join(out_dir, "tisax_workbook_mappings_candidate.csv")
    with open(path, "w", encoding="utf-8", newline="") as fh:
        w = csv.writer(fh)
        w.writerow(["source_framework", "source_requirement_id", "target_framework",
                    "target_requirement_id", "source_catalog", "evidence_hint"])
        for rec in records:
            ev = " | ".join(rec["evidence"])
            for std_label, clause in rec["references"]:
                code = tx.normalize_standard(std_label)
                if code is None:
                    continue
                w.writerow(["TISAX", rec["criterion"], code, clause,
                            "vda_isa_6.0.2_workbook", ev])
    return path


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--mappings-dir", default="fixtures/mappings/public")
    ap.add_argument("--manifest", default="fixtures/audit/eu_catalog_manifest.yaml")
    ap.add_argument("--workbook", default="")
    ap.add_argument("--out", default="var/audit")
    args = ap.parse_args()

    os.makedirs(args.out, exist_ok=True)
    catalog = audit_io.read_catalog_manifest(args.manifest)

    for framework, csv_files in EU_PAIRS.items():
        dossier = build_dossier(framework, csv_files, args.mappings_dir, catalog)
        out = os.path.join(args.out, f"{framework.lower()}_dossier.json")
        audit_io.write_dossier(out, dossier)
        c = dossier["coverage"]
        print(f"{framework}: coverage {c['coverage_pct']}% "
              f"({c['mapped_count']}/{c['catalog_count']}), "
              f"{len(dossier['suspects'])} suspect, "
              f"provenance {dossier['provenance']['complete_pct']}%")

    if args.workbook and os.path.exists(args.workbook):
        recs = tx.extract_workbook(args.workbook)
        tisax_cat = sorted({r["criterion"] for r in recs})
        audit_io.write_dossier(os.path.join(args.out, "tisax_catalog.json"),
                               {"framework": "TISAX", "requirements": tisax_cat,
                                "criterion_count": len(tisax_cat)})
        cand = write_tisax_candidate(recs, args.out)
        print(f"TISAX: {len(tisax_cat)} criteria, candidate rows -> {cand}")


if __name__ == "__main__":
    main()
```

- [ ] **Step 2: Run the CLI end-to-end**

Run:
```bash
python3 scripts/quality/audit_eu_mappings.py --workbook /Users/michaelbanda/Downloads/ISA6_DE_6.0.2.xlsx
```
Expected: printed coverage/suspect/provenance lines for NIS2, DORA, GDPR + a TISAX criteria count and candidate-CSV path. `var/audit/*.json` written.

- [ ] **Step 3: Sanity-check one dossier**

Run: `python3 -c "import json;d=json.load(open('var/audit/nis2_dossier.json'));print(json.dumps(d['coverage'],indent=2));print('suspects',len(d['suspects']))"`
Expected: coverage object with non-zero `catalog_count`, an `unmapped` list. This is the deterministic baseline the workflow consumes.

- [ ] **Step 4: Commit (code only — `var/` is gitignored)**

```bash
git add scripts/quality/audit_eu_mappings.py
git commit -m "feat(audit): Layer-1 CLI — dossiers + TISAX workbook candidate CSV"
```

---

## Task 8: Layer-2 specialist audit Workflow script

**Files:**
- Create: `scripts/workflows/eu_mapping_audit.workflow.js`

This is the multi-agent orchestration (run via the `Workflow` tool with `scriptPath`). It pipelines each EU framework through the correct specialist for a correctness audit (2a) + breadth proposal (2b), then adversarially verifies each confirmed/proposed finding. Hypotheses skip verify and go to the human queue with reasoning. The script reads the Layer-1 dossiers as the deterministic context — it never recomputes metrics.

- [ ] **Step 1: Write the workflow script**

```javascript
// scripts/workflows/eu_mapping_audit.workflow.js
export const meta = {
  name: 'eu-mapping-audit',
  description: 'Specialist audit of EU framework mappings (correctness + breadth) with adversarial verify and reasoned human-queue hypotheses',
  phases: [
    { title: 'Audit', detail: 'one specialist per EU framework: correctness + breadth' },
    { title: 'Verify', detail: 'adversarial refute per confirmed/proposed finding' },
  ],
}

const FINDINGS_SCHEMA = {
  type: 'object',
  required: ['confirmed', 'suspect', 'proposed', 'hypotheses'],
  properties: {
    confirmed: { type: 'array', items: { type: 'object',
      required: ['source_req', 'target_req', 'pct', 'ground_truth_cite'],
      properties: {
        source_req: { type: 'string' }, target_req: { type: 'string' },
        pct: { type: 'integer' }, ground_truth_cite: { type: 'string' },
      } } },
    suspect: { type: 'array', items: { type: 'object',
      required: ['source_req', 'target_req', 'current_pct', 'issue', 'recommended_action'],
      properties: {
        source_req: { type: 'string' }, target_req: { type: 'string' },
        current_pct: { type: 'integer' }, issue: { type: 'string' },
        recommended_action: { type: 'string', enum: ['fix_pct', 'remove', 'add_provenance'] },
      } } },
    proposed: { type: 'array', items: { type: 'object',
      required: ['source_req', 'target_req', 'pct', 'ground_truth_cite'],
      properties: {
        source_req: { type: 'string' }, target_req: { type: 'string' },
        pct: { type: 'integer' }, ground_truth_cite: { type: 'string' },
      } } },
    hypotheses: { type: 'array', items: { type: 'object',
      required: ['source_req', 'target_req', 'hypothesis_pct', 'reasoning', 'uncertainty_reason', 'resolution_hint', 'confidence_band'],
      properties: {
        source_req: { type: 'string' }, target_req: { type: 'string' },
        hypothesis_pct: { type: 'integer' }, reasoning: { type: 'string' },
        uncertainty_reason: { type: 'string' }, resolution_hint: { type: 'string' },
        confidence_band: { type: 'string', enum: ['low', 'med'] },
      } } },
  },
}

const VERDICT_SCHEMA = {
  type: 'object',
  required: ['verdict', 'evidence'],
  properties: {
    verdict: { type: 'string', enum: ['hold', 'refute'] },
    evidence: { type: 'string' },
  },
}

// args = [{ framework, specialist, groundTruth, dossierJson }, ...]
const FRAMEWORKS = args

const results = await pipeline(
  FRAMEWORKS,
  // Stage A — specialist correctness + breadth audit
  fw => agent(
    `You are auditing the **${fw.framework}** cross-framework mappings of an ISMS tool for an EU-compliance critique that said the mappings are too thin and not audit-defensible.

DETERMINISTIC BASELINE (computed by script — treat as ground truth, do NOT recompute):
${fw.dossierJson}

GROUND-TRUTH SOURCE you must cite against: ${fw.groundTruth}

Two tasks:
(2a) CORRECTNESS: For existing mappings, judge whether each mapping_percentage is defensible against the actual target-norm clause text. Flag wrong/overstated ones as 'suspect' with a recommended_action.
(2b) BREADTH: For the dossier's unmapped requirements, propose high-value mappings that you can ground in the cited source.

HARD RULES:
- Every 'confirmed' and 'proposed' item MUST carry a ground_truth_cite quoting the real clause text or an official published crosswalk. No cite => it is NOT confirmed/proposed.
- If you cannot ground a mapping but have a reasoned guess, put it in 'hypotheses' with hypothesis_pct + reasoning + uncertainty_reason + resolution_hint + confidence_band. NEVER invent a clause id or percentage as fact.
- Use WebSearch against the official source (${fw.groundTruth}) to verify before asserting.
- Default to skepticism. When unsure, hypothesize — do not confirm.`,
    { label: `audit:${fw.framework}`, phase: 'Audit', schema: FINDINGS_SCHEMA, agentType: fw.specialist }
  ),
  // Stage B — adversarial verify of confirmed + proposed (hypotheses skip to human queue)
  (findings, fw) => {
    const toVerify = [
      ...(findings?.confirmed || []).map(f => ({ ...f, kind: 'confirmed' })),
      ...(findings?.proposed || []).map(f => ({ ...f, kind: 'proposed' })),
    ]
    return parallel(toVerify.map(f => () =>
      agent(
        `Adversarially REFUTE this ${fw.framework} mapping. Default verdict: refute unless the cited ground-truth clearly supports it.

Mapping: ${f.source_req} -> ${f.target_req} at ${f.pct}%
Claimed ground-truth: ${f.ground_truth_cite}
Source to re-check: ${fw.groundTruth}

Re-read the cited clause via WebSearch. If the percentage is overstated, the clause does not say what is claimed, or the cite is unverifiable, return verdict 'refute' with evidence. Only 'hold' if the cite genuinely supports the mapping.`,
        { label: `verify:${fw.framework}:${f.source_req}`, phase: 'Verify', schema: VERDICT_SCHEMA, agentType: fw.specialist }
      ).then(v => ({ ...f, framework: fw.framework, verify: v }))
    )).then(verified => ({ framework: fw.framework, findings, verified }))
  }
)

return results.filter(Boolean)
```

- [ ] **Step 2: Dry-validate the script parses**

Run: `node --check scripts/workflows/eu_mapping_audit.workflow.js`
Expected: no output (syntax OK). If `node` is unavailable, skip — the Workflow tool reports parse errors on run.

- [ ] **Step 3: Commit**

```bash
git add scripts/workflows/eu_mapping_audit.workflow.js
git commit -m "feat(audit): Layer-2 specialist audit Workflow (audit + adversarial verify)"
```

---

## Task 9: Synthesis — backlog CSV + finding table

**Files:**
- Create: `scripts/quality/mapping_audit/synthesis.py`
- Modify: `scripts/quality/audit_eu_mappings.py` (add `--synthesize` mode reading workflow results JSON)
- Test: `scripts/quality/mapping_audit/tests/test_synthesis.py`

The workflow's return value (array of `{framework, findings, verified}`) is saved to `var/audit/workflow_results.json` by the operator after the run. Synthesis turns it into the backlog CSV + the tracked finding-table markdown.

- [ ] **Step 1: Write failing test**

```python
# scripts/quality/mapping_audit/tests/test_synthesis.py
from scripts.quality.mapping_audit import synthesis


def _sample_results():
    return [{
        "framework": "NIS2",
        "findings": {
            "confirmed": [{"source_req": "Art.21.2.a", "target_req": "A.5.1", "pct": 90, "ground_truth_cite": "ENISA Annex C"}],
            "suspect": [{"source_req": "Art.21.2.b", "target_req": "A.5.99", "current_pct": 100, "issue": "overstated", "recommended_action": "fix_pct"}],
            "proposed": [{"source_req": "Art.21.2.c", "target_req": "A.8.16", "pct": 70, "ground_truth_cite": "ISO 27002:2022 8.16"}],
            "hypotheses": [{"source_req": "Art.21.2.d", "target_req": "A.5.7", "hypothesis_pct": 60, "reasoning": "keyword overlap", "uncertainty_reason": "no official crosswalk", "resolution_hint": "check ENISA 2025", "confidence_band": "low"}],
        },
        "verified": [
            {"source_req": "Art.21.2.a", "target_req": "A.5.1", "pct": 90, "kind": "confirmed", "verify": {"verdict": "hold", "evidence": "supported"}},
            {"source_req": "Art.21.2.c", "target_req": "A.8.16", "pct": 70, "kind": "proposed", "verify": {"verdict": "refute", "evidence": "clause does not cover"}},
        ],
    }]


def test_backlog_rows_classify_actions_and_review_flags():
    rows = synthesis.build_backlog(_sample_results())
    by_key = {(r["source_req"], r["target_req"]): r for r in rows}
    # confirmed + held -> add, no human review
    assert by_key[("Art.21.2.a", "A.5.1")]["action"] == "add"
    assert by_key[("Art.21.2.a", "A.5.1")]["human_review_needed"] == "no"
    # proposed + refuted -> demoted to human review
    assert by_key[("Art.21.2.c", "A.8.16")]["human_review_needed"] == "yes"
    # suspect -> fix action
    assert by_key[("Art.21.2.b", "A.5.99")]["action"] == "fix"
    # hypothesis -> human review with reasoning preserved
    h = by_key[("Art.21.2.d", "A.5.7")]
    assert h["human_review_needed"] == "yes"
    assert h["reasoning"] == "keyword overlap"
    assert h["hypothesis_pct"] == 60


def test_finding_table_has_one_row_per_framework():
    md = synthesis.build_finding_table(_sample_results())
    assert "| NIS2 |" in md
    assert "Framework" in md  # header present
```

- [ ] **Step 2: Run to verify fail**

Run: `python3 -m pytest scripts/quality/mapping_audit/tests/test_synthesis.py -v`
Expected: FAIL — `ModuleNotFoundError`

- [ ] **Step 3: Implement synthesis**

```python
# scripts/quality/mapping_audit/synthesis.py
"""Merge Layer-2 workflow results into a backlog CSV + finding-table markdown."""

BACKLOG_COLUMNS = [
    "framework_pair", "action", "source_req", "target_req", "proposed_pct",
    "ground_truth_cite", "confidence", "verify_verdict", "human_review_needed",
    "hypothesis_pct", "reasoning", "uncertainty_reason", "resolution_hint",
    "confidence_band",
]


def _verdict_index(verified):
    return {(v["source_req"], v["target_req"]): v.get("verify", {}) for v in verified or []}


def build_backlog(results):
    rows = []
    for r in results:
        fw = r["framework"]
        findings = r.get("findings", {}) or {}
        vidx = _verdict_index(r.get("verified", []))

        for f in findings.get("confirmed", []):
            v = vidx.get((f["source_req"], f["target_req"]), {})
            refuted = v.get("verdict") == "refute"
            rows.append({
                "framework_pair": fw, "action": "add",
                "source_req": f["source_req"], "target_req": f["target_req"],
                "proposed_pct": f["pct"], "ground_truth_cite": f["ground_truth_cite"],
                "confidence": "verified" if not refuted else "refuted",
                "verify_verdict": v.get("verdict", ""),
                "human_review_needed": "yes" if refuted else "no",
            })
        for f in findings.get("proposed", []):
            v = vidx.get((f["source_req"], f["target_req"]), {})
            refuted = v.get("verdict") == "refute"
            rows.append({
                "framework_pair": fw, "action": "add",
                "source_req": f["source_req"], "target_req": f["target_req"],
                "proposed_pct": f["pct"], "ground_truth_cite": f["ground_truth_cite"],
                "confidence": "verified" if not refuted else "refuted",
                "verify_verdict": v.get("verdict", ""),
                "human_review_needed": "yes" if refuted else "no",
            })
        for f in findings.get("suspect", []):
            rows.append({
                "framework_pair": fw,
                "action": "remove" if f.get("recommended_action") == "remove" else "fix",
                "source_req": f["source_req"], "target_req": f["target_req"],
                "proposed_pct": "", "ground_truth_cite": "", "confidence": "",
                "verify_verdict": "", "human_review_needed": "yes",
                "reasoning": f.get("issue", ""),
            })
        for f in findings.get("hypotheses", []):
            rows.append({
                "framework_pair": fw, "action": "add",
                "source_req": f["source_req"], "target_req": f["target_req"],
                "proposed_pct": "", "ground_truth_cite": "", "confidence": "hypothesis",
                "verify_verdict": "", "human_review_needed": "yes",
                "hypothesis_pct": f["hypothesis_pct"], "reasoning": f["reasoning"],
                "uncertainty_reason": f["uncertainty_reason"],
                "resolution_hint": f["resolution_hint"],
                "confidence_band": f["confidence_band"],
            })
    # normalize: every row has every column
    for row in rows:
        for col in BACKLOG_COLUMNS:
            row.setdefault(col, "")
    return rows


def write_backlog_csv(path, rows):
    import csv, os
    os.makedirs(os.path.dirname(path) or ".", exist_ok=True)
    with open(path, "w", encoding="utf-8", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=BACKLOG_COLUMNS)
        w.writeheader()
        for row in rows:
            w.writerow({c: row.get(c, "") for c in BACKLOG_COLUMNS})


def build_finding_table(results):
    lines = [
        "| Framework | Confirmed | Suspect | Proposed | Hypotheses | Refuted |",
        "|---|---|---|---|---|---|",
    ]
    for r in results:
        f = r.get("findings", {}) or {}
        refuted = sum(1 for v in (r.get("verified") or []) if v.get("verify", {}).get("verdict") == "refute")
        lines.append(
            f"| {r['framework']} | {len(f.get('confirmed', []))} | "
            f"{len(f.get('suspect', []))} | {len(f.get('proposed', []))} | "
            f"{len(f.get('hypotheses', []))} | {refuted} |"
        )
    return "\n".join(lines) + "\n"
```

- [ ] **Step 4: Add `--synthesize` mode to the CLI**

```python
# in scripts/quality/audit_eu_mappings.py, add to imports:
from scripts.quality.mapping_audit import synthesis
import json

# add an arg in main() before parse_args():
#   ap.add_argument("--synthesize", default="", help="path to workflow_results.json")
# and after the existing workbook block in main():
    if args.synthesize and os.path.exists(args.synthesize):
        with open(args.synthesize, encoding="utf-8") as fh:
            wf_results = json.load(fh)
        rows = synthesis.build_backlog(wf_results)
        synthesis.write_backlog_csv(os.path.join(args.out, "eu_mapping_backlog.csv"), rows)
        table = synthesis.build_finding_table(wf_results)
        os.makedirs("docs/compliance", exist_ok=True)
        with open("docs/compliance/eu-mapping-audit-findings.md", "w", encoding="utf-8") as fh:
            fh.write("# EU Mapping Audit — Findings\n\n")
            fh.write(f"_Generated {len(rows)} backlog rows._\n\n")
            fh.write(table)
        print(f"backlog rows: {len(rows)} -> var/audit/eu_mapping_backlog.csv")
        print("finding table -> docs/compliance/eu-mapping-audit-findings.md")
```

- [ ] **Step 5: Run tests + verify**

Run: `python3 -m pytest scripts/quality/mapping_audit/tests/ -v`
Expected: PASS (all tests across metrics/io/tisax/synthesis)

- [ ] **Step 6: Commit**

```bash
git add scripts/quality/mapping_audit/synthesis.py scripts/quality/audit_eu_mappings.py scripts/quality/mapping_audit/tests/test_synthesis.py
git commit -m "feat(audit): synthesis — backlog CSV + finding-table markdown"
```

---

## Task 10: End-to-end run + artifacts

**Files:**
- Create: `docs/compliance/eu-mapping-audit-findings.md` (generated)
- Runtime: `var/audit/*` (not committed)

- [ ] **Step 1: Run Layer-1**

Run:
```bash
python3 scripts/quality/audit_eu_mappings.py --workbook /Users/michaelbanda/Downloads/ISA6_DE_6.0.2.xlsx
```
Expected: dossiers for NIS2/DORA/GDPR + TISAX catalog + candidate CSV in `var/audit/`.

- [ ] **Step 2: Run Layer-2 workflow**

Build the `args` array from the dossiers and run the workflow (via the `Workflow` tool, `scriptPath: scripts/workflows/eu_mapping_audit.workflow.js`). One entry per framework:
```json
[
  {"framework":"NIS2","specialist":"isms-specialist","groundTruth":"ENISA NIS2 Implementation Guidance 2024 Annex C; ISO 27002:2022","dossierJson":"<contents of var/audit/nis2_dossier.json>"},
  {"framework":"DORA","specialist":"isms-specialist","groundTruth":"EU 2022/2554 + EBA/EIOPA/ESMA RTS 2024; ISO 27002:2022","dossierJson":"<contents of var/audit/dora_dossier.json>"},
  {"framework":"GDPR","specialist":"dpo-specialist","groundTruth":"EU 2016/679; ISO 27701:2019 Annex D","dossierJson":"<contents of var/audit/gdpr_dossier.json>"},
  {"framework":"TISAX","specialist":"isms-specialist","groundTruth":"VDA-ISA 6.0.2 workbook (var/audit/tisax_workbook_mappings_candidate.csv) + ISO 27002:2022","dossierJson":"<contents of var/audit/tisax_catalog.json>"}
]
```
Save the workflow's returned JSON to `var/audit/workflow_results.json`.

- [ ] **Step 3: Run synthesis**

Run:
```bash
python3 scripts/quality/audit_eu_mappings.py --synthesize var/audit/workflow_results.json
```
Expected: `var/audit/eu_mapping_backlog.csv` + `docs/compliance/eu-mapping-audit-findings.md` written, row count printed.

- [ ] **Step 4: Review the backlog manually**

Open `var/audit/eu_mapping_backlog.csv`. Verify: every `human_review_needed=yes` row has a non-empty `reasoning` (and hypotheses have `hypothesis_pct`+`uncertainty_reason`+`resolution_hint`). Spot-check 3 `confirmed`/`add` rows against their `ground_truth_cite` — open the cited source, confirm the clause says what is claimed. This is the "wirklichkeitsnah" acceptance gate (spec §9).

- [ ] **Step 5: Commit the tracked finding table**

```bash
git add -f docs/compliance/eu-mapping-audit-findings.md
git commit -m "docs(compliance): EU mapping audit finding table"
```

- [ ] **Step 6: Hand off Wave 1**

The backlog CSV drives the three waves (spec §8). Wave 1 (quality): apply `action=fix`/`remove` rows + provenance backfill + TISAX candidate re-import via `ImportMappingCsvCommand`. Waves 2-3 (breadth/gaps) follow. Each wave is a separate change; re-run `audit_eu_mappings.py` after each to confirm acceptance criteria (coverage up, suspects → 0, provenance complete).

---

## Self-Review

**Spec coverage:**
- §3 anti-hallucination spine → Task 8 hard rules (ground-truth cite, hypotheses, default-skeptic) + Task 8 stage B (adversarial verify) + Task 9 human-review demotion. ✓
- §4 Layer 1 metrics → Tasks 1-2 (coverage/symmetry/provenance/suspects), Task 7 CLI. ✓
- §4 Layer 2 specialist table → Task 8 `args` + Task 10 step 2 persona assignment. ✓
- §5 TISAX workbook (both columns) → Tasks 5-6 (references + evidence parse) + Task 7 candidate CSV. ✓
- §6 reasoned hypotheses (5 fields) → Task 8 FINDINGS_SCHEMA hypotheses + Task 9 backlog preservation. ✓
- §7 pipeline + no-barrier + structured output → Task 8 `pipeline(...)` + schemas. ✓
- §8 backlog CSV (14 cols) + finding table + waves → Task 9 + Task 10 step 6. ✓
- §9 acceptance gate → Task 10 step 4 manual review. ✓
- Note: §4 bidirectional symmetry has a pure fn (Task 2) but no EU reverse-direction CSV exists yet, so the CLI (Task 7) does not yet call it per-pair. Symmetry becomes active in Wave 3 when direct/reverse EU pairs are added. Flagged, not a gap — the function is built and tested ahead of its data.

**Placeholder scan:** No TBD/TODO. All code steps show complete code. The workflow `args` in Task 10 uses `<contents of ...>` placeholders — these are runtime data injection points, not code placeholders (the operator pastes the dossier JSON), which is the intended hand-off.

**Type consistency:** `coverage()` returns `{mapped_count, catalog_count, coverage_pct, unmapped}` — consumed identically in Task 7. `parse_references` returns `[(label, clause)]` — consumed in Task 6 `build_records` and Task 7 `write_tisax_candidate`. Backlog columns defined once in `BACKLOG_COLUMNS` and reused. FINDINGS_SCHEMA keys (`confirmed/suspect/proposed/hypotheses`) match synthesis `build_backlog` reads. ✓

---

## Notes / Risks

- **Catalog completeness:** the manifest (Task 3) is the audited baseline, not gospel. Task 8 instructs specialists to flag missing canonical requirements — extend the manifest when they do.
- **xlsx reader fragility:** the stdlib OOXML reader (Task 6) handles the standard case; the shared-string `t="s"` detection is heuristic. The Task 6 step-5 smoke test against the real workbook is a hard gate — if references don't extract, fix before proceeding.
- **Token cost:** Task 10 step 2 runs a multi-agent workflow with WebSearch — this is the expensive step and the user has explicitly opted in.
- **No engine changes:** entities/services untouched; output flows through the existing `ImportMappingCsvCommand`.
