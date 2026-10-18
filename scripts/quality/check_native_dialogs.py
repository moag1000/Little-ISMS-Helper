#!/usr/bin/env python3
"""
check_native_dialogs.py — Gate 67.

Native browser dialogs (`alert()`, `confirm()`, `prompt()`) are banned as UX:
they are unstyled, block the main thread, ignore the Aurora theme and cannot
be translated beyond the message text. The app ships Aurora replacements:

  confirm() → window.faConfirm(message, { tone }) → Promise<boolean>
              templates: data-action="submit->ui-actions#confirm"
                         data-ui-actions-message-param="{{ '…'|trans }}"
  prompt()  → window.faPrompt(message, options)   → Promise<string|null>
              templates: data-action="submit->ui-actions#promptSubmit"
  alert()   → window.faToast(message, 'info'|'success'|'warning'|'danger')
  data-turbo-confirm is routed through faConfirm by assets/app.js.

A native call is allowed ONLY as a guarded worst-case fallback for the moment
the helper is not mounted, i.e. when a `typeof window.faConfirm|faPrompt|faToast`
check appears on the same line or within the GUARD_WINDOW lines above it:

    if (typeof window.faConfirm === 'function') { … } else { window.confirm(msg); }

Scope: assets/**/*.js (minus vendor) and templates/**/*.twig. Comments are
ignored, as are method calls on other objects (`this.confirm()`,
`deferredPrompt.prompt()`, `_fa_modal.confirm({…})`) and method definitions.

Exit 0 = clean, Exit 1 = unguarded native dialog found.
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
RE_GUARD = re.compile(r"typeof\s+window\.fa(?:Confirm|Prompt|Toast)\b")


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


def _scan_text(text: str) -> list[tuple[int, str]]:
    lines = text.splitlines()
    hits: list[tuple[int, str]] = []
    for idx, line in enumerate(lines):
        if RE_METHOD_DEF.match(line):
            continue
        for m in RE_CALL.finditer(line):
            window = "\n".join(lines[max(0, idx - GUARD_WINDOW): idx + 1])
            if RE_GUARD.search(window):
                continue
            hits.append((idx + 1, m.group(1)))
    return hits


def scan() -> list[tuple[Path, int, str]]:
    findings: list[tuple[Path, int, str]] = []
    for js in sorted((ROOT / "assets").rglob("*.js")):
        if "vendor" in js.relative_to(ROOT).parts:
            continue
        text = _strip_js_comments(js.read_text(encoding="utf-8", errors="ignore"))
        findings += [(js, ln, fn) for ln, fn in _scan_text(text)]
    for tpl in sorted((ROOT / "templates").rglob("*.twig")):
        text = _strip_twig_comments(tpl.read_text(encoding="utf-8", errors="ignore"))
        # Inline <script> bodies may carry JS comments too.
        text = re.sub(
            r"(<script\b[^>]*>)(.*?)(</script>)",
            lambda m: m.group(1) + _strip_js_comments(m.group(2)) + m.group(3),
            text,
            flags=re.S | re.I,
        )
        findings += [(tpl, ln, fn) for ln, fn in _scan_text(text)]
    return findings


def _rel(p: Path) -> str:
    try:
        return str(p.relative_to(ROOT))
    except ValueError:
        return p.name


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    findings = scan()
    if not findings:
        print("check_native_dialogs: OK" + ("" if args.quiet else " — no unguarded alert()/confirm()/prompt()."))
        return 0

    print("check_native_dialogs: VIOLATIONS\n")
    for p, ln, fn in findings:
        print(f"FAIL {_rel(p)}:{ln}: native {fn}() without a typeof window.fa* guard")
    print(f"\ncheck_native_dialogs: {len(findings)} unguarded native dialog(s).")
    print("Fix: confirm() → window.faConfirm / ui-actions#confirm; prompt() → window.faPrompt /")
    print("ui-actions#promptSubmit; alert() → window.faToast. Native only as guarded fallback.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
