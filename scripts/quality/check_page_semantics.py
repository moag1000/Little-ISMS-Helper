#!/usr/bin/env python3
"""
check_page_semantics.py — Gate 78: page semantics and one status vocabulary.

UI wave G. Seven rules:
- nested-main: base.html.twig renders `<main id="main-content">`; a template
  that extends it and opens its own `<main>` nests landmarks (screen readers
  announce two main regions).
- extra-h1: the page header macro renders the page's `<h1>`; a template that
  uses it and adds another `<h1>` has two.
- status-map: a local colour map (`{% set <name>Colors|Tones|Variants = {...} %}`,
  any name ending in colors/tones/variants, or any map literal looked up on the
  spot like `{draft: 'secondary', …}[x.status]` or `{% set status_class = {…} %}`)
  whose values are tone names
  (danger/warning/success/info/primary/secondary/neutral/accent) instead of
  `pill_tone_severity()` (severity/criticality/priority scales),
  _components/_status_vocabulary.html.twig (non-lifecycle statuses) or
  _fa_status_chip (lifecycle entities).
- ternary-tone (T2): a tone chosen by a ternary chain on a string comparison,
  `x.status == 'open' ? 'danger' : (x.status == 'done' ? 'success' : 'neutral')`,
  is the same local status map written inline (two or more tone literals in
  one `?:` expression with `==`/`!=`/`in`, across lines too). Numeric
  thresholds (`n < 10 ? 'warning' : 'danger'`) are not statuses and pass.
- pill-tone (T2): the status pill knows primary/accent/success/warning/danger/
  neutral only; `pill.render(label, 'info')` / `'secondary'` renders an
  unstyled pill.
  T4: every alias counts (`import … as sp`, `{% from … import render as sp_render %}`, the `_fa_status_pill` file
  name too), `tone:` as a named argument, and a tone kept in a `{% set t = 'info' %}` variable.
- if-chain-tone (T4): `{% if x.status == 'open' %}{% set t = 'danger' %}{% elseif … %}{% set t = 'success' %}…`
  assigns a tone per status in an if chain, the same local map written as statements.
- tooltip-unreachable: a `data-bs-toggle="tooltip"` on an element that is not
  focusable (no tabindex, not a/button/input) can only be read by mouse.

Templates that are documents of their own (layouts, PDFs, mails, standalone
pages without `{% extends %}`) may have their own `<main>`/`<h1>`.
Remaining offenders are baselined and may only shrink.

    python3 scripts/quality/check_page_semantics.py --baseline scripts/quality/baselines/page_semantics.txt
    python3 scripts/quality/check_page_semantics.py --write-baseline scripts/quality/baselines/page_semantics.txt
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TEMPLATES = ROOT / "templates"
# Layouts whose children inherit a <main>.
# (either quote style, `{%-` whitespace control; the public/auth/setup layouts open a <main> too)
MAIN_LAYOUTS = re.compile(
    r"""\{%-?\s*extends\s+['"](?:base|admin/layout|base_public|base_auth|setup/_layout)\.html\.twig['"]"""
)
PAGE_HEADER = re.compile(r"_fa_page_header\.(render|header)\(")
MAIN_OPEN = re.compile(r"<main[\s>]|role\s*=\s*[\"']main[\"']", re.I)
H1 = re.compile(r"<h1[\s>]", re.I)
STATUS_MAP = re.compile(
    r"\{%-?\s*set\s+_?(?!\w*(?:btn|button))\w*(?:[cC]olors?|[tT]ones?|[vV]ariants?)\s*=\s*\{([^{}]*)\}"
)
# Map literals looked up in place or stored under any name (`status_class`, `statusClass`, `x_map`).
INDEXED_MAP = re.compile(r"\{([^{}%]*)\}\s*\[")
SET_MAP = re.compile(r"\{%-?\s*set\s+(?!\w*(?:btn|button))\w+\s*=\s*\{([^{}]*)\}")
# PDFs and mails style their own markup; button-variant maps are not statuses.
IS_DOCUMENT = re.compile(r"(_pdf|/pdf/|/emails?/)")
BUTTON_MAP = re.compile(r"btn|button")
VOCABULARY = "templates/_components/_status_vocabulary.html.twig"
# Tone names as map values: either quote style, also as Bootstrap classes ('bg-success', 'text-bg-danger', 'badge-info').
TONE_VALUE = re.compile(
    r""":\s*['"](?:(?:text-bg-|bg-|badge-|text-)?)(?:danger|warning|success|info|primary|secondary|neutral|accent)['"]"""
)
TWIG_EXPR = re.compile(r"\{\{.*?\}\}|\{%.*?%\}", re.S)
TONE_WORD = r"(?:text-bg-|bg-|badge-|text-|fa-status-pill--)?(?:danger|warning|success|info|primary|secondary|neutral|accent)"
# `== 'open' ? 'danger'` / `in ['a', 'b']) ? 'success'`
TERNARY_TONE = re.compile(
    r"""(?:==|!=|\bnot\s+in|\bin|\bmatches|\bstarts\s+with|\bends\s+with|\bis\s+(?:not\s+)?same\s+as)\s*(?:'[^']*'|"[^"]*"|\[[^\]]*\])\s*\)?\s*\?\s*['"]""" + TONE_WORD + r"""['"]"""
)
ELSE_TONE = re.compile(r""":\s*['"]""" + TONE_WORD + r"""['"]""")
_PILL_FILE = r"""['"]_components/_(?:fa_)?status_pill\.html\.twig['"]"""
PILL_IMPORT = re.compile(r"import\s+" + _PILL_FILE + r"\s+as\s+(\w+)")
PILL_FROM_IMPORT = re.compile(r"\{%-?\s*from\s+" + _PILL_FILE + r"\s+import\s+([^%]*?)\s*-?%\}")
TONE_SET = re.compile(r"\{%-?\s*set\s+(\w+)\s*=\s*['\"](info|secondary)['\"]\s*-?%\}")
IF_TAG = re.compile(r"\{%-?\s*(?:else)?if\s+(.*?)-?%\}", re.S)
SET_TONE = re.compile(r"\{%-?\s*set\s+(\w+)\s*=\s*['\"]" + TONE_WORD + r"['\"]\s*-?%\}")
PILL_BAD_TONE = re.compile(r"""['"](info|secondary)['"]""")
COMPARISON_OPERAND = re.compile(r"""(?:==|!=|\bnot\s+in|\bin|\bmatches|\bstarts\s+with|\bends\s+with)\s*(?:'[^']*'|"[^"]*"|\[[^\]]*\])""")
TOOLTIP = re.compile(r"""<(\w+)\b[^>]*data-bs-toggle\s*=\s*["']tooltip["'][^>]*>""", re.S)
TABINDEX = re.compile(r"\stabindex\s*=")
DISABLED = re.compile(r"\sdisabled\b")
FOCUSABLE = {"a", "button", "input", "select", "textarea"}
# Print-only copies and JS print windows are not part of the page outline.
PRINT_ONLY = re.compile(r"<(div|header|section)[^>]*\bd-none d-print-block\b.*?</\1>|<script\b.*?</script>", re.S)


def _call_args(text: str, start: int) -> list[str]:
    """Top-level arguments of the call whose `(` ends right before `start`."""
    depth, cur, out, quote = 1, [], [], None
    for ch in text[start:]:
        if quote:
            cur.append(ch)
            if ch == quote:
                quote = None
            continue
        if ch in "'\"":
            quote = ch
        elif ch in "([{":
            depth += 1
        elif ch in ")]}":
            depth -= 1
            if depth == 0:
                out.append("".join(cur))
                return out
        elif ch == "," and depth == 1:
            out.append("".join(cur))
            cur = []
            continue
        cur.append(ch)
    return out


def findings() -> list[str]:
    out = []
    for p in sorted(TEMPLATES.rglob("*.html.twig")):
        rel = str(p.relative_to(ROOT))
        text = p.read_text(encoding="utf-8")
        if rel != VOCABULARY and (
            any(TONE_VALUE.search(m.group(1)) for m in STATUS_MAP.finditer(text))
            or (not IS_DOCUMENT.search(rel) and any(
                len(TONE_VALUE.findall(m.group(1))) >= 2 and not BUTTON_MAP.search(text[text.rfind("\n", 0, m.start()):m.start()])
                for rx in (INDEXED_MAP, SET_MAP) for m in rx.finditer(text)
            ))
        ):
            out.append(f"{rel}::status-map")
        if rel != VOCABULARY and not IS_DOCUMENT.search(rel):
            for m in TWIG_EXPR.finditer(text):
                hits = len(TERNARY_TONE.findall(m.group(0)))
                if hits >= 2 or (hits == 1 and ELSE_TONE.search(m.group(0))):
                    out.append(f"{rel}::ternary-tone")
                    break
        bad_vars = {m.group(1) for m in TONE_SET.finditer(text)}
        aliases = [a + r"\.render" for a in PILL_IMPORT.findall(text)]
        for m in PILL_FROM_IMPORT.finditer(text):  # {% from '…_status_pill…' import render as sp_render %}
            aliases += [re.escape(b or a) for a, b in re.findall(r"\b(render)\b(?:\s+as\s+(\w+))?", m.group(1))]
        for alias in aliases:
            for m in re.finditer(r"(?<![\w.])" + alias + r"\(", text):
                args = _call_args(text, m.end())
                tone = args[1] if len(args) > 1 else next((a.split(":", 1)[1] for a in args if re.match(r"\s*tone\s*:", a)), "")
                stripped = COMPARISON_OPERAND.sub("", tone)
                if PILL_BAD_TONE.search(stripped) or (re.fullmatch(r"\s*(\w+)\s*", stripped) and stripped.strip() in bad_vars):
                    out.append(f"{rel}::pill-tone")
                    break
            else:
                continue
            break
        chain = [m for m in SET_TONE.finditer(text)]
        for name in {m.group(1) for m in chain}:
            sets = [m for m in chain if m.group(1) == name]
            if len(sets) < 2:
                continue
            between = text[sets[0].end():sets[-1].start()]
            conds = [c.group(1) for c in IF_TAG.finditer(text[max(0, sets[0].start() - 400):sets[-1].start()])]
            if re.search(r"\{%-?\s*(?:else)?if\b", between) and any(COMPARISON_OPERAND.search(c) for c in conds):
                out.append(f"{rel}::if-chain-tone")
                break
        # Mask Twig expressions so a `>` inside `{{ a > b }}` does not end the tag early.
        masked = TWIG_EXPR.sub(lambda m: "_" * len(m.group(0)), text)
        for m in TOOLTIP.finditer(masked):
            tag, html = m.group(1).lower(), m.group(0)
            # a link without href and a disabled button are not in the tab order either
            reachable = (
                TABINDEX.search(html)
                or (tag in FOCUSABLE and not (tag == "button" and DISABLED.search(html))
                    and not (tag == "a" and "href" not in html))
            )
            if not reachable:
                out.append(f"{rel}::tooltip-unreachable")
                break
        if not MAIN_LAYOUTS.search(text):
            continue
        if MAIN_OPEN.search(text):
            out.append(f"{rel}::nested-main")
        outline = PRINT_ONLY.sub("", text)
        h1 = len(H1.findall(outline))
        if h1 > 1 or (h1 >= 1 and PAGE_HEADER.search(text)):
            out.append(f"{rel}::extra-h1")
    return out


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--baseline")
    ap.add_argument("--write-baseline")
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    current = findings()
    if args.write_baseline:
        Path(args.write_baseline).write_text(
            "# Page-semantics offenders (Gate 78): <template>::nested-main|extra-h1|status-map|ternary-tone|if-chain-tone|pill-tone|tooltip-unreachable. May only shrink.\n"
            + "".join(f"{f}\n" for f in current),
            encoding="utf-8",
        )
        print(f"check_page_semantics: wrote {len(current)} entries")
        return 0

    baseline: set[str] = set()
    if args.baseline:
        for line in Path(args.baseline).read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if line and not line.startswith("#"):
                baseline.add(line)

    new = [f for f in current if f not in baseline]
    stale = sorted(baseline - set(current))
    if new:
        print("check_page_semantics: new findings (see the docstring for each rule):")
        for f in new:
            print(f"  {f}")
    if stale:
        print("check_page_semantics: fixed entries still in the baseline — remove them:")
        for f in stale:
            print(f"  {f}")
    if not new and not stale and not args.quiet:
        print(f"check_page_semantics: OK ({len(current)} baselined)")
    return 1 if new or stale else 0


if __name__ == "__main__":
    sys.exit(main())
