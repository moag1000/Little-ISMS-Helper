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


def test_read_manifest_strips_inline_comments(tmp_path):
    man = tmp_path / "m.yaml"
    man.write_text(
        "# file header comment\n"
        "DORA:\n"
        "  source: eu_2022_2554              # ICT-risk-relevant articles\n"
        "  requirements:\n"
        "    - Art.19                         # reporting (check: may be unmapped)\n"
        "    - Art.28\n",
        encoding="utf-8",
    )
    cat = audit_io.read_catalog_manifest(str(man))
    assert cat["DORA"]["source"] == "eu_2022_2554"
    assert cat["DORA"]["requirements"] == ["Art.19", "Art.28"]


def test_read_manifest_ignores_orphan_indented_lines_before_first_header(tmp_path):
    man = tmp_path / "m.yaml"
    man.write_text(
        "  source: stray\n"          # orphan indented line before any header
        "  - orphan-item\n"
        "NIS2:\n  source: enisa\n  requirements:\n    - Art.21.2.a\n",
        encoding="utf-8",
    )
    cat = audit_io.read_catalog_manifest(str(man))  # must not raise KeyError
    assert cat["NIS2"]["requirements"] == ["Art.21.2.a"]
    assert None not in cat


def test_write_dossier_roundtrips(tmp_path):
    out = tmp_path / "sub" / "nis2_iso27001_dossier.json"
    audit_io.write_dossier(str(out), {"pair": "NIS2_ISO27001", "coverage_pct": 50.0})
    loaded = json.loads(out.read_text(encoding="utf-8"))
    assert loaded["coverage_pct"] == 50.0
