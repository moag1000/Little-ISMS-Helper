#!/usr/bin/env python3
"""Layer-1 EU mapping audit CLI.

Usage:
  python3 scripts/quality/audit_eu_mappings.py \
      --mappings-dir fixtures/mappings/public \
      --manifest fixtures/audit/eu_catalog_manifest.yaml \
      --workbook /Users/michaelbanda/Downloads/ISA6_DE_6.0.2.xlsx \
      --out var/audit
Outputs per pair: var/audit/<framework>_dossier.json
Plus (LOCAL audit artifacts, var/ is gitignored): var/audit/tisax_catalog.json,
var/audit/tisax_workbook_mappings_candidate.csv
"""
import argparse
import csv
import os
import sys

# Allow running as `python3 scripts/quality/audit_eu_mappings.py` from project root
# without needing an explicit PYTHONPATH=. prefix.
_project_root = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
if _project_root not in sys.path:
    sys.path.insert(0, _project_root)

from scripts.quality.mapping_audit import io as audit_io
from scripts.quality.mapping_audit import metrics
from scripts.quality.mapping_audit import tisax_extract as tx

# Which CSV files feed which EU target framework (forward direction into ISO/other).
EU_PAIRS = {
    "NIS2": ["nis2_iso27001_v1.csv"],
    "DORA": ["dora_iso27001_v1.csv", "dora_iso27005_v1.csv", "dora_iso22301_v1.csv"],
    "GDPR": ["gdpr_iso27701_v1.csv"],
}


def _pct_int(row):
    try:
        return int(row.get("mapping_percentage") or 0)
    except ValueError:
        return 0


def build_dossier(framework, csv_files, mappings_dir, catalog):
    rows = []
    for f in csv_files:
        path = os.path.join(mappings_dir, f)
        if os.path.exists(path):
            rows.extend(audit_io.read_mapping_csv(path))
    cat_reqs = catalog.get(framework, {}).get("requirements", [])
    # a requirement is "covered" if it appears as source_requirement_id in >=1 row
    source_view = [{"target_requirement_id": r["source_requirement_id"]} for r in rows]
    cov = metrics.coverage(source_view, cat_reqs)
    prov = metrics.provenance_completeness(rows)
    susp = metrics.suspects(rows)
    pct_values = [_pct_int(r) for r in rows]
    return {
        "framework": framework,
        "csv_files": csv_files,
        "row_count": len(rows),
        "coverage": cov,
        "provenance": prov,
        "suspects": susp,
        "pct_histogram": {
            "weak_0_49": sum(1 for p in pct_values if p < 50),
            "partial_50_99": sum(1 for p in pct_values if 50 <= p < 100),
            "full_100": sum(1 for p in pct_values if p == 100),
            "exceeds_101_plus": sum(1 for p in pct_values if p > 100),
        },
    }


def write_tisax_candidate(records, out_dir):
    # LOCAL audit artifact only (out_dir is var/, gitignored). Any later import into
    # shipped fixtures/ must carry criterion-number -> clause mappings ONLY (no evidence prose).
    path = os.path.join(out_dir, "tisax_workbook_mappings_candidate.csv")
    with open(path, "w", encoding="utf-8", newline="") as fh:
        w = csv.writer(fh)
        w.writerow(["source_framework", "source_requirement_id", "target_framework",
                    "target_requirement_id", "source_catalog", "evidence_hint"])
        for rec in records:
            ev = " | ".join(rec["evidence"])
            for std_label, clause in rec["references"]:
                code = tx.normalize_standard(std_label)
                if code is None:
                    continue
                w.writerow(["TISAX", rec["criterion"], code, clause,
                            "vda_isa_6.0.2_workbook", ev])
    return path


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--mappings-dir", default="fixtures/mappings/public")
    ap.add_argument("--manifest", default="fixtures/audit/eu_catalog_manifest.yaml")
    ap.add_argument("--workbook", default="")
    ap.add_argument("--out", default="var/audit")
    args = ap.parse_args()

    os.makedirs(args.out, exist_ok=True)
    catalog = audit_io.read_catalog_manifest(args.manifest)

    for framework, csv_files in EU_PAIRS.items():
        dossier = build_dossier(framework, csv_files, args.mappings_dir, catalog)
        out = os.path.join(args.out, f"{framework.lower()}_dossier.json")
        audit_io.write_dossier(out, dossier)
        c = dossier["coverage"]
        print(f"{framework}: coverage {c['coverage_pct']}% "
              f"({c['mapped_count']}/{c['catalog_count']}), "
              f"{len(dossier['suspects'])} suspect, "
              f"provenance {dossier['provenance']['complete_pct']}%")

    if args.workbook and os.path.exists(args.workbook):
        recs = tx.extract_workbook(args.workbook)
        tisax_cat = sorted({r["criterion"] for r in recs})
        audit_io.write_dossier(os.path.join(args.out, "tisax_catalog.json"),
                               {"framework": "TISAX", "requirements": tisax_cat,
                                "criterion_count": len(tisax_cat)})
        cand = write_tisax_candidate(recs, args.out)
        print(f"TISAX: {len(tisax_cat)} criteria, candidate rows -> {cand}")


if __name__ == "__main__":
    main()
