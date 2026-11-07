#!/usr/bin/env python3
"""
check_unreferenced_ui.py — Gate 69: no dead templates.

A template nobody renders, includes, embeds, extends or imports is dead weight:
it still has to be migrated in every design-system sweep and it misleads the
next reader into thinking it is live. (Dead Stimulus controllers: Gate 79,
check_stimulus_wiring.py.)

Template reference = its path (relative to templates/, with or without the
`.html.twig` suffix) appears as a string in src/, templates/, config/ or tests/
outside the template itself (its own usage comment does not count).

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
        # A template's own usage comment ("{% include 'x.html.twig' %}") is
        # not a reference: count occurrences outside the file itself.
        own = path.read_text(encoding="utf-8", errors="ignore")
        needles = (rel, f"'{stem}'", f'"{stem}"')
        if any(corpus.count(n) > own.count(n) for n in needles):
            continue
        dead.append(f"templates/{rel}")
    return dead


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[1])
    parser.add_argument("--baseline", type=Path)
    parser.add_argument("--quiet", action="store_true")
    args = parser.parse_args()

    baseline = load_baseline(args.baseline)
    found = find_dead_templates()
    new = [f for f in found if f not in baseline]
    stale = sorted(baseline - set(found))

    if stale and not args.quiet:
        print("Stale baseline entries (now referenced or deleted — remove them):")
        for entry in stale:
            print(f"  {entry}")

    if new:
        print("Unreferenced templates (delete them, or baseline with a reason if built dynamically):")
        for entry in new:
            print(f"  {entry}")
        return 1

    if not args.quiet:
        print(f"OK — {len(found)} baselined, no new unreferenced templates.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
