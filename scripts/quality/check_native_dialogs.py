#!/usr/bin/env python3
"""
check_native_dialogs.py — Gate 67: native dialogs and inline JavaScript.

1. Native browser dialogs (`alert()`, `confirm()`, `prompt()`) are banned as
   UX: they are unstyled, block the main thread, ignore the Aurora theme and
   cannot be translated beyond the message text. Use the Aurora helpers:

     confirm() → faConfirm(message, { tone }) → Promise<boolean>
                 templates: data-action="submit->ui-actions#confirm"
                            data-ui-actions-message-param="{{ '…'|trans }}"
     prompt()  → faPrompt(message, options)   → Promise<string|null>
                 templates: data-action="submit->ui-actions#promptSubmit"
     alert()   → faToast(message, 'info'|'success'|'warning'|'danger')

   JS modules import them: `import { faConfirm } from '../js/fa-alerts.js';`
   — the import guarantees the helper is mounted, so there is no native
   fallback any more. data-turbo-confirm is routed through faConfirm by
   assets/app.js.

2. Inline JavaScript is blocked by the Content-Security-Policy (script-src
   has no 'unsafe-inline'; SecurityHeadersSubscriber), so templates must not
   contain:
     - inline event handler attributes (`onclick="…"`, `onchange="…"`, …),
       also not as Twig attr-map keys (`attr: { onclick: '…' }`)
       → Stimulus `data-action` (generic helpers: ui-actions controller);
     - `javascript:` URLs → a button with a Stimulus action;
     - an executable inline `<script>` without the per-request nonce
       → `<script nonce="{{ csp_nonce() }}">`.
   In JS modules, building an `on…=` attribute into HTML strings or via
   setAttribute('on…') is flagged too, and so is importing a stylesheet
   (`import '…/x.css'`): AssetMapper maps CSS imports to data: URLs, which
   script-src does not allow — link the stylesheet in the layout instead.

Scope: assets/**/*.js (minus vendor) and templates/**/*.twig. Comments are
ignored, as are method calls on other objects (`this.confirm()`,
`deferredPrompt.prompt()`, `_fa_modal.confirm({…})`) and method definitions.
No baseline: the codebase is clean.

Exit 0 = clean, Exit 1 = violation found.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
GUARD_WINDOW = 8

# A call of the global function: not preceded by an identifier char, `.` or
# `$` — except an explicit `window.` prefix, which is still the global.
RE_CALL = re.compile(r"(?:(?<![\w.$])|(?<=window\.))(alert|confirm|prompt)\s*\(")
RE_METHOD_DEF = re.compile(r"^\s*(?:async\s+|static\s+|#)?(alert|confirm|prompt)\s*\([^)]*\)\s*\{")
# Inline event handler attribute in markup: ` onclick="…"`, `onchange='…'`.
RE_ON_ATTR = re.compile(r"(?:^|[\s\"'/])(on[a-z]{3,})\s*=\s*[\"'{\w]", re.I)
# Inline handler as a Twig hash key (attr maps rendered by macros).
RE_ON_KEY = re.compile(r"(?:^|[\s{,(])['\"]?(on[a-z]{3,})['\"]?\s*:\s*['\"]", re.I)
RE_JS_URL = re.compile(r"['\"]\s*javascript:", re.I)
# Inline handler built in JS: `setAttribute('onclick', …)` or an `on…=` inside a string.
RE_JS_SET_ON = re.compile(r"setAttribute\(\s*['\"`]on[a-z]{3,}['\"`]", re.I)
RE_CSS_IMPORT = re.compile(r"^\s*import\s+(?:[\w{}\s,*]+\s+from\s+)?['\"][^'\"]+\.css['\"]")
RE_SCRIPT = re.compile(r"<script\b([^>]*)>(.*?)</script>", re.S | re.I)
RE_STYLE = re.compile(r"(<style\b[^>]*>)(.*?)(</style>)", re.S | re.I)
NONCE_ATTR = 'nonce="{{ csp_nonce() }}"'
# Script types the browser executes (others are data blocks: JSON, text/php for dompdf, …).
EXECUTABLE_TYPES = {"", "text/javascript", "application/javascript", "module"}


def _blank(text: str) -> str:
    return re.sub(r"[^\n]", " ", text)


def _strip_js_comments(text: str) -> str:
    """Blank out // and /* */ comments, keeping line numbers.

    Line-based on purpose: a full tokenizer trips over regex literals; a
    quote tracker per line is enough to keep `'http://…'` intact.
    """
    out: list[str] = []
    in_block = False
    for line in text.split("\n"):
        res: list[str] = []
        i, n = 0, len(line)
        quote: str | None = None
        while i < n:
            if in_block:
                j = line.find("*/", i)
                if j == -1:
                    i = n
                    break
                in_block = False
                i = j + 2
                continue
            c = line[i]
            if quote:
                res.append(c)
                if c == "\\" and i + 1 < n:
                    res.append(line[i + 1])
                    i += 2
                    continue
                if c == quote:
                    quote = None
                i += 1
                continue
            if c in "'\"`":
                quote = c
            elif line.startswith("//", i):
                break
            elif line.startswith("/*", i):
                in_block = True
                i += 2
                continue
            res.append(c)
            i += 1
        out.append("".join(res))
    return "\n".join(out)


def _strip_twig_comments(text: str) -> str:
    """Blank out Twig comments and tags ({% macro confirm(...) %} is no JS call)."""
    return re.sub(r"\{#.*?#\}|\{%.*?%\}", lambda m: re.sub(r"[^\n]", " ", m.group(0)), text, flags=re.S)


def _line_of(text: str, pos: int) -> int:
    return text.count("\n", 0, pos) + 1


def _scan_dialogs(text: str) -> list[tuple[int, str]]:
    hits: list[tuple[int, str]] = []
    for idx, line in enumerate(text.splitlines()):
        if RE_METHOD_DEF.match(line):
            continue
        for m in RE_CALL.finditer(line):
            hits.append((idx + 1, f"native {m.group(1)}() — use faConfirm/faPrompt/faToast"))
    return hits


def _scan_js_inline_handlers(text: str) -> list[tuple[int, str]]:
    hits: list[tuple[int, str]] = []
    for idx, line in enumerate(text.splitlines()):
        if RE_JS_SET_ON.search(line):
            hits.append((idx + 1, "inline event handler set via setAttribute — use addEventListener"))
            continue
        # `on…=` inside a string literal that builds markup (`<button onclick="…">`).
        for m in re.finditer(r"<[a-z][^<>]*?\s(on[a-z]{3,})\s*=", line, re.I):
            hits.append((idx + 1, f"inline {m.group(1)}= handler in generated markup — use addEventListener"))
    return hits


def scan_js(path: Path) -> list[tuple[int, str]]:
    text = _strip_js_comments(path.read_text(encoding="utf-8", errors="ignore"))
    css = [
        (idx + 1, "CSS import in a JS module needs script-src data: — link the stylesheet in the layout")
        for idx, line in enumerate(text.splitlines())
        if RE_CSS_IMPORT.match(line)
    ]
    return sorted(_scan_dialogs(text) + _scan_js_inline_handlers(text) + css)


def scan_twig(path: Path) -> list[tuple[int, str]]:
    raw = path.read_text(encoding="utf-8", errors="ignore")
    text = _strip_twig_comments(raw)
    hits: list[tuple[int, str]] = []

    # Inline <script>: executable ones need the CSP nonce; JS rules apply to the body.
    for m in RE_SCRIPT.finditer(text):
        attrs = m.group(1)
        if re.search(r"\ssrc\s*=", attrs, re.I):
            continue
        type_m = re.search(r"\stype\s*=\s*[\"']([^\"']*)[\"']", attrs, re.I)
        script_type = (type_m.group(1) if type_m else "").strip().lower()
        if script_type not in EXECUTABLE_TYPES:
            continue
        line = _line_of(text, m.start())
        if NONCE_ATTR not in raw[m.start():m.start() + len("<script") + len(attrs) + 1]:
            hits.append((line, f'inline <script> without {NONCE_ATTR} (blocked by the CSP)'))
        body = _strip_js_comments(m.group(2))
        offset = line - 1 + m.group(0)[: m.start(2) - m.start()].count("\n")
        hits += [(ln + offset, msg) for ln, msg in _scan_dialogs(body) + _scan_js_inline_handlers(body)]

    # Markup rules on the template with script and style bodies blanked out.
    markup = RE_SCRIPT.sub(lambda m: _blank(m.group(0)), text)
    markup = RE_STYLE.sub(lambda m: m.group(1) + _blank(m.group(2)) + m.group(3), markup)
    for idx, line in enumerate(markup.splitlines()):
        for m in RE_ON_ATTR.finditer(line):
            hits.append((idx + 1, f"inline {m.group(1)}= handler — use a Stimulus data-action"))
        for m in RE_ON_KEY.finditer(line):
            hits.append((idx + 1, f"inline {m.group(1)} handler in a Twig attr map — use a Stimulus data-action"))
        if RE_JS_URL.search(line):
            hits.append((idx + 1, "javascript: URL — use a button with a Stimulus data-action"))
        for m in RE_CALL.finditer(line):
            # A dialog call outside <script> can only sit in an attribute value.
            hits.append((idx + 1, f"native {m.group(1)}() — use faConfirm/faPrompt/faToast"))
    return sorted(hits)


def _default_paths() -> list[Path]:
    js = [p for p in sorted((ROOT / "assets").rglob("*.js")) if "vendor" not in p.relative_to(ROOT).parts]
    return js + sorted((ROOT / "templates").rglob("*.twig"))


def scan(paths: list[Path] | None = None) -> list[tuple[Path, int, str]]:
    findings: list[tuple[Path, int, str]] = []
    for path in paths if paths is not None else _default_paths():
        hits = scan_twig(path) if path.suffix == ".twig" else scan_js(path)
        findings += [(path, ln, msg) for ln, msg in hits]
    return findings


def _rel(p: Path) -> str:
    try:
        return str(p.resolve().relative_to(ROOT))
    except ValueError:
        return p.name


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--quiet", action="store_true")
    ap.add_argument("--paths", nargs="+", type=Path, help="scan only these files (tests)")
    args = ap.parse_args()

    findings = scan(args.paths)
    if not findings:
        print("check_native_dialogs: OK" + ("" if args.quiet else " — no native dialogs, no inline JavaScript."))
        return 0

    print("check_native_dialogs: VIOLATIONS\n")
    for p, ln, msg in findings:
        print(f"FAIL {_rel(p)}:{ln}: {msg}")
    print(f"\ncheck_native_dialogs: {len(findings)} violation(s).")
    print("Fix: dialogs → faConfirm/faPrompt/faToast (import from assets/js/fa-alerts.js; templates:")
    print("ui-actions#confirm / #promptSubmit); inline handlers → Stimulus data-action (ui-actions);")
    print('inline <script> → nonce="{{ csp_nonce() }}".')
    return 1


if __name__ == "__main__":
    sys.exit(main())
