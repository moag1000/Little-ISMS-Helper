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
        # tolerate externally-supplied / hand-edited workflow_results.json:
        # missing keys degrade to "" rather than raising an opaque KeyError.
        fw = r.get("framework", "")
        findings = r.get("findings", {}) or {}
        vidx = _verdict_index(r.get("verified", []))

        for f in findings.get("confirmed", []):
            v = vidx.get((f.get("source_req"), f.get("target_req")), {})
            refuted = v.get("verdict") == "refute"
            rows.append({
                "framework_pair": fw, "action": "add",
                "source_req": f.get("source_req", ""), "target_req": f.get("target_req", ""),
                "proposed_pct": f.get("pct", ""), "ground_truth_cite": f.get("ground_truth_cite", ""),
                "confidence": "verified" if not refuted else "refuted",
                "verify_verdict": v.get("verdict", ""),
                "human_review_needed": "yes" if refuted else "no",
            })
        for f in findings.get("proposed", []):
            v = vidx.get((f.get("source_req"), f.get("target_req")), {})
            refuted = v.get("verdict") == "refute"
            rows.append({
                "framework_pair": fw, "action": "add",
                "source_req": f.get("source_req", ""), "target_req": f.get("target_req", ""),
                "proposed_pct": f.get("pct", ""), "ground_truth_cite": f.get("ground_truth_cite", ""),
                "confidence": "verified" if not refuted else "refuted",
                "verify_verdict": v.get("verdict", ""),
                "human_review_needed": "yes" if refuted else "no",
            })
        for f in findings.get("suspect", []):
            rows.append({
                "framework_pair": fw,
                "action": "remove" if f.get("recommended_action") == "remove" else "fix",
                "source_req": f.get("source_req", ""), "target_req": f.get("target_req", ""),
                "proposed_pct": "", "ground_truth_cite": "", "confidence": "",
                "verify_verdict": "", "human_review_needed": "yes",
                "reasoning": f.get("issue", ""),
            })
        for f in findings.get("hypotheses", []):
            rows.append({
                "framework_pair": fw, "action": "add",
                "source_req": f.get("source_req", ""), "target_req": f.get("target_req", ""),
                "proposed_pct": "", "ground_truth_cite": "", "confidence": "hypothesis",
                "verify_verdict": "", "human_review_needed": "yes",
                "hypothesis_pct": f.get("hypothesis_pct", ""), "reasoning": f.get("reasoning", ""),
                "uncertainty_reason": f.get("uncertainty_reason", ""),
                "resolution_hint": f.get("resolution_hint", ""),
                "confidence_band": f.get("confidence_band", ""),
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
        fw_name = r.get("framework", "")
        f = r.get("findings", {}) or {}
        refuted = sum(1 for v in (r.get("verified") or []) if v.get("verify", {}).get("verdict") == "refute")
        lines.append(
            f"| {fw_name} | {len(f.get('confirmed', []))} | "
            f"{len(f.get('suspect', []))} | {len(f.get('proposed', []))} | "
            f"{len(f.get('hypotheses', []))} | {refuted} |"
        )
    return "\n".join(lines) + "\n"
