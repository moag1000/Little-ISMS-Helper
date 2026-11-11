#!/usr/bin/env python3
"""
check_copy_slop.py — Gate 71: UI copy without filler.

Copy rules (CLAUDE.md "UI copy"):
  * A toast is a participle, not a sentence: "Gespeichert.", "Export gestartet."
    No "erfolgreich" / "successfully", no exclamation mark.
  * No filler adverbs ("ganz einfach", "nahtlos", "seamlessly", …).
  * The page-title suffix lives once in base.html.twig, never in a child template.

Checks every value in translations/*.{de,en}.yaml (except policy-template
document bodies, `policy.*` keys in translations/policy_*.yaml), every {% block title %}
in templates/, and (T4) the copy that bypasses the translation files: text nodes and
title/aria-label/placeholder/alt attributes of templates, string literals inside `{{ … }}`
(`"Gespeichert!"|trans`), and string literals of JS (assets/**/*.js and inline <script>),
with comments, URLs, regexes, CSS and translation-key-shaped literals left out. Text is
normalised first (soft hyphens, zero-width characters, full-width forms such as U+FF01).
Findings are identified by `<file>::<key>` (translations), `<file>::text:<snippet>` /
`<file>::js:<snippet>` (code) or `<file>` (title blocks), so line shifts never invalidate the baseline. A
baseline entry needs a reason: `translations/x.de.yaml::some.key  # quoted law`.
Stale baseline entries are reported but do not fail.

Usage: check_copy_slop.py [--baseline FILE] [--quiet]
"""
from __future__ import annotations

import argparse
import re
import sys
import unicodedata
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]

# "no '!' anywhere in UI text" (CLAUDE.md): not only at the end or before a capital letter.
EXCLAMATION = re.compile(r"!")

RULES = {
    "de": [
        # inflected forms too (erfolgreiche Speicherung, mit erfolgreichem Abschluss)
        ("erfolgreich", re.compile(r"\berfolgreich\w*", re.I)),
        ("exclamation", EXCLAMATION),
        ("filler", re.compile(r"\b(ganz einfach|kinderleicht\w*|nahtlos\w*|mit nur einem Klick|leistungsstark\w*|Gute Arbeit)\b", re.I)),
    ],
    "en": [
        ("successfully", re.compile(r"\bsuccessful(ly)?\b", re.I)),
        ("exclamation", EXCLAMATION),
        ("filler", re.compile(r"\b(seamless\w*|effortless\w*|with just one click|Great job)\b", re.I)),
    ],
}
TITLE_SUFFIX = re.compile(r"""Little[\s\u00a0]+ISMS|ISMS[\s\u00a0]+Helper|['"]app\.title['"]""", re.I)
TITLE_BLOCK = re.compile(r"{%-?\s*block title\s*-?%}(.*?){%-?\s*endblock", re.S)


def fold(text: str) -> str:
    """Spelling tricks off: NFKC (full-width `！` → `!`), soft hyphens / zero-width / other format characters removed."""
    text = unicodedata.normalize("NFKC", text)
    return "".join(c for c in text if unicodedata.category(c) != "Cf")


# In code the `!` of an operator (`!=`, `!important`, `!isNaN`, `(?!…)`) is no punctuation.
CODE_EXCLAMATION = re.compile(r"(?<![?(\[<])!(?![=\w(\[\-.>])")
CODE_RULES = {
    "de": [RULES["de"][0], ("exclamation", CODE_EXCLAMATION), RULES["de"][2]],
    "en": [RULES["en"][0], ("exclamation", CODE_EXCLAMATION), RULES["en"][2]],
}
# A literal that is a key / class / selector / identifier, not a sentence: lowercase tokens only.
KEY_SHAPED = re.compile(r"^[a-z0-9_%@#:.\-]+(?:[.\-/][a-z0-9_%@:\-]+)*$")
URL_OR_PATH = re.compile(r"^(?:[a-z][a-z0-9+.-]*:)?//|^/\S*$|^\S+/\S+$|^https?:", re.I)
HTML_COMMENT = re.compile(r"<!--.*?-->", re.S)
SCRIPT_STYLE = re.compile(r"<(script|style)\b[^>]*>(.*?)</\1\s*>", re.S | re.I)
TWIG_ANY = re.compile(r"\{\{.*?\}\}|\{%.*?%\}|\{#.*?#\}", re.S)
TWIG_OUTPUT = re.compile(r"\{\{(.*?)\}\}", re.S)
TAG = re.compile(r"<[^>]*>", re.S)
UI_ATTR = re.compile(r"""\b(?:title|aria-label|placeholder|alt|data-[\w-]*(?:message|text|label|title)[\w-]*)\s*=\s*(["'])(.*?)\1""", re.S | re.I)
STR_LITERAL = re.compile(r"""'((?:\\.|[^'\\\n])*)'|"((?:\\.|[^"\\\n])*)\"""", re.S)


def sentence_like(value: str) -> bool:
    value = value.strip()
    if not value or KEY_SHAPED.match(value) and "!" not in value or URL_OR_PATH.match(value):
        return False
    return bool(re.search(r"[A-Za-zÄÖÜäöüß]{2}", value))


def code_hits(value: str, lang_rules=CODE_RULES) -> str | None:
    """Name of the first rule the copy `value` breaks (German and English rules both apply to code)."""
    for rules in lang_rules.values():
        for name, rx in rules:
            if rx.search(value):
                return name
    return None


def js_literals(src: str) -> list[str]:
    """String and template literals of JS source; comments and regex literals are skipped, `${…}` is blanked."""
    out: list[str] = []
    i, n, prev = 0, len(src), ""
    while i < n:
        c = src[i]
        if c == "/" and src[i + 1:i + 2] == "/":
            j = src.find("\n", i)
            i = n if j < 0 else j
        elif c == "/" and src[i + 1:i + 2] == "*":
            j = src.find("*/", i + 2)
            i = n if j < 0 else j + 2
        elif c == "/" and (prev == "" or prev in "(,=:[!&|?{};+-*%<>~^"):
            i += 1  # regex literal: up to the closing slash outside a class
            in_class = False
            while i < n and (in_class or src[i] != "/") and src[i] != "\n":
                if src[i] == "\\":
                    i += 1
                elif src[i] == "[":
                    in_class = True
                elif src[i] == "]":
                    in_class = False
                i += 1
            i += 1
            prev = ")"
        elif c in "'\"`":
            j, buf = i + 1, []
            while j < n and src[j] != c:
                if src[j] == "\\":
                    buf.append(src[j + 1:j + 2])
                    j += 2
                    continue
                if c == "`" and src[j] == "$" and src[j + 1:j + 2] == "{":
                    depth, j = 1, j + 2
                    while j < n and depth:
                        depth += {"{": 1, "}": -1}.get(src[j], 0)
                        j += 1
                    buf.append(" ")
                    continue
                if c != "`" and src[j] == "\n":
                    break
                buf.append(src[j])
                j += 1
            out.append("".join(buf))
            i = j + 1
            prev = '"'
        else:
            if not c.isspace():
                prev = c
            i += 1
    return out


def template_copy(text: str) -> list[tuple[str, str]]:
    """(kind, snippet) pieces of UI text a template carries outside the translation files."""
    pieces: list[tuple[str, str]] = []
    text = HTML_COMMENT.sub(" ", re.sub(r"\{#.*?#\}", " ", text, flags=re.S))
    for m in SCRIPT_STYLE.finditer(text):
        if m.group(1).lower() == "script":
            pieces += [("js", lit) for lit in js_literals(TWIG_ANY.sub(" ", m.group(2)))]
    text = SCRIPT_STYLE.sub(" ", text)
    for m in TWIG_OUTPUT.finditer(text):  # {{ "Gespeichert!"|trans }}, {{ cond ? 'a b' : 'c d' }}
        pieces += [("text", a or b) for a, b in STR_LITERAL.findall(m.group(1))]
    for m in UI_ATTR.finditer(text):
        pieces.append(("text", TWIG_ANY.sub(" ", m.group(2))))
    body = TAG.sub(" ", TWIG_ANY.sub(" ", text))
    body = re.sub(r"&\w+;|&#\d+;", " ", body)
    pieces += [("text", line) for line in body.splitlines()]
    return pieces


def code_findings() -> list[str]:
    found: list[str] = []
    seen: set[str] = set()

    def add(rel: str, kind: str, value: str) -> None:
        value = " ".join(fold(value).split())
        if not sentence_like(value):
            return
        rule = code_hits(value)
        if rule:
            ident_ = f"{rel}::{kind}:{value[:60]}"
            if ident_ not in seen:
                seen.add(ident_)
                found.append(f"{ident_}  [{rule}]")

    for path in sorted((ROOT / "templates").rglob("*.twig")):
        rel = path.relative_to(ROOT).as_posix()
        # print documents / mails / PDFs are checked like any other: their copy is UI copy too.
        for kind, value in template_copy(path.read_text(encoding="utf-8", errors="ignore")):
            add(rel, kind, value)
    for path in sorted((ROOT / "assets").rglob("*.js")):
        if "vendor" in path.parts or path.name.endswith(".min.js"):
            continue
        rel = path.relative_to(ROOT).as_posix()
        for value in js_literals(path.read_text(encoding="utf-8", errors="ignore")):
            add(rel, "js", value)
    return found


def flatten(node, prefix=""):
    if isinstance(node, dict):
        for k, v in node.items():
            yield from flatten(v, f"{prefix}.{k}" if prefix else str(k))
    elif isinstance(node, list):
        for i, v in enumerate(node):
            yield from flatten(v, f"{prefix}.{i}")
    elif isinstance(node, str):
        yield prefix, node


def find() -> list[str]:
    found: list[str] = []
    for lang, rules in RULES.items():
        for path in sorted((ROOT / "translations").rglob(f"*.{lang}.y*ml")):
            data = yaml.safe_load(path.read_text(encoding="utf-8")) or {}
            rel = path.relative_to(ROOT).as_posix()
            # Policy-template bodies live in policy_<norm>.yaml; policy_wizard is UI.
            policy_text = path.name.startswith("policy_") and not path.name.startswith("policy_wizard.")
            for key, value in flatten(data):
                # Policy-template bodies are document content, not UI copy.
                if policy_text and key.startswith("policy."):
                    continue
                for name, rx in rules:
                    if rx.search(fold(value)):
                        found.append(f"{rel}::{key}  [{name}] {value[:70]}")
                        break
    for path in sorted((ROOT / "templates").rglob("*.twig")):
        if path.name == "base.html.twig":
            continue
        text = path.read_text(encoding="utf-8", errors="ignore")
        for block in TITLE_BLOCK.findall(fold(text)):
            if TITLE_SUFFIX.search(block):
                found.append(f"{path.relative_to(ROOT).as_posix()}  [title-suffix]")
                break
    return found + code_findings()


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
