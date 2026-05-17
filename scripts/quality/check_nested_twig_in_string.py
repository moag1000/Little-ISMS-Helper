#!/usr/bin/env python3
"""
check_nested_twig_in_string.py — Gate 17.

Detects Twig `{{ … }}` print-expressions written INSIDE string literals
that are passed to macros / includes. Twig does NOT recursively render
strings — the inner `{{ … }}` is emitted as literal text in the page
(hit: incident/new NIS2 timeline alert showed raw
`{{ 'incident.nis2_timeline.title'|trans({}, 'incident') }}` instead of
the translated label).

Pattern detected:
  body: '…{{ \\'foo.bar\\'|trans … }}…'
  title: "…{{ … }}…"
  message: '…{{ … }}…'

Conservative — only flags `body:`, `title:`, `message:`, `html:`, or
`subtitle:` keys with a literal string containing `{{`. Other string
literals (HTML snippets via |raw filter etc.) are allowed because the
caller would need to handle them anyway.

Exit 0 = clean, Exit 1 = new violations.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TEMPLATES_DIR = ROOT / "templates"

# key: 'value-with-{{ }}-inside'    or    key: "value-with-{{ }}-inside"
# Capture only macro/include arg-keys; reject generic Twig string args.
KEYS = r"(?:body|title|message|html|subtitle|description|tooltip|placeholder|label)"
# Match key: '...{{ ... |trans ... }}...' — the unambiguous bug-pattern.
# CSS `content:` and arbitrary `text:` props are intentionally excluded;
# this gate only targets cases where a |trans-call lives inside a string
# value (silent literal output instead of translated label).
RE_KEY_STRING_TWIG = re.compile(
    rf"\b{KEYS}\s*:\s*(?P<quote>['\"])"
    r"(?P<body>(?:\\.|(?!(?P=quote)).)*?\{\{(?:\\.|(?!(?P=quote)).)*?\|trans(?:\\.|(?!(?P=quote)).)*?)"
    r"(?P=quote)",
    re.DOTALL,
)

RE_TWIG_COMMENT = re.compile(r"\{#.*?#\}", re.DOTALL)


def _strip_comments(text: str) -> str:
    return RE_TWIG_COMMENT.sub(lambda m: re.sub(r"[^\n]", " ", m.group(0)), text)


def scan() -> list[tuple[Path, int, str]]:
    findings: list[tuple[Path, int, str]] = []
    for tpl in TEMPLATES_DIR.rglob("*.html.twig"):
        raw = tpl.read_text(encoding="utf-8", errors="ignore")
        text = _strip_comments(raw)
        for m in RE_KEY_STRING_TWIG.finditer(text):
            ln = text.count("\n", 0, m.start()) + 1
            snippet = m.group(0)[:120].replace("\n", " ")
            findings.append((tpl, ln, snippet))
    return findings


def load_baseline(path: Path | None) -> set[str]:
    if path is None or not path.exists():
        return set()
    return {
        s.strip() for s in path.read_text(encoding="utf-8").splitlines()
        if s.strip() and not s.strip().startswith("#")
    }


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

    findings = scan()
    keys = [f"{_rel(p)}:{ln}" for p, ln, _ in findings]

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_nested_twig_in_string.py baseline\n")
            fh.write("# Format: <template>:<line>\n")
            for k in keys:
                fh.write(k + "\n")
        print(f"check_nested_twig_in_string: wrote {len(keys)} entries")
        return 0

    baseline = load_baseline(args.baseline)
    new = [(p, ln, snip) for (p, ln, snip), k in zip(findings, keys) if k not in baseline]
    total = len(findings)
    baselined = total - len(new)

    if not new:
        if not args.quiet:
            print(f"check_nested_twig_in_string: OK — {total} legacy, {baselined} baselined.")
        else:
            print(f"check_nested_twig_in_string: OK ({total}, all baselined)")
        return 0

    print("check_nested_twig_in_string: VIOLATIONS\n")
    for p, ln, snip in new:
        print(f"FAIL {_rel(p)}:{ln}: {snip}")
    print(f"\ncheck_nested_twig_in_string: {len(new)} new ({baselined} baselined, {total} total).")
    print("Fix: pass the translated string as the macro-key value directly:")
    print('  title: \'foo.bar\'|trans({}, \'domain\'),')
    print('  body:  \'foo.baz\'|trans({}, \'domain\'),')
    return 1


if __name__ == "__main__":
    sys.exit(main())
