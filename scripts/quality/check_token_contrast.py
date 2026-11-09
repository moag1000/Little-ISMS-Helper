#!/usr/bin/env python3
"""
check_token_contrast.py — Gate 103 (contrast part): the colour tokens of
FairyAurora v4 meet WCAG 2.2 AA in light AND dark.

W11, 2026-11: status pills had 2.95:1 (success), muted text in dark mode 4.02:1
and input borders 1.28:1 — computed from the tokens, never noticed because no
check looked at pairs.

The gate reads `assets/styles/fairy-aurora.css`, resolves every custom property
per theme (light = `:root`, dark = `html[data-theme="dark"]`, system =
`html[data-theme="system"]` inside `prefers-color-scheme: dark`; dark/system fall
back to light for tokens they do not redefine) and checks fixed pairs:

  text      every text token on every surface               >= 4.5:1  (WCAG 1.4.3)
  pill      status-pill text on its tinted pill background   >= 4.5:1  (pill CSS in
            fairy-aurora-components.css is read, so a pill pointing at another
            token is checked with that token)
  on-color  --on-X on the filled button colour --X-strong    >= 4.5:1
  border    --input-border on every surface                  >= 3:1    (WCAG 1.4.11)

Bootstrap's `.text-primary/-info/-success/-warning/-danger` must be mapped to the
text tokens in fairy-aurora-components.css (Bootstrap uses the display tone).

It also fails when a stylesheet sets `color:` to a display token (`--primary`,
`--success`, `--warning`, `--danger`, `--info`, `--accent`): those are meant for
icons, borders and fills (AA-Large) and fall below 4.5:1 as text; text takes
the `-strong` variant (`--warning-text` for warning).

Exit-codes: 0 clean, 1 violations, 2 I/O error.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TOKENS = ROOT / "assets" / "styles" / "fairy-aurora.css"
COMPONENTS = ROOT / "assets" / "styles" / "fairy-aurora-components.css"
STYLES = ROOT / "assets" / "styles"

SURFACES = ["--bg", "--surface", "--surface-2", "--surface-3"]
TEXT_TOKENS = [
    "--fg", "--fg-2", "--fg-3",
    "--primary-strong", "--accent-strong", "--success-strong",
    "--warning-text", "--danger-strong",
]
FILLS = ["primary", "accent", "success", "danger"]  # --on-X on --X-strong
PILL_TONES = ["primary", "accent", "success", "warning", "danger", "neutral"]
DISPLAY_TOKENS = ["primary", "success", "warning", "danger", "info", "accent"]
# Bootstrap's .text-X reads --bs-X-rgb (the display tone); the components sheet maps it to a text token.
BOOTSTRAP_TEXT = {
    "primary": "--primary-strong", "info": "--primary-strong", "success": "--success-strong",
    "warning": "--warning-text", "danger": "--danger-strong",
}
TEXT_MIN = 4.5
BORDER_MIN = 3.0

RE_COMMENT = re.compile(r"/\*.*?\*/", re.S)
RE_DECL = re.compile(r"(--[\w-]+)\s*:\s*([^;]+);")


def blocks(css: str) -> list[tuple[str, str]]:
    """(selector, body) for every innermost rule, @media prefixes kept in the selector."""
    css = RE_COMMENT.sub("", css)
    out: list[tuple[str, str]] = []
    stack: list[str] = []
    buf = ""
    for ch in css:
        if ch == "{":
            stack.append(" ".join(buf.split()))
            buf = ""
        elif ch == "}":
            if stack:
                sel = stack.pop()
                if buf.strip():
                    out.append((" ".join(stack + [sel]), buf))
            buf = ""
        else:
            buf += ch
    return out


def theme_tokens(css: str) -> dict[str, dict[str, str]]:
    themes: dict[str, dict[str, str]] = {"light": {}, "dark": {}, "system": {}}
    for sel, body in blocks(css):
        if "@media print" in sel:
            continue
        if 'data-theme="system"' in sel and "prefers-color-scheme: dark" in sel:
            target = "system"
        elif 'data-theme="dark"' in sel and "@media" not in sel:
            target = "dark"
        elif sel in (":root", "html") or sel.startswith(":root,"):
            target = "light"
        else:
            continue
        for name, value in RE_DECL.findall(body):
            themes[target][name] = " ".join(value.split())
    for t in ("dark", "system"):
        themes[t] = {**themes["light"], **themes[t]}
    return themes


def parse_color(value: str, tokens: dict[str, str], depth: int = 0) -> tuple[float, float, float, float] | None:
    value = value.strip()
    m = re.fullmatch(r"var\((--[\w-]+)\)", value)
    if m:
        if depth > 10 or m.group(1) not in tokens:
            return None
        return parse_color(tokens[m.group(1)], tokens, depth + 1)
    m = re.fullmatch(r"#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})", value)
    if m:
        h = m.group(1)
        if len(h) == 3:
            h = "".join(c * 2 for c in h)
        return (int(h[0:2], 16), int(h[2:4], 16), int(h[4:6], 16), 1.0)
    m = re.fullmatch(r"rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)\s*(?:,\s*([\d.]+)\s*)?\)", value)
    if m:
        return (float(m.group(1)), float(m.group(2)), float(m.group(3)), float(m.group(4) or 1))
    return None


def over(fg: tuple, bg: tuple) -> tuple:
    a = fg[3]
    return (fg[0] * a + bg[0] * (1 - a), fg[1] * a + bg[1] * (1 - a), fg[2] * a + bg[2] * (1 - a), 1.0)


def luminance(c: tuple) -> float:
    def ch(v: float) -> float:
        v /= 255
        return v / 12.92 if v <= 0.03928 else ((v + 0.055) / 1.055) ** 2.4

    return 0.2126 * ch(c[0]) + 0.7152 * ch(c[1]) + 0.0722 * ch(c[2])


def ratio(a: tuple, b: tuple) -> float:
    la, lb = luminance(a), luminance(b)
    hi, lo = max(la, lb), min(la, lb)
    return (hi + 0.05) / (lo + 0.05)


def pill_rules(css: str) -> dict[str, tuple[str, str]]:
    """tone -> (background, color) from `.fa-status-pill--<tone>` rules."""
    rules: dict[str, tuple[str, str]] = {}
    for sel, body in blocks(css):
        m = re.fullmatch(r"\.fa-status-pill--(\w+)", sel)
        if not m or m.group(1) not in PILL_TONES:
            continue
        bg = re.search(r"background\s*:\s*([^;]+);", body)
        fg = re.search(r"(?<![-\w])color\s*:\s*([^;]+);", body)
        if bg and fg:
            rules[m.group(1)] = (bg.group(1).strip(), fg.group(1).strip())
    return rules


def display_text_uses() -> list[str]:
    pattern = re.compile(r"(?<![-\w])color\s*:\s*var\(--(" + "|".join(DISPLAY_TOKENS) + r")\)\s*[;!}]")
    hits = []
    for css_file in sorted(STYLES.rglob("*.css")):
        if "vendor" in css_file.parts or css_file.name.startswith("bootstrap"):
            continue
        text = RE_COMMENT.sub(lambda m: "\n" * m.group(0).count("\n"), css_file.read_text(encoding="utf-8"))
        for no, line in enumerate(text.splitlines(), 1):
            for m in pattern.finditer(line):
                hits.append(f"{css_file.relative_to(ROOT)}:{no}: color: var(--{m.group(1)}) — "
                            f"display token as text, use --{m.group(1)}-strong"
                            + (" / --warning-text" if m.group(1) == "warning" else ""))
    return hits


def check() -> tuple[list[str], list[str]]:
    themes = theme_tokens(TOKENS.read_text(encoding="utf-8"))
    pills = pill_rules(COMPONENTS.read_text(encoding="utf-8"))
    problems: list[str] = []
    table: list[str] = []

    def record(theme: str, kind: str, fg_name: str, bg_name: str, value: float | None, minimum: float) -> None:
        if value is None:
            problems.append(f"[{theme}] {kind}: {fg_name} on {bg_name} — colour not resolvable")
            return
        line = f"[{theme}] {kind:8} {fg_name:22} on {bg_name:26} {value:5.2f}:1 (min {minimum})"
        table.append(line)
        if value + 1e-9 < minimum:
            problems.append(line)

    for theme, tokens in themes.items():
        colors = {name: parse_color(f"var({name})", tokens) for name in tokens}
        for s in SURFACES:
            bg = colors.get(s)
            for t in TEXT_TOKENS:
                fg = colors.get(t)
                record(theme, "text", t, s, ratio(over(fg, bg), bg) if fg and bg else None, TEXT_MIN)
            border = colors.get("--input-border")
            record(theme, "border", "--input-border", s, ratio(over(border, bg), bg) if border and bg else None,
                   BORDER_MIN)
        for f in FILLS:
            fill, on = colors.get(f"--{f}-strong"), colors.get(f"--on-{f}")
            record(theme, "on-color", f"--on-{f}", f"--{f}-strong", ratio(on, fill) if fill and on else None,
                   TEXT_MIN)
        for tone in PILL_TONES:
            if tone not in pills:
                problems.append(f"[{theme}] pill: .fa-status-pill--{tone} rule not found")
                continue
            bg_v, fg_v = pills[tone]
            for s in ("--surface", "--surface-2"):
                base = colors.get(s)
                bg, fg = parse_color(bg_v, tokens), parse_color(fg_v, tokens)
                value = None
                if bg and fg and base:
                    pill_bg = over(bg, base)
                    value = ratio(over(fg, pill_bg), pill_bg)
                record(theme, "pill", f"{tone} ({fg_v})", f"{bg_v} on {s}", value, TEXT_MIN)
    problems.extend(display_text_uses())
    utilities = {sel: body for sel, body in blocks(COMPONENTS.read_text(encoding="utf-8"))}
    for tone, token in BOOTSTRAP_TEXT.items():
        body = utilities.get(f".text-{tone}", "")
        if not re.search(r"(?<![-\w])color\s*:\s*var\(" + re.escape(token) + r"\)\s*!important", body):
            problems.append(f"fairy-aurora-components.css: .text-{tone} must set color: var({token}) !important "
                            "(Bootstrap colours it with the display tone)")
    return problems, table


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--table", action="store_true", help="print every computed pair")
    parser.add_argument("--quiet", action="store_true")
    args = parser.parse_args()
    try:
        problems, table = check()
    except OSError as exc:
        print(f"check_token_contrast: {exc}", file=sys.stderr)
        return 2
    if args.table:
        print("\n".join(table))
    if problems:
        print(f"Gate 103 (contrast): {len(problems)} problem(s)")
        for p in problems:
            print(f"  {p}")
        return 1
    if not args.quiet:
        print(f"Gate 103 (contrast): {len(table)} token pairs meet WCAG AA in light and dark")
    return 0


if __name__ == "__main__":
    sys.exit(main())
