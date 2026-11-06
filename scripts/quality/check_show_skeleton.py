#!/usr/bin/env python3
"""
check_show_skeleton.py — Gate 75: detail pages use the one detail skeleton.

UI wave E: every detail page (`templates/**/show.html.twig`) has the status
band and the links block (evidence chain Asset → Risk → Control → Evidence →
Incident), then its sections, history and comments. Pages not
migrated yet are listed in the baseline and may only shrink; a migrated page
that leaves the baseline is reported as stale.

A page on the skeleton must not keep the old link matrix
(`_entity_link_matrix`) next to the new links block.

    python3 scripts/quality/check_show_skeleton.py --baseline scripts/quality/baselines/show_skeleton.txt
    python3 scripts/quality/check_show_skeleton.py --write-baseline scripts/quality/baselines/show_skeleton.txt
"""
from __future__ import annotations

import argparse
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TEMPLATES = ROOT / "templates"
SKELETON = "_components/_fa_entity_links.html.twig"
PARTS = ("_fa_status_band",)
EXCLUDE_DIRS = {"dev", "emails", "pdf", "bundles"}


def show_templates() -> list[Path]:
    out = []
    for p in sorted(TEMPLATES.rglob("show.html.twig")):
        rel = p.relative_to(TEMPLATES)
        if rel.parts[0] in EXCLUDE_DIRS:
            continue
        out.append(p)
    return out


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--baseline")
    ap.add_argument("--write-baseline")
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    unmigrated, incomplete = [], []
    migrated = 0
    for p in show_templates():
        rel = str(p.relative_to(ROOT))
        text = p.read_text(encoding="utf-8")
        if SKELETON in text:
            migrated += 1
            if "_entity_link_matrix" in text or not all(p_ in text for p_ in PARTS):
                incomplete.append(rel)
        else:
            unmigrated.append(rel)

    if args.write_baseline:
        Path(args.write_baseline).write_text(
            "# Detail pages not yet on the detail skeleton (Gate 75). May only shrink.\n"
            + "".join(f"{r}\n" for r in unmigrated),
            encoding="utf-8",
        )
        print(f"check_show_skeleton: wrote {len(unmigrated)} entries")
        return 0

    baseline: set[str] = set()
    if args.baseline:
        for line in Path(args.baseline).read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if line and not line.startswith("#"):
                baseline.add(line)

    new = [r for r in unmigrated if r not in baseline]
    stale = sorted(baseline - set(unmigrated))
    failed = False
    if new:
        failed = True
        print(f"check_show_skeleton: {len(new)} detail page(s) not on {SKELETON}:")
        for r in new:
            print(f"  FAIL {r}")
    if stale:
        failed = True
        print(f"check_show_skeleton: {len(stale)} stale baseline entr(y/ies) — remove them:")
        for r in stale:
            print(f"  STALE {r}")
    if incomplete:
        failed = True
        print("check_show_skeleton: incomplete skeleton (needs the status band, no old link matrix):")
        for r in incomplete:
            print(f"  FAIL {r}")
    if failed:
        return 1
    if not args.quiet:
        print(f"check_show_skeleton: OK — {migrated} detail pages on the skeleton, "
              f"{len(unmigrated)} baselined.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
