#!/usr/bin/env python3
"""
check_notblank_on_not_null.py — Require NotBlank/NotNull Assert on NOT NULL scalar columns.

When a Doctrine column is NOT NULL (no `nullable: true` in `#[ORM\\Column]`)
but the property has no `#[Assert\\NotBlank]`/`#[Assert\\NotNull]`/`#[Assert\\Choice]`
constraint, an empty form submission bypasses validation and crashes at
`EntityManager::flush()` with:

    SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'X' cannot be null

— a user-facing HTTP 500 instead of a clean 422 form error.

Scope: `src/Entity/**/*.php`. Only `?string|?int` properties (lifecycle /
collection / FK fields are out of scope). Boolean and DateTime properties
with sensible defaults are also excluded.

Per-file opt-out: `// @notblank-allowed: <reason>` in top 30 lines.
Per-property opt-out: `#[Assert\\NotBlank-Exempt('<reason>')]` is NOT a real
constraint; instead use an `@notblank-allowed:` line-comment immediately above
the property.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ENTITY_DIR = ROOT / "src" / "Entity"

# Match a Column block + (optional other attrs) + a `private ?string|?int $X = null` property.
# Tolerant of multi-line ORM\Column declarations.
RE_PROPERTY_BLOCK = re.compile(
    r"(?P<column>#\[ORM\\Column\((?P<attrs>[^)]*)\)\])"
    r"(?P<inter>(?:\s*#\[[^\]]+\][^\n]*\n)*)"
    r"\s*(?P<line>private\s+\?(?P<type>string|int)\s+\$(?P<name>\w+)\s*=\s*null)",
    re.MULTILINE,
)
RE_NULLABLE = re.compile(r"nullable\s*:\s*true")
RE_HAS_NOTBLANK = re.compile(r"#\[Assert\\(?:NotBlank|NotNull|Choice)\b")
RE_NAME_OVERRIDE = re.compile(r"name\s*:\s*['\"](?P<col>[^'\"]+)['\"]")
OPT_OUT_FILE = "@notblank-allowed"
OPT_OUT_LINE = "@notblank-allowed"


def find_violations(path: Path) -> list[tuple[int, str, str]]:
    text = path.read_text(encoding="utf-8")
    head = "\n".join(text.splitlines()[:30])
    if OPT_OUT_FILE in head:
        return []

    violations: list[tuple[int, str, str]] = []
    lines = text.splitlines()
    for m in RE_PROPERTY_BLOCK.finditer(text):
        attrs = m.group("attrs")
        inter = m.group("inter") or ""
        if RE_NULLABLE.search(attrs):
            continue  # column allows NULL — fine
        block = m.group("column") + inter
        if RE_HAS_NOTBLANK.search(block):
            continue  # Already has NotBlank / NotNull / Choice
        # Check inline comment opt-out on line above the property
        prop_line_no = text[: m.start("line")].count("\n") + 1
        prev_line = lines[prop_line_no - 2] if prop_line_no >= 2 else ""
        if OPT_OUT_LINE in prev_line:
            continue
        name = m.group("name")
        typ = m.group("type")
        violations.append((prop_line_no, name, f"?{typ} ${name}"))
    return violations


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--baseline", type=Path, default=None)
    parser.add_argument("--quiet", action="store_true")
    parser.add_argument("--write-baseline", type=Path, default=None)
    args = parser.parse_args()

    baseline: set[str] = set()
    if args.baseline and args.baseline.exists():
        for line in args.baseline.read_text().splitlines():
            line = line.strip()
            if line and not line.startswith("#"):
                baseline.add(line.split("  ", 1)[0])

    all_findings: list[str] = []
    for php in sorted(ENTITY_DIR.rglob("*.php")):
        rel = php.relative_to(ROOT).as_posix()
        for line_no, name, sig in find_violations(php):
            all_findings.append(f"{rel}:{line_no}  {sig}")

    if args.write_baseline:
        with args.write_baseline.open("w") as fh:
            fh.write("# check_notblank_on_not_null.py baseline\n")
            fh.write("# Format: <relative-path>:<line>  <signature>\n")
            for line in all_findings:
                fh.write(line + "\n")
        print(f"check_notblank_on_not_null: wrote {len(all_findings)} entries")
        return 0

    new_violations: list[str] = []
    baselined_count = 0
    for line in all_findings:
        prefix = line.split("  ", 1)[0]
        if prefix in baseline:
            baselined_count += 1
        else:
            new_violations.append(line)

    if new_violations:
        if not args.quiet:
            print("check_notblank_on_not_null: VIOLATIONS\n")
        for v in new_violations:
            print(f"FAIL {v}")
        print(
            f"\ncheck_notblank_on_not_null: {len(new_violations)} new violation(s) "
            f"({baselined_count} baselined, {len(all_findings)} total)."
        )
        print("Fix: add `#[Assert\\NotBlank]` or `#[Assert\\NotNull]` above the property,")
        print("or mark the line with `// @notblank-allowed: <reason>` (controller pre-fills it).")
        return 1

    if not args.quiet:
        print(f"check_notblank_on_not_null: OK ({len(all_findings)}, all baselined)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
