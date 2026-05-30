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
