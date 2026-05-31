#!/usr/bin/env python3
"""Gate: forbidden (renamed/removed) entity getters used in Twig templates.

A Twig accessor like ``incident.detectedDate`` resolves to ``getDetectedDate()``.
When a field is renamed on the entity but a rarely-rendered email/PDF template
still uses the old name, Twig throws a *runtime* RuntimeError the moment the
template is produced — it sails through ``lint:twig`` and unit tests. This has
bitten us repeatedly (controls/incidents PDF, training/control emails,
treatment-plan emails).

A full getter-existence check is too false-positive-prone to gate on: ``document``
collides with the JS DOM object, and entity names like ``risk``/``control`` are
freely reused as plain array rows in analytics templates. So instead this gate
keeps an explicit DENY-LIST of accessors that were renamed/removed — each maps a
``<var>.<dead-accessor>`` to its replacement. It is zero-false-positive and
catches the real recurring failure mode: the same dead field rotting across
several templates, and regressions that re-introduce it.

When you rename an entity getter, add the old ``<var>.<old>`` here.
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TEMPLATE_DIR = ROOT / "templates"

# (loop-var, dead-accessor) -> "replacement (for the error hint)".
# Optionally scope a rule to templates whose path contains a substring by using
# a 3rd tuple element; otherwise it applies to every template.
DENY: dict[tuple[str, str], str] = {
    ("control", "implementationProgress"): "implementationPercentage",
    ("incident", "detectedDate"): "detectedAt",
    ("incident", "resolvedDate"): "resolvedAt",
    ("training", "trainingDate"): "scheduledDate",
    ("training", "duration"): "durationMinutes",
    ("plan", "deadline"): "targetCompletionDate",
    ("plan", "daysRemaining"): "daysUntilTarget",
}
# Rules whose var name is ambiguous → only apply inside matching template paths.
SCOPE: dict[tuple[str, str], str] = {
    ("plan", "deadline"): "treatment_plan",
    ("plan", "daysRemaining"): "treatment_plan",
}


TWIG_EXPR = re.compile(r"\{\{.*?\}\}|\{%.*?%\}", re.DOTALL)


def _blank_literal(m: "re.Match[str]") -> str:
    q = m.group()[0]
    return q + " " * (len(m.group()) - 2) + q


def clean(text: str) -> str:
    """Blank Twig string literals while preserving every character position
    (so line numbers stay correct). Only the contents of {{ }} / {% %} spans are
    touched — including multi-line ``{% set %}`` blocks — so HTML attribute
    quotes (e.g. style="…{{ control.x }}…") are left intact, while Twig string
    keys like 'emails.training.duration'|trans are blanked."""
    def repl(m: "re.Match[str]") -> str:
        span = m.group()
        span = re.sub(r"'[^']*'", _blank_literal, span)
        span = re.sub(r'"[^"]*"', _blank_literal, span)
        return span

    return TWIG_EXPR.sub(repl, text)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    patterns = {
        (var, dead): re.compile(rf"(?<![\w'\"]){re.escape(var)}\.{re.escape(dead)}\b")
        for (var, dead) in DENY
    }

    violations: list[str] = []
    for tmpl in sorted(TEMPLATE_DIR.rglob("*.html.twig")):
        rel = tmpl.relative_to(ROOT).as_posix()
        try:
            lines = clean(tmpl.read_text(encoding="utf-8", errors="ignore")).splitlines()
        except OSError:
            continue
        for (var, dead), repl in DENY.items():
            needle = SCOPE.get((var, dead))
            if needle is not None and needle not in rel:
                continue
            rx = patterns[(var, dead)]
            for i, line in enumerate(lines, 1):
                if rx.search(line):
                    violations.append(f"{rel}:{i}: {var}.{dead} → use {var}.{repl}")

    if not violations:
        if not args.quiet:
            print(f"check_template_entity_getters: OK ({len(DENY)} deny-rules, 0 hits)")
        return 0

    print("check_template_entity_getters: RENAMED/REMOVED entity getter used in template")
    print()
    for v in violations:
        print(f"FAIL {v}")
    print()
    print(f"check_template_entity_getters: {len(violations)} hit(s).")
    print("These accessors no longer exist on the entity → Twig RuntimeError at render.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
