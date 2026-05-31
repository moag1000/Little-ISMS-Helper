#!/usr/bin/env python3
"""
check_nav_area_parity.py — keep the sidebar main-area highlight honest.

The L1 "active area" is decided by the ordered `nav_active` resolver in
`templates/_components/_mega_menu.html.twig` (`{ p: '<route-prefix>', c:
'<area>' }`, most-specific-first, first-match-wins). The actual links live in
`templates/_components/_mega_menu_panel_only.html.twig`, grouped into
`data-category="<area>"` flyout panels.

These two MUST agree: every route linked in a panel must resolve, via the
resolver, to THAT panel's area — otherwise the page highlights the wrong area
or none at all (the 44-defect drift fixed in PR #788). This guard parses both
files statically (no kernel needed) and fails when a panel route does not
resolve to its own panel.

Intentional quick-action shortcuts (a "New X" link surfaced in another panel
that should still highlight X's home area) are listed in SHORTCUTS.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MENU = ROOT / "templates/_components/_mega_menu.html.twig"
PANEL = ROOT / "templates/_components/_mega_menu_panel_only.html.twig"

# Quick-action links that intentionally live in one panel but highlight the
# entity's own home area. file-panel -> route -> expected resolved area.
SHORTCUTS = {
    "app_asset_new": "assets-risk",
    "app_risk_new": "assets-risk",
    "app_data_breach_new": "privacy",
    "app_audit_new": "compliance",
}


def parse_resolver(text: str) -> list[tuple[str, str]]:
    """Ordered (prefix, area) pairs from the _nav_map literal."""
    pairs = []
    for m in re.finditer(r"\{\s*p:\s*'([^']+)'\s*,\s*c:\s*'([^']+)'\s*\}", text):
        pairs.append((m.group(1), m.group(2)))
    return pairs


def resolve(route: str, pairs: list[tuple[str, str]]) -> str | None:
    for prefix, area in pairs:
        if route == prefix or route.startswith(prefix):
            return area
    return None


def parse_panel_routes(text: str) -> list[tuple[str, str]]:
    """(route, panel-area) for every path('route') inside a data-category block."""
    cats = [(m.start(), m.group(1)) for m in re.finditer(r'data-category="([a-z-]+)"', text)]
    cats.append((len(text), None))
    out = []
    seen = set()
    for i in range(len(cats) - 1):
        start, area = cats[i]
        block = text[start:cats[i + 1][0]]
        for rm in re.finditer(r"path\('([a-zA-Z0-9_]+)'", block):
            route = rm.group(1)
            if route in seen:
                continue
            seen.add(route)
            out.append((route, area))
    return out


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    if not MENU.exists() or not PANEL.exists():
        print("check_nav_area_parity: menu templates not found", file=sys.stderr)
        return 1

    pairs = parse_resolver(MENU.read_text(encoding="utf-8"))
    if not pairs:
        print("check_nav_area_parity: could not parse _nav_map resolver", file=sys.stderr)
        return 1

    routes = parse_panel_routes(PANEL.read_text(encoding="utf-8"))
    violations = []
    for route, panel_area in routes:
        expected = SHORTCUTS.get(route, panel_area)
        got = resolve(route, pairs)
        if got != expected:
            violations.append((route, panel_area, expected, got))

    if violations:
        print(f"check_nav_area_parity: {len(violations)} panel route(s) "
              f"resolve to the wrong main area:")
        for route, panel, exp, got in violations:
            print(f"  FAIL {route:42} in panel '{panel}' -> resolves to "
                  f"'{got}' (expected '{exp}')")
        print("\nFix: add/reorder the route's prefix in the _nav_map resolver in "
              "templates/_components/_mega_menu.html.twig (most-specific-first), "
              "or add an intentional shortcut to SHORTCUTS in this guard.")
        return 1

    if not args.quiet:
        print(f"check_nav_area_parity: OK — {len(routes)} panel routes all resolve "
              f"to their own area ({len(pairs)} resolver entries).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
