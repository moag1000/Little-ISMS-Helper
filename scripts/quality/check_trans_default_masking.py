#!/usr/bin/env python3
"""
check_trans_default_masking.py — Gate 76: no `|trans|default` over a missing key.

`'some.key'|trans({}, 'domain')|default('Fallback')` looks safe, but Symfony's
translator returns the KEY itself when a message is missing, so `default` never
fires (the value is not empty) and the user sees the raw key. The fallback text
is dead code that hides the missing translation.

For every occurrence with a literal key and a literal domain in templates/ this
gate checks that the key exists in translations/<domain>.de.yaml AND
translations/<domain>.en.yaml (nested or flat dotted keys, any level).

Usage:
    python3 scripts/quality/check_trans_default_masking.py [--quiet] [--list-missing]
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path
from typing import Any, Iterable

import yaml

ROOT = Path(__file__).resolve().parents[2]
TPL_DIR = ROOT / "templates"
TR_DIR = ROOT / "translations"

RE_KEY_TRANS = re.compile(r"""(?P<q>['"])(?P<key>[^'"\\\n]+)(?P=q)\s*\|\s*trans\s*\(""")
RE_STR = re.compile(r"""^\s*(['"])(?P<v>.*)\1\s*$""", re.S)
RE_DEFAULT = re.compile(r"\s*\|\s*default\s*\(")


def flatten(data: Any, prefix: str = "") -> Iterable[str]:
    if isinstance(data, dict):
        for k, v in data.items():
            child = f"{prefix}.{k}" if prefix else str(k)
            if isinstance(v, (dict, list)):
                yield from flatten(v, child)
            else:
                yield child
    elif isinstance(data, list):
        for i, item in enumerate(data):
            child = f"{prefix}.{i}"
            if isinstance(item, (dict, list)):
                yield from flatten(item, child)
            else:
                yield child


_cache: dict[Path, set[str]] = {}


def keys_of(path: Path) -> set[str] | None:
    if path in _cache:
        return _cache[path]
    if not path.exists():
        return None
    with path.open(encoding="utf-8") as fh:
        _cache[path] = set(flatten(yaml.safe_load(fh) or {}))
    return _cache[path]


def match_paren(text: str, start: int) -> int:
    """Index of the ')' closing the '(' at text[start-1]; -1 if none."""
    depth, quote, i = 1, "", start
    while i < len(text):
        c = text[i]
        if quote:
            if c == "\\":
                i += 1
            elif c == quote:
                quote = ""
        elif c in "'\"":
            quote = c
        elif c in "([{":
            depth += 1
        elif c in ")]}":
            depth -= 1
            if depth == 0:
                return i
        i += 1
    return -1


def split_top(args: str) -> list[str]:
    parts, depth, quote, cur = [], 0, "", []
    i = 0
    while i < len(args):
        c = args[i]
        if quote:
            cur.append(c)
            if c == "\\" and i + 1 < len(args):
                i += 1
                cur.append(args[i])
            elif c == quote:
                quote = ""
        elif c in "'\"":
            quote = c
            cur.append(c)
        elif c in "([{":
            depth += 1
            cur.append(c)
        elif c in ")]}":
            depth -= 1
            cur.append(c)
        elif c == "," and depth == 0:
            parts.append("".join(cur))
            cur = []
        else:
            cur.append(c)
        i += 1
    parts.append("".join(cur))
    return parts


def find_masked(text: str) -> list[tuple[int, str, str, str]]:
    """Return (line, key, domain, default_text) for each literal |trans|default."""
    out = []
    for m in RE_KEY_TRANS.finditer(text):
        end = match_paren(text, m.end())
        if end < 0:
            continue
        args = split_top(text[m.end():end])
        if len(args) < 2:
            continue
        dm = RE_STR.match(args[1])
        if not dm:
            continue
        d = RE_DEFAULT.match(text, end + 1)
        if not d:
            continue
        dend = match_paren(text, d.end())
        dflt = ""
        if dend > 0:
            sm = RE_STR.match(text[d.end():dend])
            dflt = sm.group("v") if sm else ""
        out.append((text.count("\n", 0, m.start()) + 1, m.group("key"), dm.group("v"), dflt))
    return out


def scan() -> list[tuple[str, int, str, str, str, list[str]]]:
    """(file, line, key, domain, default, [missing locales])"""
    res = []
    for tpl in sorted(TPL_DIR.rglob("*.twig")):
        text = tpl.read_text(encoding="utf-8", errors="ignore")
        if "default" not in text:
            continue
        for ln, key, dom, dflt in find_masked(text):
            missing = []
            for loc in ("de", "en"):
                ks = keys_of(TR_DIR / f"{dom}.{loc}.yaml")
                if ks is None or key not in ks:
                    missing.append(loc)
            if missing:
                res.append((str(tpl.relative_to(ROOT)), ln, key, dom, dflt, missing))
    return res


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--quiet", action="store_true")
    ap.add_argument("--list-missing", action="store_true", help="print TSV: domain, key, default, locales")
    args = ap.parse_args()
    missing = scan()
    if args.list_missing:
        for f, ln, key, dom, dflt, locs in missing:
            print(f"{dom}\t{key}\t{dflt}\t{','.join(locs)}\t{f}:{ln}")
        return 0
    if not missing:
        print("check_trans_default_masking: OK" if args.quiet else
              "check_trans_default_masking: OK — every literal |trans|default key exists in DE and EN.")
        return 0
    print("check_trans_default_masking: VIOLATIONS\n")
    for f, ln, key, dom, dflt, locs in missing:
        print(f"FAIL {f}:{ln}: '{key}' (domain '{dom}') missing in {', '.join(locs)}")
    print(f"\n{len(missing)} masked miss(es). The translator returns the key itself for a missing message, "
          "so |default never fires.\nFix: add the key to translations/<domain>.{de,en}.yaml and drop |default.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
