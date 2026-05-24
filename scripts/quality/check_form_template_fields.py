#!/usr/bin/env python3
"""
check_form_template_fields.py — FormType <-> Template drift CI-Gate.

Complement to Gate 29 (check_form_render_completeness.py).

Gate 29 catches: FormType declares field X, template forgets to render it
                 -> field flows past submit (visual breakage).

This gate catches the REVERSE drift:
  Template references `form.X` (form_row, form_label, form_widget, setRendered)
  but the associated FormType has NO `->add('X', ...)` call.
  -> dead reference -> silent UI breakage (the form_row() simply renders
     nothing, or Twig swallows the missing-child OffsetGet error in prod).

Detection (static analysis, no PHP/Twig engine):
  1. For each src/Form/**/*Type.php -> collect builder->add('NAME') field set.
  2. For each templates/**/*.html.twig that uses `form_start(form)` ...
     `form_end(form)`, map it to a candidate FormType via the same path
     heuristic Gate 29 uses (e.g. templates/asset/_form.html.twig -> AssetType).
  3. For every `form.<field>` usage in the template, FAIL if <field> is
     NOT in the FormType's builder-fields set.

Tolerated dynamic references (template uses but FormType cannot statically
declare):
  - Symfony form-API helpers: `_token`, `children`, `vars`, `parent`,
    `data`, `value`, `submitted`, `valid`, `errors`, `method`, `name`,
    `rendered`, `setRendered`.
  - User-defined macro/named blocks where `form` is shadowed by a local
    variable named `form` — heuristic skipped only when we cannot find
    a `form_start(form)` anchor on the page.

CLI:
  python3 check_form_template_fields.py [--baseline PATH] [--write-baseline PATH] [--quiet]

Exit:
  0 = no new violations (or all baselined)
  1 = new violations
  2 = environment error (e.g. directories missing)
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
FORMS_DIR = ROOT / "src" / "Form"
TEMPLATES_DIR = ROOT / "templates"

# FormType field collection — same pattern as Gate 29.
RE_ADD = re.compile(r"->add\(\s*['\"]([a-zA-Z_][a-zA-Z0-9_]*)['\"]")
# OwnerPickerFormTrait::addOwnerPicker(...) injects up to 4 child fields whose
# names come from config-keys user_field / person_field / deputies_field /
# legacy_field. Same handling as check_form_sections.py (Gate P-2).
RE_OWNER_PICKER = re.compile(
    r"addOwnerPicker\s*\([^,]+,\s*\[(.*?)\]\s*\)\s*;",
    re.DOTALL,
)
OWNER_PICKER_CONFIG_KEYS = (
    "user_field",
    "person_field",
    "deputies_field",
    "legacy_field",
)
RE_OWNER_PICKER_FIELD = re.compile(
    r"['\"](?:" + "|".join(OWNER_PICKER_CONFIG_KEYS) + r")['\"]\s*=>\s*['\"]([a-zA-Z_][a-zA-Z0-9_]*)['\"]"
)

# Template field references. We probe `form.<field>` in the four common
# render-helpers + the `{% do form.<X>.setRendered %}` pattern. Note we
# DO NOT look at arbitrary `form.X` reads (e.g. inside a {% if %}) — those
# tend to be intentional and hard to validate statically.
RE_REF_PATTERNS = [
    re.compile(r"form_row\(\s*form\.([a-zA-Z_][a-zA-Z0-9_]*)"),
    re.compile(r"form_label\(\s*form\.([a-zA-Z_][a-zA-Z0-9_]*)"),
    re.compile(r"form_widget\(\s*form\.([a-zA-Z_][a-zA-Z0-9_]*)"),
    re.compile(r"form_errors\(\s*form\.([a-zA-Z_][a-zA-Z0-9_]*)"),
    re.compile(r"form_help\(\s*form\.([a-zA-Z_][a-zA-Z0-9_]*)"),
    re.compile(r"\{%\s*do\s+form\.([a-zA-Z_][a-zA-Z0-9_]*)\.setRendered"),
]

RE_FORM_START = re.compile(r"\{\{\s*form_start\(\s*form")
RE_FORM_END = re.compile(r"\{\{\s*form_end\(\s*form")

# Reserved Symfony form-vars/helpers that ARE legitimate `form.X` accessors
# but are never declared via builder->add(). Tolerated.
FORM_INTERNALS = {
    "_token",
    "children",
    "vars",
    "parent",
    "data",
    "value",
    "submitted",
    "valid",
    "errors",
    "method",
    "name",
    "rendered",
    "all",
    "isRoot",
    "root",
    "isSubmitted",
    "isValid",
    "isEmpty",
    "config",
    "createView",
    "count",
}

# Heuristic alias map (mirrors Gate 29).
ALIASES = {
    "bc_exercise": "BusinessContinuityExerciseType",
    "bc_plans": "BusinessContinuityPlanType",
    "business_continuity_plan": "BusinessContinuityPlanType",
}


def collect_form_fields() -> dict[str, set[str]]:
    """FormType-class-name (no .php) -> set of declared field names."""
    out: dict[str, set[str]] = {}
    for ft in FORMS_DIR.rglob("*Type.php"):
        text = ft.read_text(encoding="utf-8", errors="ignore")
        fields = set(RE_ADD.findall(text))
        # Pull OwnerPickerFormTrait-injected fields (assignedTo / assignedPerson /
        # assignedDeputyPersons / legacy) so they're counted as declared.
        for picker_block in RE_OWNER_PICKER.finditer(text):
            fields.update(RE_OWNER_PICKER_FIELD.findall(picker_block.group(1)))
        if fields:
            out[ft.stem] = fields
    return out


def candidates_for(rel_parts: tuple[str, ...]) -> list[str]:
    """Given template path parts like ('asset', '_form.html.twig'), produce
    a list of candidate FormType class names ('AssetType', then drill-down)."""
    parts = list(rel_parts[:-1])  # drop filename
    out: list[str] = []
    for seg in parts:
        out.append("".join(p.capitalize() for p in seg.split("_")) + "Type")
    if parts:
        joined = "".join("".join(p.capitalize() for p in seg.split("_")) for seg in parts)
        out.append(joined + "Type")
        if parts[0] in ALIASES:
            out.append(ALIASES[parts[0]])
    return out


def referenced_fields(text: str) -> set[tuple[str, int]]:
    """Return set of (field_name, line_number) referenced as `form.X` in
    a render-helper or `{% do form.X.setRendered %}`."""
    out: set[tuple[str, int]] = set()
    lines = text.splitlines()
    for idx, raw in enumerate(lines, start=1):
        for pat in RE_REF_PATTERNS:
            for m in pat.finditer(raw):
                name = m.group(1)
                if name in FORM_INTERNALS:
                    continue
                out.add((name, idx))
    return out


def scan() -> list[tuple[Path, str, str, int]]:
    """Return list of (template_path, FormType, field, lineno) for each
    dead reference."""
    form_fields = collect_form_fields()
    findings: list[tuple[Path, str, str, int]] = []
    for tpl in TEMPLATES_DIR.rglob("*.html.twig"):
        rel_parts = tpl.relative_to(TEMPLATES_DIR).parts
        if rel_parts and rel_parts[0] == "_components":
            continue
        text = tpl.read_text(encoding="utf-8", errors="ignore")
        if not RE_FORM_START.search(text) and not RE_FORM_END.search(text):
            # Template uses `form.X` but no form_start anchor -> likely a
            # show/list template where `form` is some other variable
            # (e.g. {{ form.label }} for a CSRF token-only mini-form).
            # Skip — too noisy without an anchor.
            continue
        refs = referenced_fields(text)
        if not refs:
            continue
        candidates = candidates_for(rel_parts)
        matched = next((c for c in candidates if c in form_fields), None)
        if matched is None:
            # We cannot determine the FormType — skip silently. A separate
            # gate (or manual review) catches templates with no FormType
            # binding.
            continue
        declared = form_fields[matched]
        for field, lineno in sorted(refs, key=lambda x: (x[1], x[0])):
            if field not in declared:
                findings.append((tpl, matched, field, lineno))
    return findings


def load_baseline(path: Path | None) -> set[str]:
    if path is None or not path.exists():
        return set()
    return {
        s.strip() for s in path.read_text(encoding="utf-8").splitlines()
        if s.strip() and not s.strip().startswith("#")
    }


def _rel(p: Path) -> Path:
    try:
        return p.relative_to(ROOT)
    except ValueError:
        return Path(p.name)


def _key(rel_path: Path, formtype: str, field: str) -> str:
    """Baseline key — line-number-INDEPENDENT so trivial refactors don't
    invalidate the baseline. New violations of the same (path, FormType,
    field) tuple still fail unless explicitly baselined."""
    return f"{rel_path} :: {formtype} :: {field}"


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--baseline", type=Path, default=None)
    ap.add_argument("--write-baseline", type=Path, default=None)
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    if not FORMS_DIR.is_dir():
        print(f"ERROR: {FORMS_DIR} not found", file=sys.stderr)
        return 2
    if not TEMPLATES_DIR.is_dir():
        print(f"ERROR: {TEMPLATES_DIR} not found", file=sys.stderr)
        return 2

    findings = scan()
    keys = [_key(_rel(p), ft, fld) for p, ft, fld, _ln in findings]

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_form_template_fields.py baseline\n")
            fh.write("# Format: <template-path> :: <FormTypeName> :: <field-name>\n")
            fh.write("# Line numbers are intentionally omitted so refactors do not\n")
            fh.write("# invalidate entries. Add new lines by hand if needed.\n")
            for k in sorted(set(keys)):
                fh.write(k + "\n")
        print(
            f"check_form_template_fields: wrote {len(set(keys))} entries "
            f"to {args.write_baseline}"
        )
        return 0

    baseline = load_baseline(args.baseline)
    new: list[tuple[Path, str, str, int]] = []
    for finding, key in zip(findings, keys):
        if key not in baseline:
            new.append(finding)

    total = len(findings)
    baselined = total - len(new)

    if not new:
        if not args.quiet:
            print(
                f"check_form_template_fields: OK — {total} known drift entries, "
                f"{baselined} baselined."
            )
        else:
            print(f"check_form_template_fields: OK ({total}, all baselined)")
        return 0

    print("check_form_template_fields: VIOLATIONS\n")
    for path, formtype, field, lineno in new[:200]:
        rel = _rel(path)
        print(
            f"FAIL {rel}:{lineno}: form.{field} in template — "
            f"not in FormType {formtype} builder"
        )
    if len(new) > 200:
        print(f"... and {len(new) - 200} more")
    print(
        f"\ncheck_form_template_fields: {len(new)} new violation(s) "
        f"({baselined} baselined, {total} total)."
    )
    print("Fix options per (template, field):")
    print("  - add  ->add('<field>', ...)  to the FormType (intended new field), OR")
    print("  - remove the form.<field> reference from the template (dead ref), OR")
    print("  - baseline if intentionally dynamic: re-run with --write-baseline.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
