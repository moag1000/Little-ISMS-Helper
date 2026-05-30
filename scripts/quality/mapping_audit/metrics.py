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
