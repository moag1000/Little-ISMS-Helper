#!/usr/bin/env python3
"""
check_template_route_refs.py — every literal path()/url() route must exist.

A `{{ path('typo_route') }}` in a template throws at render time -> HTTP 500 on
every page that includes it. PHP unit tests don't catch this (it's a render-time
Twig+routing concern). This guard extracts every literal `path('x')` / `url('x')`
route name from templates (Twig comments stripped) and verifies it exists in the
router, failing on any unknown route.

The router route list is taken from `php bin/console debug:router --format=json`
(invoked automatically) or from a pre-generated file via --routes-json.

ALLOWLIST holds intentionally-unresolved references:
  * the dev-only design-system preview page uses placeholder route names;
  * dormant components whose backing route is a tracked TODO.
Anything NOT allowlisted that references a missing route fails the gate.
"""
from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TPL = ROOT / "templates"

# route name -> reason it is allowed to be missing (kept small + documented).
ALLOWLIST = {
    # dev/design_system.html.twig — preview page with placeholder routes
    "route": "dev design-system placeholder",
    "risk_index": "dev design-system placeholder",
    "risk_new": "dev design-system placeholder",
    "risk_delete": "dev design-system placeholder",
    "document_new": "dev design-system placeholder",
    "dashboard": "dev design-system placeholder",
    "audit_finding_show": "dev design-system placeholder",
    "dashboard_settings_save": "dev design-system placeholder",
    # dormant components (not yet wired into any page) — backing route TODO
    "app_preferences_dismiss": "TODO: dormant _fa_onboarding_banner needs dismiss route",
    "admin_api_key_revoke": "TODO: dormant _fa_api_key component",
}


def strip_comments(s: str) -> str:
    return re.sub(r"\{#.*?#\}", "", s, flags=re.S)


def load_routes(routes_json: str | None) -> set[str]:
    if routes_json:
        data = json.loads(Path(routes_json).read_text(encoding="utf-8"))
    else:
        out = subprocess.run(
            ["php", "bin/console", "debug:router", "--format=json", "--env=dev"],
            cwd=ROOT, capture_output=True, text=True,
        )
        if out.returncode != 0:
            print("check_template_route_refs: could not run debug:router:\n"
                  + out.stderr, file=sys.stderr)
            sys.exit(2)
        data = json.loads(out.stdout)
    return set(data.keys())


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--routes-json", default=None,
                    help="path to debug:router --format=json output (optional)")
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    existing = load_routes(args.routes_json)
    pat = re.compile(r"\b(?:path|url)\('([a-zA-Z0-9_]+)'")

    missing = {}  # route -> list[file]
    for f in TPL.rglob("*.html.twig"):
        text = strip_comments(f.read_text(encoding="utf-8", errors="ignore"))
        for m in pat.finditer(text):
            r = m.group(1)
            if r in existing or r in ALLOWLIST:
                continue
            missing.setdefault(r, []).append(str(f.relative_to(ROOT)))

    if missing:
        print(f"check_template_route_refs: {len(missing)} unknown route(s) "
              f"referenced (would 500 at render):")
        for r, files in sorted(missing.items()):
            print(f"  FAIL {r:40} {files[0]}" + (f" (+{len(files)-1} more)"
                                                 if len(files) > 1 else ""))
        print("\nFix: correct the route name, or add the route, or (if a known "
              "placeholder/dormant) add it to ALLOWLIST in this guard.")
        return 1

    if not args.quiet:
        print("check_template_route_refs: OK — all literal path()/url() route "
              f"references resolve ({len(ALLOWLIST)} allowlisted).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
