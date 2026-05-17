#!/usr/bin/env python3
"""
check_form_render_completeness.py — Gate 15.

Detects Twig templates that call `form_start(form)` + `form_end(form)`
WITHOUT a catch-all (`form_widget(form)`, `form_rest(form)`, or
`{% include '_components/_auto_form.html.twig' %}`), AND render only a
SUBSET of the FormType's `->add(...)` fields via `form_row(form.X)`
or `{% do form.X.setRendered %}`.

Symptom in production: `form_end()` auto-renders the leftover fields
AFTER the explicit submit button — fields visually appear "below" the
submit, breaking the form layout (hit 3× in incident/new + edit,
audit_finding/_form, corrective_action/_form).

Matching heuristic for template ↔ FormType:
  - templates/<dir>/...     -> <Dir>Type (CamelCase) in src/Form/
  - templates/<a>/<b>/...   -> <AB>Type joined
  - Manual overrides for known aliases (bc_exercise → BusinessContinuityExercise).

Whitelist (the gate skips):
  - render_rest:false in form_end options
  - form_rest(form)
  - form_widget(form)            (full auto-render)
  - {% include '_components/_auto_form.html.twig' %}
  - templates under _components/  (design-system showcases)

Exit 0 = clean / all baselined, Exit 1 = new violations.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
FORMS_DIR = ROOT / "src" / "Form"
TEMPLATES_DIR = ROOT / "templates"

RE_ADD = re.compile(r"->add\(\s*'([a-zA-Z_][a-zA-Z0-9_]*)'")
RE_ROW = re.compile(r"form_row\(\s*form\.([a-zA-Z_][a-zA-Z0-9_]*)")
RE_LABEL = re.compile(r"form_label\(\s*form\.([a-zA-Z_][a-zA-Z0-9_]*)")
RE_WIDGET_FIELD = re.compile(r"form_widget\(\s*form\.([a-zA-Z_][a-zA-Z0-9_]*)")
RE_SET_RENDERED = re.compile(r"\{%\s*do\s+form\.([a-zA-Z_][a-zA-Z0-9_]*)\.setRendered")
RE_FORM_START = re.compile(r"\{\{\s*form_start\(\s*form")
RE_FORM_END = re.compile(r"\{\{\s*form_end\(\s*form")
RE_RENDER_REST_FALSE = re.compile(r"render_rest\s*[:=]\s*false")
RE_FORM_REST = re.compile(r"\{\{\s*form_rest\(\s*form\s*\)")
RE_FORM_WIDGET_FULL = re.compile(r"\{\{\s*form_widget\(\s*form\s*\)")
RE_FORM_FULL = re.compile(r"\{\{\s*form\(\s*form")  # `{{ form(form) }}`
RE_AUTO_FORM = re.compile(r"_auto_form\.html\.twig")
RE_FORM_THEME = re.compile(r"\{%\s*form_theme\s+form")

ALIASES = {
    "bc_exercise": "BusinessContinuityExerciseType",
    "bc_plans": "BusinessContinuityPlanType",
    "business_continuity_plan": "BusinessContinuityPlanType",
}


def collect_form_fields() -> dict[str, set[str]]:
    out: dict[str, set[str]] = {}
    for ft in FORMS_DIR.rglob("*Type.php"):
        text = ft.read_text(encoding="utf-8", errors="ignore")
        fields = set(RE_ADD.findall(text))
        if fields:
            out[ft.stem] = fields
    return out


def candidates_for(rel_parts: tuple[str, ...]) -> list[str]:
    parts = list(rel_parts[:-1])  # drop filename
    out: list[str] = []
    for seg in parts:
        out.append("".join(p.capitalize() for p in seg.split("_")) + "Type")
    if parts:
        out.append("".join("".join(p.capitalize() for p in seg.split("_")) for seg in parts) + "Type")
        if parts[0] in ALIASES:
            out.append(ALIASES[parts[0]])
    return out


def template_uses_catchall(text: str) -> bool:
    return any(r.search(text) for r in (
        RE_RENDER_REST_FALSE,
        RE_FORM_REST,
        RE_FORM_WIDGET_FULL,
        RE_FORM_FULL,
        RE_AUTO_FORM,
        RE_FORM_THEME,  # custom form theme — likely intentional
    ))


def rendered_fields(text: str) -> set[str]:
    out: set[str] = set()
    out.update(RE_ROW.findall(text))
    out.update(RE_LABEL.findall(text))
    out.update(RE_WIDGET_FIELD.findall(text))
    out.update(RE_SET_RENDERED.findall(text))
    return out


def scan() -> list[tuple[Path, str, list[str]]]:
    form_fields = collect_form_fields()
    findings: list[tuple[Path, str, list[str]]] = []
    for tpl in TEMPLATES_DIR.rglob("*.html.twig"):
        rel_parts = tpl.relative_to(TEMPLATES_DIR).parts
        if rel_parts and rel_parts[0] == "_components":
            continue
        text = tpl.read_text(encoding="utf-8", errors="ignore")
        if not RE_FORM_START.search(text) or not RE_FORM_END.search(text):
            continue
        if template_uses_catchall(text):
            continue
        rendered = rendered_fields(text)
        if not rendered:
            continue
        candidates = candidates_for(rel_parts)
        matched = next((c for c in candidates if c in form_fields), None)
        if matched is None:
            continue
        missing = sorted(form_fields[matched] - rendered)
        if missing:
            findings.append((tpl, matched, missing))
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


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--baseline", type=Path, default=None)
    ap.add_argument("--write-baseline", type=Path, default=None)
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    findings = scan()
    keys = [f"{_rel(p)} :: {ft}" for p, ft, _ in findings]

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_form_render_completeness.py baseline\n")
            fh.write("# Format: <template-path> :: <FormTypeName>\n")
            for k in keys:
                fh.write(k + "\n")
        print(f"check_form_render_completeness: wrote {len(keys)} entries to {args.write_baseline}")
        return 0

    baseline = load_baseline(args.baseline)
    new = [(p, ft, miss) for (p, ft, miss), k in zip(findings, keys) if k not in baseline]
    total = len(findings)
    baselined = total - len(new)

    if not new:
        if not args.quiet:
            print(f"check_form_render_completeness: OK — {total} legacy, {baselined} baselined.")
        else:
            print(f"check_form_render_completeness: OK ({total}, all baselined)")
        return 0

    print("check_form_render_completeness: VIOLATIONS\n")
    for p, ft, miss in new:
        print(f"FAIL {_rel(p)} (against {ft})")
        print(f"     missing form_row/form_label/setRendered for: {miss}")
    print(f"\ncheck_form_render_completeness: {len(new)} new ({baselined} baselined, {total} total).")
    print("Fix options per field X:")
    print("  - add  {{ form_row(form.X) }}  in a section, OR")
    print("  - mark intentionally hidden:  {% do form.X.setRendered %}, OR")
    print("  - if controller passes raw form, add  {% include '_components/_auto_form.html.twig' %}")
    return 1


if __name__ == "__main__":
    sys.exit(main())
