#!/usr/bin/env python3
"""
check_breadcrumb_url_key.py — breadcrumb crumbs must link via `url:`.

`templates/_components/_breadcrumb.html.twig` renders a crumb as a LINK only
when the crumb object has a `url:` key. A crumb written with `path:` or `href:`
(a frequent copy-paste slip, sometimes with a bare route-name string) is
silently rendered as UNLINKED plain text — the user sees breadcrumb text that
looks clickable but is dead. ~50 such dead crumbs were fixed in PR #789.

This guard scans every template that includes the breadcrumb component, parses
the `breadcrumbs: [...]` array (Twig comments stripped), and fails when any
crumb object uses `path:`/`href:` as a link key without a `url:`.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TPL = ROOT / "templates"


def strip_comments(s: str) -> str:
    return re.sub(r"\{#.*?#\}", "", s, flags=re.S)


def breadcrumb_array(text: str) -> str | None:
    m = re.search(r"breadcrumbs:\s*\[", text)
    if not m:
        return None
    rest = text[m.end():]
    depth = 1
    for i, ch in enumerate(rest):
        if ch == "[":
            depth += 1
        elif ch == "]":
            depth -= 1
            if depth == 0:
                return rest[:i]
    return rest


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    hits = []
    checked = 0
    for f in TPL.rglob("*.html.twig"):
        text = f.read_text(encoding="utf-8", errors="ignore")
        if "_breadcrumb.html.twig" not in text:
            continue
        text = strip_comments(text)
        arr = breadcrumb_array(text)
        if arr is None:
            continue
        checked += 1
        for crumb in re.finditer(r"\{[^{}]*\}", arr):
            c = crumb.group(0)
            if re.search(r"\b(?:path|href):", c) and "url:" not in c:
                hits.append((f.relative_to(ROOT), c.strip().replace("\n", " ")[:90]))

    if hits:
        print(f"check_breadcrumb_url_key: {len(hits)} dead-link breadcrumb crumb(s) "
              f"(use `url: path(...)`, not `path:`/`href:`):")
        for rel, c in hits:
            print(f"  FAIL {rel}: {c}")
        return 1

    if not args.quiet:
        print(f"check_breadcrumb_url_key: OK — {checked} breadcrumb templates, "
              f"no dead-link crumbs.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
