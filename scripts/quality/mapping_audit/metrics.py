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


def bidirectional_symmetry(forward, reverse):
    """Share of forward A->B pairs that have a matching reverse B->A pair.

    DORMANT (intentional): no reverse-direction EU CSVs exist yet, so the Layer-1
    CLI does not call this per-pair. It activates in Wave 3 when direct/reverse EU
    pairs are added (see spec §4 + plan self-review). Built+tested ahead of data.
    """
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
            pct = int(float(r.get("mapping_percentage") or 0))  # tolerate "100.0"
        except (ValueError, TypeError):
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
