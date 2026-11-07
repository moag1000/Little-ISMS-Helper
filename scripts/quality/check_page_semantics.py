#!/usr/bin/env python3
"""
check_page_semantics.py — Gate 78: page semantics and one status vocabulary.

UI wave G. Four rules:
- nested-main: base.html.twig renders `<main id="main-content">`; a template
  that extends it and opens its own `<main>` nests landmarks (screen readers
  announce two main regions).
- extra-h1: the page header macro renders the page's `<h1>`; a template that
  uses it and adds another `<h1>` has two.
- status-map: a local `{% set statusColors = {...} %}` (or status_tone map)
  instead of _components/_status_vocabulary.html.twig / _fa_status_chip.
- tooltip-unreachable: a `data-bs-toggle="tooltip"` on an element that is not
  focusable (no tabindex, not a/button/input) can only be read by mouse.

Templates that are documents of their own (layouts, PDFs, mails, standalone
pages without `{% extends %}`) may have their own `<main>`/`<h1>`.
Remaining offenders are baselined and may only shrink.

    python3 scripts/quality/check_page_semantics.py --baseline scripts/quality/baselines/page_semantics.txt
    python3 scripts/quality/check_page_semantics.py --write-baseline scripts/quality/baselines/page_semantics.txt
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TEMPLATES = ROOT / "templates"
# Layouts whose children inherit a <main>.
MAIN_LAYOUTS = re.compile(r"\{%\s*extends\s+'(base|admin/layout)\.html\.twig'")
PAGE_HEADER = re.compile(r"_fa_page_header\.(render|header)\(")
MAIN_OPEN = re.compile(r"<main[\s>]")
H1 = re.compile(r"<h1[\s>]")
STATUS_MAP = re.compile(r"\{%\s*set\s+_?(status_?[cC]olors|status_?[tT]ones?)\s*=\s*\{")
TOOLTIP = re.compile(r"<(\w+)\b[^>]*data-bs-toggle=\"tooltip\"[^>]*>", re.S)
FOCUSABLE = {"a", "button", "input", "select", "textarea"}
# Print-only copies and JS print windows are not part of the page outline.
PRINT_ONLY = re.compile(r"<(div|header|section)[^>]*\bd-none d-print-block\b.*?</\1>|<script\b.*?</script>", re.S)


def findings() -> list[str]:
    out = []
    for p in sorted(TEMPLATES.rglob("*.html.twig")):
        rel = str(p.relative_to(ROOT))
        text = p.read_text(encoding="utf-8")
        if STATUS_MAP.search(text):
            out.append(f"{rel}::status-map")
        for m in TOOLTIP.finditer(text):
            if m.group(1).lower() not in FOCUSABLE and "tabindex" not in m.group(0):
                out.append(f"{rel}::tooltip-unreachable")
                break
        if not MAIN_LAYOUTS.search(text):
            continue
        if MAIN_OPEN.search(text):
            out.append(f"{rel}::nested-main")
        outline = PRINT_ONLY.sub("", text)
        h1 = len(H1.findall(outline))
        if h1 > 1 or (h1 >= 1 and PAGE_HEADER.search(text)):
            out.append(f"{rel}::extra-h1")
    return out


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--baseline")
    ap.add_argument("--write-baseline")
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    current = findings()
    if args.write_baseline:
        Path(args.write_baseline).write_text(
            "# Page-semantics offenders (Gate 78): <template>::nested-main|extra-h1|status-map|tooltip-unreachable. May only shrink.\n"
            + "".join(f"{f}\n" for f in current),
            encoding="utf-8",
        )
        print(f"check_page_semantics: wrote {len(current)} entries")
        return 0

    baseline: set[str] = set()
    if args.baseline:
        for line in Path(args.baseline).read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if line and not line.startswith("#"):
                baseline.add(line)

    new = [f for f in current if f not in baseline]
    stale = sorted(baseline - set(current))
    if new:
        print("check_page_semantics: new findings (see the docstring for each rule):")
        for f in new:
            print(f"  {f}")
    if stale:
        print("check_page_semantics: fixed entries still in the baseline — remove them:")
        for f in stale:
            print(f"  {f}")
    if not new and not stale and not args.quiet:
        print(f"check_page_semantics: OK ({len(current)} baselined)")
    return 1 if new or stale else 0


if __name__ == "__main__":
    sys.exit(main())
