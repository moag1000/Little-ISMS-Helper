from scripts.quality.mapping_audit import library_reader as lr


SAMPLE = """schema_version: '1.1'
library:
  id: 'cra_to_nis2-art21_v1.0'
  source_framework: 'EU-CRA'
  target_framework: 'NIS2'
  provenance:
    primary_source_url: 'https://eur-lex.europa.eu/eli/reg/2024/2847/oj'
mappings:
  - source: 'CRA-Annex-I-1.1'
    target: '21.2.e'
    relationship: 'equivalent'
    confidence: 'high'
    rationale: |
      Multi-line text with target: tricky and relationship: words inside.
    gap_warning: |
      More text.
  - source: 'CRA-Annex-I-1.2'
    target: '21.2.d'
    relationship: 'partial_overlap'
    confidence: 'medium'
    rationale: 'short'
"""


def test_relationship_to_pct_scale():
    assert lr.relationship_to_pct("equivalent") == 100
    assert lr.relationship_to_pct("partial_overlap") == 60
    assert lr.relationship_to_pct("related") == 40
    assert lr.relationship_to_pct("unknown") == 50


def test_parse_header_and_entries():
    meta, rows = lr.parse_library_yaml(SAMPLE)
    assert meta["source_framework"] == "EU-CRA"
    assert meta["target_framework"] == "NIS2"
    assert meta["provenance_url"].endswith("2024/2847/oj")
    assert len(rows) == 2


def test_first_field_not_polluted_by_multiline_rationale():
    # the rationale text contains "target:" and "relationship:" words — must not leak
    meta, rows = lr.parse_library_yaml(SAMPLE)
    r0 = rows[0]
    assert r0["source_requirement_id"] == "CRA-Annex-I-1.1"
    assert r0["target_requirement_id"] == "21.2.e"
    assert r0["relationship"] == "equivalent"
    assert r0["mapping_percentage"] == "100"
    assert r0["confidence"] == "high"
    assert r0["provenance_url"].endswith("2024/2847/oj")
