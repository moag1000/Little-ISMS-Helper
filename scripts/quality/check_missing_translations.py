#!/usr/bin/env python3
"""
Gate 6 — Missing translation keys (fast Python implementation).

Replaces the shell wrapper around `php bin/console debug:translation`
which cold-started the Symfony kernel 12 times sequentially (~12-24s on CI).
This direct YAML-diff implementation runs in <1s.

Compares the key-sets of <domain>.de.yaml and <domain>.en.yaml for every
domain, in both directions: a key that exists in only one locale fails.

Beyond key presence it also fails on
  - an EMPTY value in one locale while the other locale has text (the key
    exists, so the translator does not fall through to the next locale and
    the user sees a blank label),
  - a differing placeholder set (`%name%`, `{name}`) between the two locales
    of the same key (e.g. `%level%` vs `%probability%` -> literal `%level%`
    leaks into the UI), and
  - a domain file that exists in only one locale.

Trade-off vs the original:
  - Loses static template-side "is this key still referenced?" scan
    — that's `debug:translation`'s --only-unused mode, a separate concern
    (covered by check_translation_dynamic_keys.py registry).
  - For "are the two locales in sync?" the YAML diff is authoritative.

Usage:
    python3 scripts/quality/check_missing_translations.py
    TARGETED=1 python3 scripts/quality/check_missing_translations.py  # high-priority subset only
"""
from __future__ import annotations

import os
import re
import sys
from pathlib import Path
from typing import Any, Iterable

import yaml


PROJECT_ROOT = Path(__file__).resolve().parent.parent.parent
TRANSLATIONS_DIR = PROJECT_ROOT / "translations"

HIGH_PRIORITY_DOMAINS = [
    "nav",
    "alva",
    "admin",
    "compliance",
    "compliance_wizard",
    "mfa",
    "dashboard",
    "risk",
    "control",
    "incident",
    "assets",
    "vulnerabilities",
]

SKIP_DOMAINS: set[str] = set()

# (domain, key) pairs whose placeholder token is a literal, user-facing EXAMPLE
# that is deliberately translated (never substituted by the translator).
PLACEHOLDER_ALLOWLIST: set[tuple[str, str]] = {
    ("answer_library", "answer_library.placeholder.answer"),
}


def flatten(data: Any, prefix: str = "") -> Iterable[str]:
    """Yield every dot-notated leaf-key (Symfony translator semantics)."""
    if isinstance(data, dict):
        for k, v in data.items():
            child = f"{prefix}.{k}" if prefix else str(k)
            if isinstance(v, (dict, list)):
                yield from flatten(v, child)
            else:
                yield child
    elif isinstance(data, list):
        # Lists in translation YAML are rare but valid (numbered alternatives).
        for i, item in enumerate(data):
            child = f"{prefix}.{i}"
            if isinstance(item, (dict, list)):
                yield from flatten(item, child)
            else:
                yield child


def keys_of(path: Path) -> set[str]:
    if not path.exists():
        return set()
    with path.open(encoding="utf-8") as f:
        data = yaml.safe_load(f) or {}
    return set(flatten(data))


def extract_leftovers(path: Path) -> list[str]:
    """Keys whose value is still a `translation:extract` stub (`__<key>`)."""
    if not path.exists():
        return []
    with path.open(encoding="utf-8") as f:
        data = yaml.safe_load(f) or {}
    out: list[str] = []

    def walk(node: Any, prefix: str = "") -> None:
        if isinstance(node, dict):
            for k, v in node.items():
                walk(v, f"{prefix}.{k}" if prefix else str(k))
        elif isinstance(node, str) and node.startswith("__"):
            out.append(prefix)

    walk(data)
    return out


PLACEHOLDER_RE = re.compile(r"%[A-Za-z_][A-Za-z0-9_]*%|\{[A-Za-z_][A-Za-z0-9_]*\}")


def leaves(path: Path) -> dict[str, Any]:
    """Map every dot-notated leaf key to its raw value."""
    if not path.exists():
        return {}
    with path.open(encoding="utf-8") as f:
        data = yaml.safe_load(f) or {}
    out: dict[str, Any] = {}

    def walk(node: Any, prefix: str = "") -> None:
        if isinstance(node, dict):
            for k, v in node.items():
                walk(v, f"{prefix}.{k}" if prefix else str(k))
        elif isinstance(node, list):
            for i, item in enumerate(node):
                walk(item, f"{prefix}.{i}")
        else:
            out[prefix] = node

    walk(data)
    return out


def is_blank(value: Any) -> bool:
    return value is None or (isinstance(value, str) and value.strip() == "")


def value_problems(domain: str) -> tuple[list[str], list[str]]:
    """Return (empty-value keys, placeholder-mismatch descriptions)."""
    de = leaves(TRANSLATIONS_DIR / f"{domain}.de.yaml")
    en = leaves(TRANSLATIONS_DIR / f"{domain}.en.yaml")
    empty: list[str] = []
    mismatch: list[str] = []
    for key in sorted(de.keys() & en.keys()):
        d, e = de[key], en[key]
        if is_blank(d) != is_blank(e):
            empty.append(f"{key} (de={'empty' if is_blank(d) else 'set'}, en={'empty' if is_blank(e) else 'set'})")
            continue
        if isinstance(d, str) and isinstance(e, str):
            pd, pe = set(PLACEHOLDER_RE.findall(d)), set(PLACEHOLDER_RE.findall(e))
            if pd != pe and (domain, key) not in PLACEHOLDER_ALLOWLIST:
                mismatch.append(f"{key} (de={sorted(pd)}, en={sorted(pe)})")
    return empty, mismatch


def single_locale_domains() -> list[str]:
    """Domain files present in one locale only."""
    de = {p.name.removesuffix(".de.yaml") for p in TRANSLATIONS_DIR.glob("*.de.yaml")}
    en = {p.name.removesuffix(".en.yaml") for p in TRANSLATIONS_DIR.glob("*.en.yaml")}
    return sorted((de ^ en) - SKIP_DOMAINS)


def discover_domains() -> list[str]:
    domains: list[str] = []
    for de_file in sorted(TRANSLATIONS_DIR.glob("*.de.yaml")):
        domain = de_file.name.removesuffix(".de.yaml")
        if domain in SKIP_DOMAINS:
            continue
        if not (TRANSLATIONS_DIR / f"{domain}.en.yaml").exists():
            continue  # reported by single_locale_domains()
        domains.append(domain)
    return domains


def main() -> int:
    if not TRANSLATIONS_DIR.is_dir():
        print(f"ERROR: translations/ not found at {TRANSLATIONS_DIR}", file=sys.stderr)
        return 2

    # Full check is the default since 2026-10: every domain, both directions.
    # TARGETED=1 restores the old high-priority subset for a quick local run.
    all_mode = os.environ.get("TARGETED") != "1"
    if all_mode:
        domains = discover_domains()
        print(f"Mode: all domains ({len(domains)})")
    else:
        domains = [
            d for d in HIGH_PRIORITY_DOMAINS
            if d not in SKIP_DOMAINS
            and (TRANSLATIONS_DIR / f"{d}.de.yaml").exists()
            and (TRANSLATIONS_DIR / f"{d}.en.yaml").exists()
        ]
        print(
            f"Mode: targeted ({len(domains)} high-priority domains; "
            f"unset TARGETED for the full check)"
        )

    total_violations = 0
    checked = 0

    for domain in single_locale_domains():
        have = "de" if (TRANSLATIONS_DIR / f"{domain}.de.yaml").exists() else "en"
        print(f"FAIL domain '{domain}': only {domain}.{have}.yaml exists, the other locale file is missing")
        total_violations += 1

    for domain in domains:
        checked += 1
        de_keys = keys_of(TRANSLATIONS_DIR / f"{domain}.de.yaml")
        en_keys = keys_of(TRANSLATIONS_DIR / f"{domain}.en.yaml")

        missing_in_de = sorted(en_keys - de_keys)
        if missing_in_de:
            print(
                f"FAIL domain '{domain}': "
                f"{len(missing_in_de)} missing German translation key(s)"
            )
            for k in missing_in_de[:5]:
                print(f"  - {k}")
            total_violations += len(missing_in_de)

        # config/packages/translation.yaml has `fallbacks: [de, en]`, so a key
        # missing in EN shows German text to English users (an EMPTY value does
        # not fall back at all and renders blank - checked below).
        missing_in_en = sorted(de_keys - en_keys)
        if missing_in_en:
            print(
                f"FAIL domain '{domain}': "
                f"{len(missing_in_en)} missing English translation key(s)"
            )
            for k in missing_in_en[:5]:
                print(f"  - {k}")
            total_violations += len(missing_in_en)

        empty, mismatch = value_problems(domain)
        if empty:
            print(f"FAIL domain '{domain}': {len(empty)} empty value(s) while the other locale has text")
            for k in empty[:5]:
                print(f"  - {k}")
            total_violations += len(empty)
        if mismatch:
            print(f"FAIL domain '{domain}': {len(mismatch)} placeholder mismatch(es) between de and en")
            for k in mismatch[:5]:
                print(f"  - {k}")
            total_violations += len(mismatch)

        for locale in ("de", "en"):
            stubs = extract_leftovers(TRANSLATIONS_DIR / f"{domain}.{locale}.yaml")
            if stubs:
                print(
                    f"FAIL domain '{domain}' ({locale}): "
                    f"{len(stubs)} untranslated extract stub(s) ('__key')"
                )
                for k in stubs[:5]:
                    print(f"  - {k}")
                total_violations += len(stubs)

    print()
    print(f"Translation check: {checked} domain(s) checked.")

    if total_violations == 0:
        print("OK  Gate 6 — No missing translation keys in checked domains.")
        return 0

    print(f"Gate 6 FAIL: {total_violations} missing translation key(s) found.")
    print(
        "Fix: open translations/<domain>.de.yaml and add the listed keys "
        "(values can be a German translation of the EN string)."
    )
    return 1


if __name__ == "__main__":
    sys.exit(main())
