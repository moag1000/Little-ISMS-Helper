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
    # build_records returns raw labels; normalization is a downstream concern
    assert ("ISO 27001:2022", "A.5.1") in rec["references"]
    assert rec["evidence"] == ["Richtlinie", "Intranet"]
