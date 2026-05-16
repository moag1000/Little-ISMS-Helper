#!/usr/bin/env python3
"""
check_freetext_legacy.py — Audit-S5 / S4 Foundation P-15 DataReuse CI-gate.

Scans src/Form/**/*Type.php for fields added as plain TextType / TextareaType
whose field-name looks like it should reference an existing entity in the
codebase. Free-text fields where a structured Entity-reference exists violate
the Data-Reuse-Reflex (CLAUDE.md): each new free-text degrades the long-term
reusability of identity data (Auditor / Trainer / Owner / Person).

Heuristic patterns (case-insensitive field-name match):

  - *Auditor / lead*Auditor       → User / Person EntityType
  - *Trainer                      → User / Person
  - *Person                       → Person entity
  - *Owner (when TextType only)   → OwnerPickerFormTrait
  - *Email (in a list-context)    → User-Email
  - *Country (2-char ISO suffix)  → ISO-Country-Select
  - *Department / *Abteilung      → Department-Entity (Quick-Create)
  - *Participant(s) / participants → User/Person collection
  - *Facilitator / *Observer      → User/Person
  - affectedSystems / system_*    → Asset entity (use affectedAssets)

Whitelisting via inline annotation:

      // @legacy-freetext: <reason>

Modes:
  default — warning-only: print findings, exit 0.
  --strict — fail on any non-baselined finding.

Reference: var/junior-isb-audit/SOLUTIONS_FOUNDATION.md § P-15.

Exit-codes:
  0 — clean OR --strict but everything baselined OR not in --strict
  1 — --strict and new violations exist
  2 — parse / I/O error

Usage:
    python3 scripts/quality/check_freetext_legacy.py            # warn-only
    python3 scripts/quality/check_freetext_legacy.py --strict   # CI-fail
    python3 scripts/quality/check_freetext_legacy.py --baseline \
        scripts/quality/baselines/freetext_legacy.txt --strict
    python3 scripts/quality/check_freetext_legacy.py --write-baseline \
        scripts/quality/baselines/freetext_legacy.txt
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
FORM_DIR = ROOT / "src" / "Form"
ENTITY_DIR = ROOT / "src" / "Entity"

# Heuristic field-name pattern → suggested replacement.
# Order matters: more specific patterns first.
HEURISTICS: list[tuple[re.Pattern[str], str]] = [
    (re.compile(r"^(?:lead)?[Aa]uditor[s]?$"), "User / Person EntityType (OwnerPicker P-1)"),
    (re.compile(r"^auditTeam$"), "Collection<User|Person>"),
    (re.compile(r"^trainer[s]?$", re.IGNORECASE), "User / Person EntityType"),
    (re.compile(r"^participant[s]?$"), "Collection<User|Person>"),
    (re.compile(r"^observer[s]?$"), "Collection<User|Person>"),
    (re.compile(r"^facilitator[s]?$"), "User / Person EntityType"),
    (re.compile(r"^(?:response|crisis|incident)Team(?:Members)?$"), "Collection<User|Person>"),
    (re.compile(r"^responsibleDepartment$"), "Department EntityType (Quick-Create)"),
    (re.compile(r"^affectedSystems$"), "Use affectedAssets (Collection<Asset>)"),
    (re.compile(r"^contactPerson$"), "Person EntityType"),
    (re.compile(r"^[A-Z_a-z]*Owner$"), "OwnerPickerFormTrait → User/Person"),
    (re.compile(r"^[A-Z_a-z]*Email[s]?$"), "User-Email or EmailType (not TextType)"),
    (re.compile(r"^country(?:OfHeadOffice)?$"), "ISO-Country-Select / CountryType"),
]

# Annotations.
RE_ANNOTATION = re.compile(r"//\s*@legacy-freetext(?::\s*(.+))?")

# ->add('<name>', TextType::class | TextareaType::class
RE_TEXT_FIELD = re.compile(
    r"->add\(\s*['\"](?P<name>[A-Za-z_][A-Za-z0-9_]*)['\"]\s*,\s*"
    r"(?P<type>TextType|TextareaType)::class"
)


def field_heuristic_hit(name: str) -> str | None:
    for pat, suggestion in HEURISTICS:
        if pat.search(name):
            return suggestion
    return None


def check_file(path: Path) -> list[tuple[int, str, str, str]]:
    """
    Return list of (line_no, field_name, form_field_type, suggestion).
    """
    try:
        text = path.read_text(encoding="utf-8")
    except OSError as e:
        print(f"ERROR reading {path}: {e}", file=sys.stderr)
        return []

    if "TextType" not in text and "TextareaType" not in text:
        return []

    lines = text.splitlines()
    out: list[tuple[int, str, str, str]] = []

    for idx, raw in enumerate(lines):
        line_no = idx + 1

        m = RE_TEXT_FIELD.search(raw)
        if not m:
            continue

        name = m.group("name")
        field_type = m.group("type")
        suggestion = field_heuristic_hit(name)
        if suggestion is None:
            continue

        # Inline annotation on the same line.
        if RE_ANNOTATION.search(raw):
            continue

        # Preceding comment annotation.
        skip = False
        for back in range(1, 4):
            if idx - back < 0:
                break
            prev = lines[idx - back].strip()
            if not prev:
                continue
            if RE_ANNOTATION.search(prev):
                skip = True
                break
            if not (prev.startswith("//") or prev.startswith("*")):
                break
        if skip:
            continue

        out.append((line_no, name, field_type, suggestion))

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


def violation_key(rel: Path, line_no: int, name: str) -> str:
    return f"{rel}:{line_no}:{name}"


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
        help="Scope: explicit file/dir paths (default: src/Form/**/*Type.php).",
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
        help="Write current violation set to file and exit 0 (snapshot mode).",
    )
    parser.add_argument(
        "--strict",
        action="store_true",
        help="Exit 1 on findings (default: warning-only, exit 0).",
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

    paths: list[Path] = []
    if args.paths:
        for p in args.paths:
            pp = Path(p)
            if not pp.is_absolute():
                pp = ROOT / pp
            if pp.is_file():
                paths.append(pp)
            elif pp.is_dir():
                paths.extend(sorted(pp.rglob("*Type.php")))
    else:
        if not FORM_DIR.is_dir():
            print(f"ERROR: {FORM_DIR} not found", file=sys.stderr)
            return 2
        paths = sorted(FORM_DIR.rglob("*Type.php"))

    baseline = load_baseline(args.baseline)
    all_violations: list[tuple[Path, int, str, str, str]] = []

    for path in paths:
        if args.verbose:
            print(f"scan {path.relative_to(ROOT)}", file=sys.stderr)
        for line_no, name, ftype, suggestion in check_file(path):
            all_violations.append((path, line_no, name, ftype, suggestion))

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write(
                "# check_freetext_legacy.py baseline — generated snapshot.\n"
                "# Format: <relative-path>:<line>:<field-name>\n"
                "# Remove an entry once the field is migrated to an EntityType.\n"
            )
            for path, line_no, name, _ftype, _sug in all_violations:
                fh.write(f"{_rel(path)}:{line_no}:{name}\n")
        print(
            f"check_freetext_legacy: wrote {len(all_violations)} entries to {args.write_baseline}"
        )
        return 0

    new_violations = [
        v for v in all_violations
        if violation_key(_rel(v[0]), v[1], v[2]) not in baseline
    ]

    total = len(all_violations)
    new = len(new_violations)
    baselined = total - new

    if new == 0:
        if not args.quiet:
            print(
                f"check_freetext_legacy: OK — {total} freetext-legacy hit(s), "
                f"{baselined} baselined."
            )
        else:
            print(
                f"check_freetext_legacy: OK ({total} hits, all baselined)"
            )
        return 0

    severity_label = "FAIL" if args.strict else "WARN"
    print(f"check_freetext_legacy: {severity_label} ({new} new finding(s))\n")
    for path, line_no, name, ftype, suggestion in new_violations:
        rel = _rel(path)
        print(
            f"{severity_label} {rel}:{line_no}: "
            f"'{name}' ({ftype}) — consider {suggestion}"
        )
    print(
        f"\n{new} new finding(s) ({baselined} baselined, {total} total)."
    )
    if not args.strict:
        print(
            "Run with --strict to make findings CI-blocking, or add\n"
            "  // @legacy-freetext: <reason>\n"
            "directly above intentional free-text fields."
        )
        return 0

    print(
        "Fix options:\n"
        "  (a) replace TextType with EntityType / OwnerPickerFormTrait\n"
        "  (b) suppress with `// @legacy-freetext: <reason>` above the ->add() call\n"
        "  (c) baseline pre-existing hits via --baseline"
    )
    return 1


if __name__ == "__main__":
    sys.exit(main())
