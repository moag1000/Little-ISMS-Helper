#!/usr/bin/env python3
"""
check_a11y_static.py — Gate 103 (markup part): the accessibility rules a
template can break without anybody noticing in a browser.

W11, 2026-11: the a11y sweep found 162 `<th>` without `scope`, 26 of 28 charts
without a text alternative, 22 icon-only buttons without a name, a second
`role="banner"` on every page and three clickable `<div>`s no keyboard reaches.

Checks over templates/ (dev-only templates under templates/dev/ excluded):

  th-scope     every `<th>` carries `scope="col|row|colgroup|rowgroup"`
  canvas       every `<canvas>` has `role="img"` plus `aria-label` or
               `aria-labelledby` (WCAG 1.1.1) — a `chart` controller canvas may
               give `data-chart-label-value` instead, the controller sets both
  icon-only    a `<button>` / `<a>` whose content is only icons (no text, no
               `{{ … }}` output, no visually-hidden text) needs `aria-label`,
               `aria-labelledby` or `title` (WCAG 4.1.2)
  banner       `role="banner"` only in base layouts (one banner per page)
  click        `data-action="click->…"` on a non-interactive element
               (div, span, li, tr, td, p, section, article, img, i, header) needs
               `role="button"` (or link/tab/…) and `tabindex="0"` (WCAG 2.1.1);
               backdrop/close handlers and `row-click#navigate` (follows the
               row's link) are exempt

A line may opt out with `{# a11y-ok: <reason> #}` on the same or the line before.

Exit-codes: 0 clean, 1 violations, 2 I/O error.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TEMPLATES = ROOT / "templates"
EXCLUDED_DIRS = {"dev"}
BANNER_ALLOWED = {"base.html.twig", "base_auth.html.twig", "base_public.html.twig"}
NON_INTERACTIVE = {"div", "span", "li", "tr", "td", "p", "section", "article", "img", "i", "header", "footer", "ul"}
INTERACTIVE_ROLES = {"button", "link", "tab", "menuitem", "checkbox", "switch", "option", "radio", "treeitem"}

# A click on a dialog backdrop closes it; keyboard users close with Escape.
BACKDROP_METHOD = re.compile(r"backdrop|close|dismiss|outside|stopPropagation", re.I)
# Delegation: the handler follows the link inside the row, which the keyboard reaches itself.
DELEGATING_CLICKS = {("row-click", "navigate")}

OPT_OUT = re.compile(r"\{#\s*a11y-ok:\s*\S.*?#\}")
TWIG_COMMENT = re.compile(r"\{#.*?#\}", re.S)
TWIG_TAG = re.compile(r"\{%.*?%\}", re.S)
TWIG_EXPR = re.compile(r"\{\{.*?\}\}", re.S)


def blank(match: re.Match[str]) -> str:
    """Keep offsets and newlines, drop the text."""
    return "".join("\n" if c == "\n" else " " for c in match.group(0))


def open_tags(text: str, name: str):
    """Yield (start, end, tag_text) of every `<name …>` open tag; `>` inside quotes or Twig is skipped."""
    for m in re.finditer(r"<" + name + r"(?=[\s>/])", text, re.I):
        i, quote, depth = m.end(), None, 0
        while i < len(text):
            two = text[i:i + 2]
            if depth == 0 and quote is None and two in ("{{", "{%"):
                depth, i = 1, i + 2
                continue
            if depth and two in ("}}", "%}"):
                depth, i = 0, i + 2
                continue
            ch = text[i]
            if not depth:
                if quote:
                    if ch == quote:
                        quote = None
                elif ch in "\"'":
                    quote = ch
                elif ch == ">":
                    yield m.start(), i + 1, text[m.start():i + 1]
                    break
            i += 1


def attr(tag: str, name: str) -> str | None:
    m = re.search(r"(?<![\w-])" + re.escape(name) + r"""\s*=\s*(?:"([^"]*)"|'([^']*)')""", tag, re.S)
    if m:
        return m.group(1) if m.group(1) is not None else m.group(2)
    return "" if re.search(r"(?<![\w-])" + re.escape(name) + r"(?=[\s>/])", tag) else None


def has_name(tag: str) -> bool:
    return any(attr(tag, a) for a in ("aria-label", "aria-labelledby", "title")) or \
        any(attr(tag, a) is not None and "{{" in (attr(tag, a) or "") for a in ("aria-label", "title"))


def visible_text(inner: str) -> str:
    """Text a screen reader would read from the content of a button/link."""
    inner = TWIG_TAG.sub(" ", inner)
    # aria-hidden subtrees and <svg>/<i> icons say nothing
    inner = re.sub(r"<(\w+)[^>]*aria-hidden\s*=\s*[\"']true[\"'][^>]*>.*?</\1>", " ", inner, flags=re.S | re.I)
    inner = re.sub(r"<(\w+)[^>]*aria-hidden\s*=\s*[\"']true[\"'][^>]*/?>", " ", inner, flags=re.S | re.I)
    inner = re.sub(r"<svg\b.*?</svg>", " ", inner, flags=re.S | re.I)
    inner = re.sub(r"<i\b[^>]*>\s*</i>", " ", inner, flags=re.S | re.I)
    # an <img alt="…"> names its link
    alts = " ".join(a for a in re.findall(r"<img\b[^>]*\balt\s*=\s*[\"']([^\"']+)[\"']", inner, re.I))
    inner = re.sub(r"<[^>]+>", " ", inner)
    return (inner + " " + alts).strip()


def exempt_click(controller: str, method: str) -> bool:
    """Backdrop/close clicks (Escape closes the dialog) and row delegation to the row's own link."""
    return bool(BACKDROP_METHOD.search(method)) or (controller, method) in DELEGATING_CLICKS


def check_file(path: Path) -> list[str]:
    raw = path.read_text(encoding="utf-8")
    lines = raw.splitlines()
    text = TWIG_COMMENT.sub(blank, raw)
    rel = path.relative_to(ROOT) if path.is_relative_to(ROOT) else path
    out: list[str] = []

    def lineno(pos: int) -> int:
        return text.count("\n", 0, pos) + 1

    def report(pos: int, rule: str, msg: str) -> None:
        no = lineno(pos)
        context = "\n".join(lines[max(0, no - 2):no])
        if OPT_OUT.search(context):
            return
        out.append(f"{rel}:{no}: [{rule}] {msg}")

    for start, _end, tag in open_tags(text, "th"):
        if not re.search(r"(?<![\w-])scope\s*=", tag):
            report(start, "th-scope", '<th> without scope="col" / "row"')

    for start, _end, tag in open_tags(text, "canvas"):
        labelled = attr(tag, "aria-label") or attr(tag, "aria-labelledby")
        chart = "chart" in (attr(tag, "data-controller") or "").split() and attr(tag, "data-chart-label-value")
        if not chart and not (attr(tag, "role") == "img" and labelled):
            report(start, "canvas", '<canvas> without role="img" + aria-label (or data-chart-label-value)')

    for name in ("button", "a"):
        for start, end, tag in open_tags(text, name):
            close = re.compile(r"</" + name + r"\s*>", re.I).search(text, end)
            if not close:
                continue
            inner = text[end:close.start()]
            if TWIG_EXPR.search(inner) or has_name(tag):
                continue
            if re.search(r"class\s*=\s*[\"'][^\"']*\b(?:visually-hidden|sr-only)\b", inner):
                continue
            if not re.search(r"\w", visible_text(inner)):
                report(start, "icon-only", f"<{name}> with only an icon needs aria-label (or title)")

    if path.name not in BANNER_ALLOWED:
        for m in re.finditer(r"role\s*=\s*[\"']banner[\"']", text):
            report(m.start(), "banner", 'role="banner" outside the base layout — the page already has one')

    for name in NON_INTERACTIVE:
        for start, _end, tag in open_tags(text, name):
            action = attr(tag, "data-action") or ""
            clicks = re.findall(r"(?:^|\s)click->([\w-]+)#(\w+)", action)
            if not clicks or all(exempt_click(c, m) for c, m in clicks):
                continue
            role = (attr(tag, "role") or "").strip()
            if role not in INTERACTIVE_ROLES or attr(tag, "tabindex") is None:
                report(start, "click", f'click handler on <{name}> without role="button" + tabindex="0"')
    return out


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--quiet", action="store_true")
    parser.add_argument("paths", nargs="*", type=Path, help="templates to check (default: all)")
    args = parser.parse_args()
    files = [p.resolve() for p in args.paths] or sorted(
        p for p in TEMPLATES.rglob("*.twig") if not EXCLUDED_DIRS & set(p.relative_to(TEMPLATES).parts[:1])
    )
    problems: list[str] = []
    try:
        for f in files:
            problems.extend(check_file(f))
    except OSError as exc:
        print(f"check_a11y_static: {exc}", file=sys.stderr)
        return 2
    if problems:
        print(f"Gate 103 (markup): {len(problems)} problem(s)")
        for p in problems:
            print(f"  {p}")
        return 1
    if not args.quiet:
        print(f"Gate 103 (markup): {len(files)} templates clean")
    return 0


if __name__ == "__main__":
    sys.exit(main())
