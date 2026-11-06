#!/usr/bin/env python3
"""
check_list_skeleton.py — Gate 73: list pages use the one list skeleton.

UI wave C: every list page (`templates/**/index.html.twig`) renders through
`_components/_fa_list_page.html.twig` (filter bar, table card, pager, empty
state in a fixed order). Lists not migrated yet are listed in the baseline and
may only shrink; a migrated list that leaves the baseline is reported as stale.

A list on the skeleton must not carry KPI tiles (`_fa_feature_card`): numbers
belong in a summary line of chips, the tiles on a dashboard.

    python3 scripts/quality/check_list_skeleton.py --baseline scripts/quality/baselines/list_skeleton.txt
    python3 scripts/quality/check_list_skeleton.py --write-baseline scripts/quality/baselines/list_skeleton.txt
"""
from __future__ import annotations

import argparse
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TEMPLATES = ROOT / "templates"
SKELETON = "_components/_fa_list_page.html.twig"
# Not list pages despite the name: dashboards, hubs, wizards, landing pages.
EXCLUDE_DIRS = {"dev", "emails", "pdf", "bundles"}


def list_templates() -> list[Path]:
    out = []
    for p in sorted(TEMPLATES.rglob("index.html.twig")):
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

    unmigrated, tiles = [], []
    migrated = 0
    for p in list_templates():
        rel = str(p.relative_to(ROOT))
        text = p.read_text(encoding="utf-8")
        if SKELETON in text:
            migrated += 1
            if "_fa_feature_card" in text:
                tiles.append(rel)
        else:
            unmigrated.append(rel)

    if args.write_baseline:
        Path(args.write_baseline).write_text(
            "# List pages not yet on _fa_list_page (Gate 73). May only shrink.\n"
            + "".join(f"{r}\n" for r in unmigrated),
            encoding="utf-8",
        )
        print(f"check_list_skeleton: wrote {len(unmigrated)} entries")
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
        print(f"check_list_skeleton: {len(new)} list page(s) not on {SKELETON}:")
        for r in new:
            print(f"  FAIL {r}")
    if stale:
        failed = True
        print(f"check_list_skeleton: {len(stale)} stale baseline entr(y/ies) — remove them:")
        for r in stale:
            print(f"  STALE {r}")
    if tiles:
        failed = True
        print("check_list_skeleton: KPI tiles in a list page (use summary chips):")
        for r in tiles:
            print(f"  FAIL {r}")
    if failed:
        return 1
    if not args.quiet:
        print(f"check_list_skeleton: OK — {migrated} list pages on the skeleton, "
              f"{len(unmigrated)} baselined.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
