#!/usr/bin/env python3
r"""
check_disabled_mapped_pair.py — Forbid `'disabled' => true` without `'mapped' => false`.

When a FormType field is disabled but still mapped (the default), Symfony binds
the POST value back to the entity on submit.  A browser that strips disabled
fields sends nothing → Symfony sees an empty submission → the entity property is
set to null → NOT NULL DB columns blow up with a 422 / ConstraintViolation.

Rule: every `$builder->add(...)` whose options-block contains `'disabled' => true`
MUST also contain `'mapped' => false` (or `'unmapped' => true`), OR carry an
explicit override annotation on the preceding non-empty line:

    // @intentional-bind: <reason>
    $builder->add('foo', TextType::class, ['disabled' => true, ...]);

Fixed in PR #707 (21 fields) and PR #712 Pattern A (12 fields).
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
FORM_DIR = ROOT / "src" / "Form"

# Matches the start of a builder->add() call chain
RE_ADD = re.compile(r"->add\s*\(")
RE_DISABLED = re.compile(r"['\"]disabled['\"]\s*=>\s*true")
RE_MAPPED_FALSE = re.compile(r"['\"]mapped['\"]\s*=>\s*false")
RE_UNMAPPED = re.compile(r"['\"]unmapped['\"]\s*=>\s*true")
RE_ANNOTATION = re.compile(r"//\s*@intentional-bind(?::\s*.+)?")


def _extract_add_block(text: str, add_start: int) -> tuple[str, int]:
    """
    Given the position of '->add(' in text, extract the full argument list
    (balanced parentheses) and return (block_text, end_index).
    Returns ('', -1) if parentheses are not balanced within 8 kB.
    """
    # Seek to the opening paren
    i = text.index("(", add_start)
    depth = 0
    limit = min(len(text), i + 8192)
    for j in range(i, limit):
        c = text[j]
        if c == "(":
            depth += 1
        elif c == ")":
            depth -= 1
            if depth == 0:
                return text[i : j + 1], j
    return "", -1


def scan(path: Path) -> list[tuple[int, str]]:
    try:
        text = path.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        return []

    if "'disabled'" not in text and '"disabled"' not in text:
        return []

    violations: list[tuple[int, str]] = []
    lines = text.splitlines()

    for m in RE_ADD.finditer(text):
        block, end_idx = _extract_add_block(text, m.start())
        if not block:
            continue

        if not RE_DISABLED.search(block):
            continue

        # Good: mapped => false or unmapped => true already present
        if RE_MAPPED_FALSE.search(block) or RE_UNMAPPED.search(block):
            continue

        # Check for override annotation on the preceding non-empty line(s)
        add_line_no = text[: m.start()].count("\n")  # 0-based line index
        annotation_found = False
        for back in range(1, 5):
            prev_idx = add_line_no - back
            if prev_idx < 0:
                break
            prev = lines[prev_idx].strip()
            if not prev:
                continue  # skip blank lines
            if RE_ANNOTATION.search(prev):
                annotation_found = True
            break  # stop at first non-blank predecessor line

        if annotation_found:
            continue

        # Same-line annotation (rare but possible)
        add_line_text = lines[add_line_no] if add_line_no < len(lines) else ""
        if RE_ANNOTATION.search(add_line_text):
            continue

        line_no = add_line_no + 1  # 1-based
        violations.append((line_no, add_line_text.strip()[:160]))

    return violations


def walk(root: Path) -> list[Path]:
    return sorted(p for p in root.rglob("*.php") if p.is_file())


def load_baseline(path: Path | None) -> set[str]:
    if path is None or not path.exists():
        return set()
    out: set[str] = set()
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

    if not FORM_DIR.is_dir():
        print(f"ERROR: {FORM_DIR} not found", file=sys.stderr)
        return 2

    all_violations: list[tuple[Path, int, str]] = []
    for f in walk(FORM_DIR):
        for ln, snip in scan(f):
            all_violations.append((f, ln, snip))

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_disabled_mapped_pair.py baseline\n")
            fh.write("# Format: <relative-path>:<line>\n")
            for path, ln, _snip in all_violations:
                fh.write(f"{_rel(path)}:{ln}\n")
        print(
            f"check_disabled_mapped_pair: wrote {len(all_violations)} entries"
            f" to {args.write_baseline}"
        )
        return 0

    baseline = load_baseline(args.baseline)
    new = [v for v in all_violations if f"{_rel(v[0])}:{v[1]}" not in baseline]
    total = len(all_violations)
    baselined = total - len(new)

    if not new:
        if not args.quiet:
            print(
                f"check_disabled_mapped_pair: OK — {total} occurrence(s),"
                f" {baselined} baselined."
            )
        return 0

    print("check_disabled_mapped_pair: VIOLATIONS\n")
    for path, ln, snip in new[:200]:
        print(f"FAIL {_rel(path)}:{ln}: {snip}")
    if len(new) > 200:
        print(f"... and {len(new) - 200} more")
    print(
        f"\ncheck_disabled_mapped_pair: {len(new)} new violation(s)"
        f" ({baselined} baselined, {total} total)."
    )
    print(
        "Fix: add `'mapped' => false` to the options array,"
        " OR add `// @intentional-bind: <reason>` comment on the preceding line."
    )
    return 1


if __name__ == "__main__":
    sys.exit(main())
