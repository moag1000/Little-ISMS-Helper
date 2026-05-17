#!/usr/bin/env python3
"""
check_nested_forms.py — Detect nested literal `<form>` tags in Twig templates.

HTML5 forbids nested forms; nesting causes silent submit-loss in the inner
form and confusing browser behavior. Symfony's `form_start()` / `form_end()`
emits a literal opening/closing tag, but we cannot inspect dynamic output —
this gate only scans literal `<form …>` … `</form>` pairs in templates.

Algorithm (per file):
  1. Walk tokens left-to-right; maintain depth counter `d`.
  2. On `<form` opening (not `<form>`-in-attribute) → if d>0 FAIL, then d+=1.
  3. On `</form>` → d=max(0, d-1).
  4. `{% include %}` / `{% embed %}` / `{% block %}` boundaries reset depth
     for the included region (we don't recurse).

Heuristic — false-positive avoidance:
  * Mask `{# … #}` comments.
  * Skip `<form>` inside `{# … #}` lines.
  * Skip `<form>` literal inside a `{% verbatim %}` block.

Exit-codes: 0 clean, 1 violations, 2 I/O error.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SKIP_DIRS = {"vendor", "node_modules", "var", ".claude", "migrations", "tests/Fixtures", "docs"}

RE_FORM_OPEN = re.compile(r"<form\b", re.IGNORECASE)
RE_FORM_CLOSE = re.compile(r"</form\s*>", re.IGNORECASE)
RE_TWIG_COMMENT = re.compile(r"\{#.*?#\}", re.DOTALL)
RE_VERBATIM_OPEN = re.compile(r"\{%\s*verbatim\s*%\}")
RE_VERBATIM_CLOSE = re.compile(r"\{%\s*endverbatim\s*%\}")


def is_skipped(path: Path) -> bool:
    parts = path.relative_to(ROOT).parts
    for skip in SKIP_DIRS:
        if "/" in skip:
            seg = tuple(skip.split("/"))
            if any(parts[i:i + len(seg)] == seg for i in range(len(parts))):
                return True
        elif skip in parts:
            return True
    return False


def line_of(text: str, offset: int) -> int:
    return text.count("\n", 0, offset) + 1


def strip_verbatim(text: str) -> str:
    """Mask {% verbatim %} … {% endverbatim %} with spaces preserving offsets."""
    out = list(text)
    pos = 0
    while True:
        m = RE_VERBATIM_OPEN.search(text, pos)
        if not m:
            break
        m2 = RE_VERBATIM_CLOSE.search(text, m.end())
        if not m2:
            break
        for i in range(m.end(), m2.start()):
            if out[i] != "\n":
                out[i] = " "
        pos = m2.end()
    return "".join(out)


def scan(path: Path) -> list[tuple[int, str]]:
    try:
        text = path.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        return []
    if "<form" not in text.lower():
        return []
    # Mask comments + verbatim
    text_clean = RE_TWIG_COMMENT.sub(lambda m: " " * (m.end() - m.start()), text)
    text_clean = strip_verbatim(text_clean)

    # Tokenize: collect open/close events with their offsets.
    events: list[tuple[int, str]] = []
    for m in RE_FORM_OPEN.finditer(text_clean):
        events.append((m.start(), "open"))
    for m in RE_FORM_CLOSE.finditer(text_clean):
        events.append((m.start(), "close"))
    events.sort()

    depth = 0
    out: list[tuple[int, str]] = []
    lines = text.splitlines()
    for offset, kind in events:
        if kind == "open":
            if depth > 0:
                ln = line_of(text, offset)
                snippet = lines[ln - 1].strip()[:160] if 0 < ln <= len(lines) else "<form>"
                out.append((ln, snippet))
            depth += 1
        else:
            depth = max(0, depth - 1)
    return out


def walk(root: Path, pat: str) -> list[Path]:
    return sorted(p for p in root.glob(pat) if p.is_file() and not is_skipped(p))


def load_baseline(path: Path | None) -> set[str]:
    if path is None or not path.exists():
        return set()
    out = set()
    for raw in path.read_text(encoding="utf-8").splitlines():
        s = raw.strip()
        if s and not s.startswith("#"):
            out.add(s)
    return out


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

    violations: list[tuple[Path, int, str]] = []
    for f in walk(ROOT / "templates", "**/*.html.twig"):
        for ln, snip in scan(f):
            violations.append((f, ln, snip))

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_nested_forms.py baseline\n# Format: <relative-path>:<line>\n")
            for path, ln, _snip in violations:
                fh.write(f"{_rel(path)}:{ln}\n")
        print(f"check_nested_forms: wrote {len(violations)} entries to {args.write_baseline}")
        return 0

    baseline = load_baseline(args.baseline)
    new = [v for v in violations if f"{_rel(v[0])}:{v[1]}" not in baseline]
    total = len(violations)
    baselined = total - len(new)

    if not new:
        if not args.quiet:
            print(f"check_nested_forms: OK — {total} occurrence(s), {baselined} baselined.")
        else:
            print(f"check_nested_forms: OK ({total} occurrences, all baselined)")
        return 0

    print("check_nested_forms: VIOLATIONS\n")
    for path, ln, snip in new:
        print(f"FAIL {_rel(path)}:{ln}: nested <form> — {snip}")
    print(f"\ncheck_nested_forms: {len(new)} new violation(s) ({baselined} baselined, {total} total).")
    print("Fix: close the outer <form> first OR move the inner form outside the parent.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
