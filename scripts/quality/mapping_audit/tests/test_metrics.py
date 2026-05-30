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
