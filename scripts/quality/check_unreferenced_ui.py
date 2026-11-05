#!/usr/bin/env python3
"""
check_unreferenced_ui.py — Gate 69: no dead templates, no dead Stimulus controllers.

A template nobody renders, includes, embeds, extends or imports is dead weight:
it still has to be migrated in every design-system sweep and it misleads the
next reader into thinking it is live. Same for a Stimulus controller that no
markup attaches.

Template reference = its path (relative to templates/, with or without the
`.html.twig` suffix) appears as a string anywhere in src/, templates/, config/
or tests/. Controller reference = its identifier (`foo_bar_controller.js` →
`foo-bar`) appears in templates/, assets/ (other than its own file), src/ or
config/.

Dynamically built names are not resolvable statically: list them in the
baseline with a short reason (`path  # reason`). Stale baseline entries are
reported but do not fail.

Usage: check_unreferenced_ui.py [--baseline FILE] [--quiet]
"""
from __future__ import annotations

import argparse
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TEMPLATES = ROOT / "templates"
CONTROLLERS = ROOT / "assets" / "controllers"

# Rendered by framework conventions, not by name.
TEMPLATE_PREFIX_ALLOW = ("bundles/", "emails/", "email/")

SOURCE_SUFFIXES = {".php", ".twig", ".yaml", ".yml", ".js", ".json", ".xml"}


def read_corpus(dirs: list[Path], skip: Path | None = None) -> str:
    chunks: list[str] = []
    for base in dirs:
        if not base.exists():
            continue
        for path in base.rglob("*"):
            if path.is_file() and path.suffix in SOURCE_SUFFIXES and path != skip:
                try:
                    chunks.append(path.read_text(encoding="utf-8", errors="ignore"))
                except OSError:
                    continue
    return "\n".join(chunks)


def load_baseline(path: Path | None) -> set[str]:
    if path is None or not path.exists():
        return set()
    entries = set()
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.split("#", 1)[0].strip()
        if line:
            entries.add(line)
    return entries


def find_dead_templates() -> list[str]:
    corpus = read_corpus([ROOT / "src", TEMPLATES, ROOT / "config", ROOT / "tests"])
    dead = []
    for path in sorted(TEMPLATES.rglob("*.twig")):
        rel = path.relative_to(TEMPLATES).as_posix()
        if rel.startswith(TEMPLATE_PREFIX_ALLOW):
            continue
        stem = rel.removesuffix(".html.twig").removesuffix(".twig")
        if rel in corpus or f"'{stem}'" in corpus or f'"{stem}"' in corpus:
            continue
        dead.append(f"templates/{rel}")
    return dead


def find_dead_controllers() -> list[str]:
    controllers = sorted(CONTROLLERS.glob("*_controller.js"))
    corpus = read_corpus([TEMPLATES, ROOT / "src", ROOT / "config"])
    own = {p: p.read_text(encoding="utf-8", errors="ignore") for p in controllers}
    assets = read_corpus([ROOT / "assets"])
    dead = []
    for path in controllers:
        ident = path.name.removesuffix("_controller.js").replace("_", "-")
        if ident in corpus:
            continue
        # Referenced from another asset file (not just its own source)?
        if assets.count(ident) > own[path].count(ident):
            continue
        dead.append(f"assets/controllers/{path.name}")
    return dead


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[1])
    parser.add_argument("--baseline", type=Path)
    parser.add_argument("--quiet", action="store_true")
    args = parser.parse_args()

    baseline = load_baseline(args.baseline)
    found = find_dead_templates() + find_dead_controllers()
    new = [f for f in found if f not in baseline]
    stale = sorted(baseline - set(found))

    if stale and not args.quiet:
        print("Stale baseline entries (now referenced or deleted — remove them):")
        for entry in stale:
            print(f"  {entry}")

    if new:
        print("Unreferenced UI files (delete them, or baseline with a reason if built dynamically):")
        for entry in new:
            print(f"  {entry}")
        return 1

    if not args.quiet:
        print(f"OK — {len(found)} baselined, no new unreferenced templates or controllers.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
