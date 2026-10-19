#!/usr/bin/env python3
"""
check_design_tokens_sync.py — Keep the app's CSS tokens in sync with the
Design System artifact snapshot.

The design system is maintained as a claude.ai Design System artifact. Its
token layer (`tokens.css`, generated from tokens.json, plus the remaining
tokens in its `fairy-aurora.css`) is snapshotted into
`docs/design_system/artifact/` (see SOURCE.md there for how to refresh it). This gate
compares every custom property in that snapshot against the definitions in
`assets/styles/*.css`, per theme (light / dark):

  - missing  — the artifact defines the token, the app does not
  - differs  — both define it, with different values

Known, accepted deviations live in the baseline. Remove an entry once the app
and the artifact agree; a NEW deviation fails CI, so a token change has to land
on both sides (or be baselined deliberately).

Exit-codes: 0 clean / baselined, 1 violations, 2 I/O error.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SNAPSHOT_DIR = ROOT / "docs" / "design_system" / "artifact"
# Cascade order of the artifact: its fairy-aurora.css @imports tokens.css first,
# then declares the remaining tokens, so later files win.
SNAPSHOT_FILES = [SNAPSHOT_DIR / "tokens.css", SNAPSHOT_DIR / "fairy-aurora.css"]
STYLES = ROOT / "assets" / "styles"
# Canon file first: when several stylesheets define a token, its value wins.
PRIORITY = ["fairy-aurora.css"]
# Not a theme: its :root / html[data-theme="dark"] selector list forces the
# light palette onto paper inside @media print, so it must never be read as a
# definition of either theme.
EXCLUDED_FILES = {"fairy-aurora-print.css"}

RE_COMMENT = re.compile(r"/\*.*?\*/", re.S)
RE_RULE = re.compile(r"([^{}]+)\{([^{}]*)\}")
RE_DECL = re.compile(r"(--[\w-]+)\s*:\s*([^;]+);")


def _theme_of_one(sel: str) -> str | None:
    if 'data-theme="dark"' in sel or 'data-bs-theme="dark"' in sel or 'data-theme="system"' in sel:
        return "dark"
    if sel.startswith(":root") or sel.startswith("html") or sel == '[data-theme="light"]':
        return "light"
    return None


def themes_of(selector: str) -> set[str]:
    """Themes a rule defines. A selector LIST applies to every theme named in it."""
    sel = " ".join(selector.split())
    if "@" in sel:
        return set()
    return {t for part in sel.split(",") if (t := _theme_of_one(part.strip()))}


def theme_of(selector: str) -> str | None:
    """Single-theme view kept for callers: dark wins only if the list is dark-only."""
    themes = themes_of(selector)
    if themes == {"dark"}:
        return "dark"
    if "light" in themes:
        return "light"
    return None


def normalize(value: str) -> str:
    v = " ".join(value.split()).lower()
    v = re.sub(r"\s*,\s*", ",", v)
    # A lone rem length equals its px value at the 16px root size (4px == 0.25rem).
    if m := re.fullmatch(r"(\d*\.?\d+)rem", v):
        v = f"{float(m.group(1)) * 16:g}px"
    return re.sub(r"\(\s*", "(", re.sub(r"\s*\)", ")", v))


def parse(css: str) -> dict[str, dict[str, str]]:
    out: dict[str, dict[str, str]] = {"light": {}, "dark": {}}
    css = RE_COMMENT.sub("", css)
    for m in RE_RULE.finditer(css):
        for theme in themes_of(m.group(1)):
            for d in RE_DECL.finditer(m.group(2)):
                out[theme].setdefault(d.group(1), normalize(d.group(2)))
    return out


def load_app() -> dict[str, dict[str, set[str]]]:
    files = sorted(STYLES.glob("*.css"), key=lambda p: (p.name not in PRIORITY, p.name))
    app: dict[str, dict[str, set[str]]] = {"light": {}, "dark": {}}
    canon: dict[str, dict[str, str]] = {"light": {}, "dark": {}}
    for f in files:
        if f.name in EXCLUDED_FILES:
            continue
        parsed = parse(f.read_text(encoding="utf-8"))
        for theme, decls in parsed.items():
            for k, v in decls.items():
                app[theme].setdefault(k, set()).add(v)
                if f.name in PRIORITY:
                    canon[theme].setdefault(k, v)
    app["_canon"] = canon  # type: ignore[assignment]
    return app


def load_baseline(path: Path | None) -> set[str]:
    if path is None or not path.is_file():
        return set()
    return {
        line.strip()
        for line in path.read_text(encoding="utf-8").splitlines()
        if line.strip() and not line.startswith("#")
    }


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--baseline", type=Path, default=None)
    ap.add_argument("--write-baseline", type=Path, default=None)
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    artifact: dict[str, dict[str, str]] = {"light": {}, "dark": {}}
    for snap in SNAPSHOT_FILES:
        if not snap.is_file():
            print(f"ERROR: {snap} not found", file=sys.stderr)
            return 2
        for theme, decls in parse(snap.read_text(encoding="utf-8")).items():
            artifact[theme].update(decls)
    app = load_app()
    canon = app.pop("_canon")  # type: ignore[arg-type]

    violations: list[tuple[str, str, str, str]] = []  # theme, token, kind, detail
    for theme in ("light", "dark"):
        for token, value in artifact[theme].items():
            if theme == "dark" and token not in app["dark"]:
                # The app inherits the light value in dark mode, so only a
                # dark-specific artifact value needs checking.
                if artifact["light"].get(token) == value:
                    continue
                have = app["light"].get(token)
                canon_value = canon["light"].get(token)
            else:
                have = app[theme].get(token)
                canon_value = canon[theme].get(token)
            if not have:
                violations.append((theme, token, "missing", value))
                continue
            # The canon file (fairy-aurora.css) owns the token: its definition
            # is what the cascade resolves to, so a stale second definition in
            # another stylesheet must not satisfy the check. Only a token the
            # canon file does not define falls back to "any stylesheet agrees".
            if canon_value is not None:
                ok = canon_value == value
                shown = canon_value
            else:
                ok = value in have
                shown = sorted(have)[0]
            if not ok:
                violations.append((theme, token, "differs", f"artifact={value} app={shown}"))

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_design_tokens_sync.py baseline\n")
            fh.write("# Format: <theme>:<token>:<missing|differs>\n")
            fh.write("# Accepted deviations between assets/styles/*.css and the design-system\n")
            fh.write("# artifact snapshot. Remove an entry once both sides agree.\n")
            for theme, token, kind, _ in violations:
                fh.write(f"{theme}:{token}:{kind}\n")
        print(f"check_design_tokens_sync: wrote {len(violations)} entries to {args.write_baseline}")
        return 0

    baseline = load_baseline(args.baseline)
    new = [v for v in violations if f"{v[0]}:{v[1]}:{v[2]}" not in baseline]
    total = len(violations)
    if not new:
        if not args.quiet:
            print(f"check_design_tokens_sync: OK — {total} deviation(s), all baselined.")
        return 0

    for theme, token, kind, detail in new:
        print(f"  [{theme}] {token}: {kind} ({detail})")
    print(
        f"\ncheck_design_tokens_sync: {len(new)} new deviation(s) from the design-system artifact "
        f"({total - len(new)} baselined). Align assets/styles/ with "
        "docs/design_system/artifact/, or refresh the snapshot "
        "(docs/design_system/artifact/SOURCE.md)."
    )
    return 1


if __name__ == "__main__":
    sys.exit(main())
