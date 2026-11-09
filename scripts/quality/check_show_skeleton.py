#!/usr/bin/env python3
"""
check_show_skeleton.py — Gate 75: detail pages use the one detail skeleton.

UI wave E: every detail page (`templates/**/{show,detail,view}.html.twig`; the last two since
T2) has the status
band and the links block (evidence chain Asset → Risk → Control → Evidence →
Incident), then its sections, history and comments. Pages not
migrated yet are listed in the baseline and may only shrink; a migrated page
that leaves the baseline is reported as stale.

A page on the skeleton must not keep the old link matrix
(`_entity_link_matrix`) next to the new links block.

A `show.html.twig` that does not show one record (a workflow definition read
from YAML, a session aggregate) carries `{# show-skeleton: no record — <why> #}`
and is skipped; the reason is required. A page with that marker that still renders
record-only parts (comment thread, status band, running approval, audit history) is a
record page and fails (T2: the marker used to exempt any page).

    python3 scripts/quality/check_show_skeleton.py --baseline scripts/quality/baselines/show_skeleton.txt
    python3 scripts/quality/check_show_skeleton.py --write-baseline scripts/quality/baselines/show_skeleton.txt
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TEMPLATES = ROOT / "templates"
SKELETON = "_components/_fa_entity_links.html.twig"
# The reason must be a real sentence, not a placeholder.
NO_RECORD = re.compile(r"\{#\s*show-skeleton:\s*no record\s*[—-]\s*\S.{9,}?#\}")
RE_COMMENT = re.compile(r"\{#.*?#\}", re.S)
# Parts of the skeleton that must be USED, not merely imported or named in a comment:
# the status band macro call and the history block (both on every record page).
PARTS = (
    re.compile(r"_fa_status_band\s*\.\s*\w+\s*\("),
    re.compile(r"_fa_show_history"),
)
OLD_MATRIX = "_entity_link_matrix"
# Parts only a page about one record renders.
RECORD_ONLY = re.compile(r"_isms_comment_thread|_fa_status_band\s*\.|\bworkflow_state\(|\bshow_history\(|\bentity_links\(")
PAGE_NAMES = ("show.html.twig", "detail.html.twig", "view.html.twig")
INCLUDED = re.compile(r"""['"]([\w/.-]+\.twig)['"]""")
EXCLUDE_DIRS = {"dev", "emails", "pdf", "bundles"}


def has_old_matrix(text: str, depth: int = 0) -> bool:
    """The old link matrix in the page or in a page-local partial it includes."""
    if OLD_MATRIX in text:
        return True
    if depth >= 2:
        return False
    for ref in INCLUDED.findall(text):
        part = TEMPLATES / ref
        if not ref.startswith(("_components/", "_macros/")) and part.is_file():
            if has_old_matrix(RE_COMMENT.sub("", part.read_text(encoding="utf-8")), depth + 1):
                return True
    return False


def show_templates() -> list[Path]:
    out = []
    for p in sorted(q for name in PAGE_NAMES for q in TEMPLATES.rglob(name)):
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
        raw = p.read_text(encoding="utf-8")
        if NO_RECORD.search(raw):
            if RECORD_ONLY.search(RE_COMMENT.sub("", raw)):
                incomplete.append(f"{rel} (marked 'no record' but renders record-only parts)")
            continue
        text = RE_COMMENT.sub("", raw)  # a comment naming the skeleton is not using it
        if SKELETON in text:
            migrated += 1
            if has_old_matrix(text) or not all(p_.search(text) for p_ in PARTS):
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
        print("check_show_skeleton: incomplete skeleton (needs the status band call, the history block, no old link matrix):")
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
