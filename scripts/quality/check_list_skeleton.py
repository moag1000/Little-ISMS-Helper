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
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TEMPLATES = ROOT / "templates"
SKELETON = "_components/_fa_list_page.html.twig"
# Not list pages despite the name: dashboards, hubs, wizards, landing pages.
EXCLUDE_DIRS = {"dev", "emails", "pdf", "bundles"}
# index.html.twig files that are not list pages (hubs, dashboards, wizards,
# settings forms, status pages). Kept here with the reason, not in the
# baseline: the baseline is for lists that still have to move.
NOT_LISTS_FILE = ROOT / "scripts" / "quality" / "baselines" / "list_skeleton_not_lists.txt"


def not_lists() -> set[str]:
    if not NOT_LISTS_FILE.exists():
        return set()
    out = set()
    for line in NOT_LISTS_FILE.read_text(encoding="utf-8").splitlines():
        line = line.split("#", 1)[0].strip()
        if line:
            out.add(line.split()[0])
    return out


RE_COMMENT = re.compile(r"\{#.*?#\}", re.S)
# Every way a KPI tile gets onto a page: the feature-card macro, the KPI tile macro, the legacy classes.
TILE = re.compile(r"_fa_feature_card|_kpi_tile|fa-feature-card|\bkpi-card\b|variant\s*:\s*['\"]kpi['\"]")
INCLUDED = re.compile(r"""['"]([\w/.-]+\.twig)['"]""")


def code(text: str) -> str:
    """Template text without {# comments #} (a comment naming the skeleton is not using it)."""
    return RE_COMMENT.sub("", text)


def has_tiles(text: str, seen: frozenset[str] = frozenset(), depth: int = 0) -> bool:
    """KPI tiles in the page itself or in a partial it includes (two levels deep)."""
    if TILE.search(text):
        return True
    if depth >= 2:
        return False
    for ref in INCLUDED.findall(text):
        part = TEMPLATES / ref
        # page-local partials only; shared components/macros mention tiles in their own docs
        if ref not in seen and not ref.startswith(("_components/", "_macros/")) and part.is_file():
            if has_tiles(code(part.read_text(encoding="utf-8")), seen | {ref}, depth + 1):
                return True
    return False


def list_templates() -> list[Path]:
    out = []
    skip = not_lists()
    for p in sorted(TEMPLATES.rglob("index.html.twig")):
        rel = p.relative_to(TEMPLATES)
        if rel.parts[0] in EXCLUDE_DIRS or str(p.relative_to(ROOT)) in skip:
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
        text = code(p.read_text(encoding="utf-8"))
        if SKELETON in text:
            migrated += 1
            if has_tiles(text):
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

    missing = sorted(r for r in not_lists() if not (ROOT / r).exists())
    if missing:
        print(f"check_list_skeleton: {len(missing)} entr(y/ies) in {NOT_LISTS_FILE.name} without a file:")
        for r in missing:
            print(f"  STALE {r}")
        return 1

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
