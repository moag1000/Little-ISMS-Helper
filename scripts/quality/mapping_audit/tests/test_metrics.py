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


def test_suspects_tolerates_float_formatted_percentage():
    rows = [
        {"mapping_percentage": "100.0", "confidence": "low", "rationale": "x", "target_requirement_id": "A.5.1", "source_requirement_id": "Art.21.2.a"},
    ]
    result = metrics.suspects(rows)
    assert len(result) == 1
    assert result[0]["mapping_percentage"] == 100  # "100.0" not dropped to 0
