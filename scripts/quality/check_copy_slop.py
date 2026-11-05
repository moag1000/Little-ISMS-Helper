#!/usr/bin/env python3
"""
check_copy_slop.py — Gate 71: UI copy without filler.

Copy rules (CLAUDE.md "UI copy"):
  * A toast is a participle, not a sentence: "Gespeichert.", "Export gestartet."
    No "erfolgreich" / "successfully", no exclamation mark.
  * No filler adverbs ("ganz einfach", "nahtlos", "seamlessly", …).
  * The page-title suffix lives once in base.html.twig, never in a child template.

Checks every value in translations/*.{de,en}.yaml (except policy-template
document bodies, `policy.*` keys in translations/policy_*.yaml) and every {% block title %}
in templates/. Findings are identified by `<file>::<key>` (translations) or
`<file>` (templates), so line shifts never invalidate the baseline. A
baseline entry needs a reason: `translations/x.de.yaml::some.key  # quoted law`.
Stale baseline entries are reported but do not fail.

Usage: check_copy_slop.py [--baseline FILE] [--quiet]
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]

RULES = {
    "de": [
        ("erfolgreich", re.compile(r"\berfolgreich\b", re.I)),
        ("exclamation", re.compile(r"!\s*$|!\s+[A-ZÄÖÜ]")),
        ("filler", re.compile(r"\b(ganz einfach|kinderleicht|nahtlos|mit nur einem Klick|leistungsstark|Gute Arbeit)\b", re.I)),
    ],
    "en": [
        ("successfully", re.compile(r"\bsuccessful(ly)?\b", re.I)),
        ("exclamation", re.compile(r"!\s*$|!\s+[A-Z]")),
        ("filler", re.compile(r"\b(seamless(ly)?|effortless(ly)?|with just one click|Great job)\b", re.I)),
    ],
}
TITLE_BLOCK = re.compile(r"{%-?\s*block title\s*-?%}(.*?){%-?\s*endblock", re.S)


def flatten(node, prefix=""):
    if isinstance(node, dict):
        for k, v in node.items():
            yield from flatten(v, f"{prefix}.{k}" if prefix else str(k))
    elif isinstance(node, str):
        yield prefix, node


def find() -> list[str]:
    found: list[str] = []
    for lang, rules in RULES.items():
        for path in sorted((ROOT / "translations").glob(f"*.{lang}.yaml")):
            data = yaml.safe_load(path.read_text(encoding="utf-8")) or {}
            rel = path.relative_to(ROOT).as_posix()
            policy_text = path.name.startswith("policy_")
            for key, value in flatten(data):
                # Policy-template bodies are document content, not UI copy.
                if policy_text and key.startswith("policy."):
                    continue
                for name, rx in rules:
                    if rx.search(value):
                        found.append(f"{rel}::{key}  [{name}] {value[:70]}")
                        break
    for path in sorted((ROOT / "templates").rglob("*.twig")):
        if path.name == "base.html.twig":
            continue
        text = path.read_text(encoding="utf-8", errors="ignore")
        for block in TITLE_BLOCK.findall(text):
            if "Little ISMS Helper" in block or "'app.title'" in block:
                found.append(f"{path.relative_to(ROOT).as_posix()}  [title-suffix]")
                break
    return found


def ident(entry: str) -> str:
    return entry.split("  [", 1)[0]


def load_baseline(path: Path | None) -> set[str]:
    if path is None or not path.exists():
        return set()
    return {
        line.split("#", 1)[0].strip()
        for line in path.read_text(encoding="utf-8").splitlines()
        if line.split("#", 1)[0].strip()
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[1])
    parser.add_argument("--baseline", type=Path)
    parser.add_argument("--quiet", action="store_true")
    args = parser.parse_args()

    baseline = load_baseline(args.baseline)
    found = find()
    new = [f for f in found if ident(f) not in baseline]
    stale = sorted(baseline - {ident(f) for f in found})

    if stale and not args.quiet:
        print("Stale baseline entries (fixed or removed — drop them):")
        for entry in stale:
            print(f"  {entry}")
    if new:
        print("UI copy against the copy rules (CLAUDE.md 'UI copy'):")
        for entry in new:
            print(f"  {entry}")
        print("\nFix the text, or baseline `<file>::<key>  # reason` if the wording is required.")
        return 1
    if not args.quiet:
        print(f"OK — {len(found)} baselined, no new copy-rule violations.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
