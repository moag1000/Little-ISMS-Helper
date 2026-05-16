#!/usr/bin/env python3
"""
check_flash_domain.py — Audit-S5 / S1 Foundation P-5 LocalizedFlash CI-gate.

Scans src/Controller/**/*.php for flash-message and translation calls that omit
an explicit translation-domain. The default-domain fallback resolves to the
`messages` catalog, but the bulk of UI translation lives in domain-specific
YAML files. Silent fallbacks therefore render raw translation IDs in the UI.

This gate flags two patterns:

  1. addFlash($key, $this->translator->trans('foo'))
     → trans() called with one argument; no `$params` array, no domain.
     ALLOWED forms:
       * $this->flashSuccess('foo')                  // LocalizedFlashTrait
       * $this->flashError('foo', ['%name%' => …])
       * $this->addFlash($key, $this->translator->trans('foo', [], 'domain'))

  2. $this->translator->trans('foo')   (any position)
     → bare 1-arg trans() inside a Controller. Same rationale as (1).

Reference: var/junior-isb-audit/SOLUTIONS_FOUNDATION.md § P-5.

Exit-codes:
  0 — no violations (or all violations baselined)
  1 — at least one violation outside the baseline
  2 — parse / I/O error

Usage:
    python3 scripts/quality/check_flash_domain.py
    python3 scripts/quality/check_flash_domain.py --baseline \
        scripts/quality/baselines/flash_domain.txt
    python3 scripts/quality/check_flash_domain.py --quiet
    python3 scripts/quality/check_flash_domain.py --write-baseline \
        scripts/quality/baselines/flash_domain.txt
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CONTROLLER_DIR = ROOT / "src" / "Controller"

# Match $this->translator->trans('key') with ONLY a single argument. We support
# the most common Symfony invocation styles:
#   $this->translator->trans('foo')
#   $this->translator->trans("foo")
# But NOT the proper 3-arg form:
#   $this->translator->trans('foo', [], 'domain')
#   $this->translator->trans('foo', ['%x%' => 1], 'domain')
#
# We also support the shorthand `$translator->trans(...)` (local variable) and
# the chained `$translator->trans('foo', [], 'domain')` form is allowed.
RE_TRANS_BARE = re.compile(
    r"""
    \$(?:this->)?translator    # $this->translator | $translator
    \s*->\s*trans\(
    \s*['"][^'"]+['"]          # the translation key string literal
    \s*\)                      # CLOSE paren — i.e. NO further arguments
    """,
    re.VERBOSE,
)

# Match $this->translator->trans('key', [...])   (2 args, no domain)
# We accept either a [...] literal array, $vars, or [params] as the second arg.
RE_TRANS_TWO_ARGS = re.compile(
    r"""
    \$(?:this->)?translator
    \s*->\s*trans\(
    \s*['"][^'"]+['"]              # key
    \s*,\s*                        # comma
    (?:                            # second arg: [] literal or variable
        \[[^\]]*\]
        |
        \$\w+
    )
    \s*\)                          # CLOSE paren — NO third arg
    """,
    re.VERBOSE,
)

# Match addFlash($_, …) — used as a quick filter; we don't enforce structure
# here directly, the inner trans() match is what triggers the gate.
RE_ADD_FLASH = re.compile(r"\$this->addFlash\(")

# Annotation: per-line override.
RE_ANNOTATION = re.compile(r"//\s*@flash-domain-fallback-ok(?::\s*(.+))?")


def check_file(path: Path) -> list[tuple[int, str]]:
    """
    Return list of (line_no, snippet) violations.
    """
    try:
        text = path.read_text(encoding="utf-8")
    except OSError as e:
        print(f"ERROR reading {path}: {e}", file=sys.stderr)
        return []

    if "translator" not in text:
        return []

    lines = text.splitlines()
    out: list[tuple[int, str]] = []

    for idx, raw in enumerate(lines):
        line_no = idx + 1
        # Skip comment-only lines.
        stripped = raw.lstrip()
        if stripped.startswith("//") or stripped.startswith("*") or stripped.startswith("#"):
            continue

        # Same-line annotation overrides.
        if RE_ANNOTATION.search(raw):
            continue

        # Previous-non-empty line annotation also overrides.
        skip_due_to_prev = False
        for back in range(1, 4):
            if idx - back < 0:
                break
            prev = lines[idx - back].strip()
            if not prev:
                continue
            if RE_ANNOTATION.search(prev):
                skip_due_to_prev = True
                break
            if not (prev.startswith("//") or prev.startswith("*")):
                break
        if skip_due_to_prev:
            continue

        # Single-arg trans()
        if RE_TRANS_BARE.search(raw):
            snippet = raw.strip()
            out.append((line_no, snippet))
            continue

        # Two-arg trans() (key + params, no domain)
        if RE_TRANS_TWO_ARGS.search(raw):
            snippet = raw.strip()
            out.append((line_no, snippet))
            continue

    return out


# Baseline / CLI ─────────────────────────────────────────────────────────────


def load_baseline(path: Path | None) -> set[str]:
    if path is None or not path.exists():
        return set()
    out: set[str] = set()
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        out.add(line)
    return out


def violation_key(rel: Path, line_no: int) -> str:
    return f"{rel}:{line_no}"


def _rel(path: Path) -> Path:
    """Return repo-root-relative path when possible, otherwise the path's name."""
    try:
        return path.relative_to(ROOT)
    except ValueError:
        return Path(path.name)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--paths",
        nargs="*",
        default=None,
        help="Scope: explicit file/dir paths (default: src/Controller/**/*.php).",
    )
    parser.add_argument(
        "--baseline",
        type=Path,
        default=None,
        help="Optional baseline file with pre-existing violations to ignore.",
    )
    parser.add_argument(
        "--write-baseline",
        type=Path,
        default=None,
        help="Write current violations to file and exit 0 (snapshot mode).",
    )
    parser.add_argument(
        "--quiet",
        action="store_true",
        help="Print summary only.",
    )
    parser.add_argument(
        "--verbose",
        action="store_true",
        help="Print scan progress per file.",
    )
    args = parser.parse_args()

    # Resolve scope.
    paths: list[Path] = []
    if args.paths:
        for p in args.paths:
            pp = Path(p)
            if not pp.is_absolute():
                pp = ROOT / pp
            if pp.is_file():
                paths.append(pp)
            elif pp.is_dir():
                paths.extend(sorted(pp.rglob("*.php")))
    else:
        if not CONTROLLER_DIR.is_dir():
            print(f"ERROR: {CONTROLLER_DIR} not found", file=sys.stderr)
            return 2
        paths = sorted(CONTROLLER_DIR.rglob("*.php"))

    # Ignore .backup files / vendor copies.
    paths = [p for p in paths if not p.name.endswith(".backup")]

    baseline = load_baseline(args.baseline)
    all_violations: list[tuple[Path, int, str]] = []

    for path in paths:
        if args.verbose:
            print(f"scan {path.relative_to(ROOT)}", file=sys.stderr)
        for line_no, snippet in check_file(path):
            all_violations.append((path, line_no, snippet))

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write(
                "# check_flash_domain.py baseline — generated snapshot.\n"
                "# Format: <relative-path>:<line>\n"
                "# Remove an entry once the call passes an explicit domain\n"
                "# or is migrated to a LocalizedFlashTrait helper.\n"
            )
            for path, line_no, _snippet in all_violations:
                fh.write(f"{_rel(path)}:{line_no}\n")
        print(
            f"check_flash_domain: wrote {len(all_violations)} entries to {args.write_baseline}"
        )
        return 0

    new_violations = [
        v for v in all_violations
        if violation_key(_rel(v[0]), v[1]) not in baseline
    ]

    total = len(all_violations)
    new = len(new_violations)
    baselined = total - new

    if new == 0:
        if not args.quiet:
            print(
                f"check_flash_domain: OK — {total} bare-trans() call(s) found, "
                f"{baselined} baselined."
            )
        else:
            print(
                f"check_flash_domain: OK ({total} bare-trans calls, all baselined)"
            )
        return 0

    print("check_flash_domain: VIOLATIONS\n")
    for path, line_no, snippet in new_violations:
        rel = _rel(path)
        # Trim very long lines for legibility.
        trimmed = snippet if len(snippet) < 160 else snippet[:157] + "..."
        print(f"FAIL {rel}:{line_no}: {trimmed}")
    print(
        f"\n{new} new violation(s) ({baselined} baselined, {total} total)."
    )
    print(
        "Fix options:\n"
        "  (a) use LocalizedFlashTrait: `$this->flashSuccess('key.with.dots')`\n"
        "  (b) pass explicit domain: `$this->translator->trans('foo', [], 'domain')`\n"
        "  (c) intentional 'messages' fallback? add `// @flash-domain-fallback-ok: <reason>`"
    )
    return 1


if __name__ == "__main__":
    sys.exit(main())
