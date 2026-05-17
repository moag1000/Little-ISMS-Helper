#!/usr/bin/env python3
"""
check_translation_nesting.py — Verify translation top-level keys match domain.

A translation file `<domain>.<locale>.yaml` should have ALL top-level keys
matching either:
  * the domain name itself (`assets:` in `assets.de.yaml`)
  * a small allowlist of cross-cutting Alva/Inbox/Global keys
  * `messages.<locale>.yaml` is exempt (it's the global fallback bucket)

This catches the `no_tenant:` was-at-top-level bug pattern — a sub-section
key bleeding to the outer scope, where `|trans('no_tenant.title')` would
silently resolve to the messages fallback instead of the intended file.

Top-level allowlist (per CLAUDE.md): alva, inbox, global, data_breach,
processing_activity, common, app, breadcrumb. New tooling keys should be
nested under the domain.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TR_DIR = ROOT / "translations"
EXEMPT_DOMAINS = {"messages"}
ALLOWLIST = {
    "alva", "inbox", "global", "data_breach", "processing_activity",
    "common", "app", "breadcrumb",
}

RE_TOP_KEY = re.compile(r"^([a-zA-Z_][a-zA-Z0-9_]*):", re.MULTILINE)


def parse_top_keys(path: Path) -> list[tuple[int, str]]:
    try:
        text = path.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        return []
    out: list[tuple[int, str]] = []
    for idx, raw in enumerate(text.splitlines(), start=1):
        if not raw or raw[0] in (" ", "\t", "#"):
            continue
        m = re.match(r"^([a-zA-Z_][a-zA-Z0-9_]*):", raw)
        if m:
            out.append((idx, m.group(1)))
    return out


def load_baseline(path: Path | None) -> set[str]:
    if path is None or not path.exists():
        return set()
    out = set()
    for raw in path.read_text(encoding="utf-8").splitlines():
        s = raw.strip()
        if s and not s.startswith("#"):
            out.add(s)
    return out


def _rel(p: Path) -> Path:
    try:
        return p.relative_to(ROOT)
    except ValueError:
        return Path(p.name)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--baseline", type=Path, default=None)
    ap.add_argument("--write-baseline", type=Path, default=None)
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    if not TR_DIR.is_dir():
        print(f"ERROR: {TR_DIR} not found", file=sys.stderr)
        return 2

    violations: list[tuple[Path, int, str]] = []
    for path in sorted(TR_DIR.glob("*.yaml")):
        parts = path.name.split(".")
        if len(parts) < 3:
            continue
        domain = parts[0]
        if domain in EXEMPT_DOMAINS:
            continue
        for ln, key in parse_top_keys(path):
            if key == domain or key in ALLOWLIST:
                continue
            violations.append((path, ln, f"top-level key '{key}' not domain '{domain}'"))

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_translation_nesting.py baseline\n# Format: <relative-path>:<line>:<key>\n")
            for path, ln, snip in violations:
                key = snip.split("'")[1] if "'" in snip else "?"
                fh.write(f"{_rel(path)}:{ln}:{key}\n")
        print(f"check_translation_nesting: wrote {len(violations)} entries to {args.write_baseline}")
        return 0

    baseline = load_baseline(args.baseline)
    new = []
    for path, ln, snip in violations:
        key = snip.split("'")[1] if "'" in snip else "?"
        if f"{_rel(path)}:{ln}:{key}" not in baseline:
            new.append((path, ln, snip))
    total = len(violations)
    baselined = total - len(new)

    if not new:
        if not args.quiet:
            print(f"check_translation_nesting: OK — {total} foreign top-key(s), {baselined} baselined.")
        else:
            print(f"check_translation_nesting: OK ({total}, all baselined)")
        return 0

    print("check_translation_nesting: VIOLATIONS\n")
    for path, ln, snip in new[:200]:
        print(f"FAIL {_rel(path)}:{ln}: {snip}")
    if len(new) > 200:
        print(f"... and {len(new) - 200} more")
    print(f"\ncheck_translation_nesting: {len(new)} new violation(s) ({baselined} baselined, {total} total).")
    print("Fix: nest under the domain key. e.g. instead of\n  no_tenant: { title: ... }\nuse\n  <domain>:\n    no_tenant:\n      title: ...")
    return 1


if __name__ == "__main__":
    sys.exit(main())
