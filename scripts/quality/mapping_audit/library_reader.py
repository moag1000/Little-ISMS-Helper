"""Reader for library YAML cross-framework mappings (fixtures/library/mappings/*.yaml).

Stdlib-only (no PyYAML) to keep the audit toolchain dependency-free. We only need
the flat per-entry fields (source/target/relationship/confidence) + the library
header (frameworks + provenance URL); the multi-line rationale/gap_warning/
audit_evidence_hint block scalars are intentionally ignored for the audit metrics.

The library format uses a `relationship` enum instead of a numeric percentage, so
`relationship_to_pct` maps it onto the 0-100 scale the rest of the toolchain uses.
"""
import re

# relationship enum -> percentage, so library mappings flow through the same metrics.
_REL_PCT = {
    "equivalent": 100,
    "superset": 90,
    "subset": 75,
    "partial_overlap": 60,
    "related": 40,
    "reference": 70,  # anchor-style entries (targets-list schema, no relationship)
}


def relationship_to_pct(rel):
    return _REL_PCT.get((rel or "").strip().lower(), 50)


def _header_field(text, key):
    m = re.search(r"^\s*%s:\s*'?\"?([^'\"\n]+)'?\"?\s*$" % re.escape(key), text, re.M)
    return m.group(1).strip() if m else ""


def parse_library_yaml(text):
    """Parse a library mapping YAML string into the toolchain row format.

    Returns (meta, rows) where meta has source_framework/target_framework/
    provenance_url, and each row has source_requirement_id, target_requirement_id,
    relationship, confidence, mapping_percentage, source_catalog, provenance_url.
    """
    header = text.split("mappings:", 1)[0]
    meta = {
        "source_framework": _header_field(header, "source_framework"),
        "target_framework": _header_field(header, "target_framework"),
        "provenance_url": _header_field(header, "primary_source_url"),
        "library_id": _header_field(header, "id"),
    }
    body = text.split("mappings:", 1)[1] if "mappings:" in text else ""
    # split into entry blocks on the "- source:" boundary
    blocks = re.split(r"\n\s*-\s+source:", body)
    rows = []
    for blk in blocks:
        if "target:" not in blk and "targets:" not in blk:
            continue
        msrc = re.match(r"\s*'?\"?([^'\"\n]+)'?\"?", blk)
        src = msrc.group(1).strip() if msrc else ""
        # first occurrence of each field in the block is the real field (rationale follows)
        def first(key):
            m = re.search(r"^\s+%s:\s*'?\"?([^'\"\n|]+)'?\"?\s*$" % re.escape(key), blk, re.M)
            return m.group(1).strip() if m else ""
        rel = first("relationship")
        conf = first("confidence")
        # two schemas: singular `target: 'X'` (+relationship) OR plural `targets: ['A','B']`
        tgt = first("target")
        if not tgt:
            # plural `targets:` — inline `['A','B']` or a block list of `- 'X'` lines
            mt_inline = re.search(r"^\s+targets:\s*\[([^\]]*)\]", blk, re.M)
            if mt_inline:
                tgt_list = [t.strip().strip("'\"") for t in mt_inline.group(1).split(",") if t.strip()]
            else:
                mblock = re.search(r"^(\s+)targets:\s*$(.*?)(?=^\1\S|\Z)", blk, re.M | re.S)
                body_t = mblock.group(2) if mblock else ""
                tgt_list = [m.strip().strip("'\"") for m in re.findall(r"^\s+-\s+(.+)$", body_t, re.M)]
            rel = rel or "reference"  # these anchor-style entries carry no relationship
        else:
            tgt_list = [tgt]
        if not src or not tgt_list:
            continue
        # the multi-line rationale block is not captured, but record its PRESENCE so
        # the suspect heuristic (high-pct + no rationale) does not false-flag every
        # 'equivalent' (=100%) library row that does have a rationale in the YAML.
        has_rationale = bool(re.search(r"^\s+rationale:\s*", blk, re.M))
        for tgt in tgt_list:
            rows.append({
                "source_framework": meta["source_framework"],
                "source_requirement_id": src,
                "target_framework": meta["target_framework"],
                "target_requirement_id": tgt,
                "relationship": rel,
                "confidence": conf or "medium",
                "mapping_percentage": str(relationship_to_pct(rel)),
                "source_catalog": meta["library_id"],
                "provenance_url": meta["provenance_url"],
                "rationale": "present" if has_rationale else "",
            })
    return meta, rows


def read_library_mapping(path):
    with open(path, encoding="utf-8") as fh:
        return parse_library_yaml(fh.read())
