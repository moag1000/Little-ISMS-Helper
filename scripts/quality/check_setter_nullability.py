#!/usr/bin/env python3
"""
check_setter_nullability.py — Setter param type-hint must match property nullability.

Symfony's PropertyAccessor calls the setter when binding a form value. When
a property is declared `private ?T $foo = null` but its setter is
`setFoo(T $foo)` (non-nullable), an empty form submission produces:

    Expected argument of type "T", "null" given at property path "foo".
    Symfony\\Component\\PropertyAccess\\Exception\\InvalidTypeException
    → HTTP 500 at PropertyAccessor.php

Sweeps in PR #706 (55 DateTime setters) + #707 (217 scalar setters) fixed
the historical violations. This gate prevents regression: every
`private ?T $foo = null` property must have a matching `setFoo(?T $foo)`
signature (or no public setter at all — fine for read-only state).

Scope: `src/Entity/**/*.php`. Per-file opt-out:
`// @setter-nullability-allowed: <reason>` in top 30 lines.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ENTITY_DIR = ROOT / "src" / "Entity"

RE_NULLABLE_PROP = re.compile(
    r"private\s+\??(?P<type>string|int|float|bool|DateTimeInterface|DateTimeImmutable|array)\s+\$(?P<name>\w+)\s*=\s*null",
    re.IGNORECASE,
)
# Match: `private ?T $foo = null` only — non-nullable defaults are out of scope.
RE_NULLABLE_PROP_STRICT = re.compile(
    r"private\s+\?(?P<type>string|int|float|bool|DateTimeInterface|DateTimeImmutable|array)\s+\$(?P<name>\w+)\s*=\s*null"
)
OPT_OUT_MARKER = "@setter-nullability-allowed"


def find_violations(path: Path) -> list[tuple[int, str, str]]:
    text = path.read_text(encoding="utf-8")
    # Per-file opt-out
    head = "\n".join(text.splitlines()[:30])
    if OPT_OUT_MARKER in head:
        return []

    violations: list[tuple[int, str, str]] = []
    for m in RE_NULLABLE_PROP_STRICT.finditer(text):
        name = m.group("name")
        typ = m.group("type")
        setter = f"set{name[0].upper()}{name[1:]}"
        # Match a public setter — accept various return types
        setter_re = re.compile(
            rf"public\s+function\s+{setter}\s*\(\s*(\??{typ})\s+\$\w+\s*\)",
            re.IGNORECASE,
        )
        sm = setter_re.search(text)
        if not sm:
            # No public setter — fine (immutable / read-only / collection)
            continue
        param_type = sm.group(1)
        if not param_type.startswith("?"):
            line_no = text[: sm.start()].count("\n") + 1
            violations.append((line_no, setter, f"{typ} ${name} (property nullable, setter not)"))
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
                baseline.add(line)

    all_findings: list[str] = []
    for php in sorted(ENTITY_DIR.rglob("*.php")):
        rel = php.relative_to(ROOT).as_posix()
        for line_no, setter, msg in find_violations(php):
            key = f"{rel}:{line_no}"
            all_findings.append(key + f"  {setter}({msg})")

    if args.write_baseline:
        with args.write_baseline.open("w") as fh:
            fh.write("# check_setter_nullability.py baseline\n")
            fh.write("# Format: <relative-path>:<line>  <setter>(<note>)\n")
            for line in all_findings:
                fh.write(line + "\n")
        print(f"check_setter_nullability: wrote {len(all_findings)} entries to {args.write_baseline}")
        return 0

    # Diff against baseline
    new_violations: list[str] = []
    baselined_count = 0
    for line in all_findings:
        # Compare by file:line prefix only — note/setter may drift
        prefix = line.split("  ", 1)[0]
        if prefix in {b.split("  ", 1)[0] for b in baseline}:
            baselined_count += 1
        else:
            new_violations.append(line)

    if new_violations:
        if not args.quiet:
            print("check_setter_nullability: VIOLATIONS\n")
        for v in new_violations:
            print(f"FAIL {v}")
        print(
            f"\ncheck_setter_nullability: {len(new_violations)} new violation(s) "
            f"({baselined_count} baselined, {len(all_findings)} total)."
        )
        print("Fix: relax the setter param to `?T` to match `private ?T $foo = null`.")
        print("Or mark the file `// @setter-nullability-allowed: <reason>` near the top.")
        return 1

    if not args.quiet:
        print(f"check_setter_nullability: OK ({len(all_findings)}, all baselined)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
