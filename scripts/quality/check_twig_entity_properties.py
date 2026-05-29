#!/usr/bin/env python3
"""Quality gate: catch Twig accesses to entity properties/methods that do not exist.

`php bin/console lint:twig` validates SYNTAX only — it never checks that
`{{ risk.supplier.email }}` actually resolves to a real accessor on the
Supplier entity. A renamed/typo'd field therefore compiles fine and only blows
up at runtime with a Twig RuntimeError → HTTP 500, exactly when that branch is
rendered (e.g. viewing a risk that has a supplier).

This gate resolves Doctrine to-one ASSOCIATIONS (e.g. Risk.supplier → Supplier)
and, for every template access of the form `<expr>.<association>.<member>`,
verifies that `<member>` is a real accessor on the association's target entity.
Association chains are used because the middle segment is provably an entity, so
the check is high-confidence and low-noise (translation keys / JS / arrays do
not chain through a real association name preceded by an identifier).

Exit code 1 if any unknown member is found, 0 otherwise.
"""
from __future__ import annotations

import glob
import os
import re
import sys

ENTITY_DIR = "src/Entity"
TEMPLATE_GLOB = "templates/**/*.twig"

# Twig also exposes Doctrine Collection / array helpers on to-many sides and on
# arrays; never flag these as missing entity members.
COLLECTION_AND_TWIG_MEMBERS = {
    "count", "length", "first", "last", "contains", "isempty", "slice", "keys",
    "values", "toarray", "getvalues", "getkeys", "matching", "map", "filter",
    "reduce", "indexof", "containskey", "get", "set", "add", "remove",
    # Symfony FormView members — a relation-named field can also be a form child
    # (`form.subType.vars`); these are never entity members.
    "vars", "children", "parent", "rendered", "methodrendered",
    # Browser location/JS members reachable via window.location in inline JS that
    # is not wrapped in a <script> block (e.g. onclick attributes).
    "href", "reload", "origin", "assign", "replace", "pathname", "search",
    "hash", "hostname", "protocol",
}


def accessors_for_entity(php: str) -> set[str]:
    """All names Twig can resolve on the entity: public props + get/is/has*."""
    members: set[str] = set()
    for m in re.finditer(r"public\s+function\s+(get|is|has)([A-Za-z0-9_]+)\s*\(", php):
        members.add((m.group(2)[0].lower() + m.group(2)[1:]).lower())
    # Plain public methods Twig can call as `entity.method`.
    for m in re.finditer(r"public\s+function\s+([a-z][A-Za-z0-9_]*)\s*\(", php):
        members.add(m.group(1).lower())
    # Public properties.
    for m in re.finditer(r"public\s+(?:readonly\s+)?\??[\\A-Za-z0-9_|]+\s+\$([a-zA-Z0-9_]+)", php):
        members.add(m.group(1).lower())
    return members


def main() -> int:
    entity_files = glob.glob(os.path.join(ENTITY_DIR, "*.php"))
    entity_names = {os.path.basename(f)[:-4] for f in entity_files}

    accessors: dict[str, set[str]] = {}
    # association field name -> set of possible target entity class names
    assoc_targets: dict[str, set[str]] = {}

    for f in entity_files:
        name = os.path.basename(f)[:-4]
        php = open(f, encoding="utf-8", errors="ignore").read()
        accessors[name] = accessors_for_entity(php)
        # to-one associations: `private ?Supplier $supplier`, `private Tenant $tenant`
        for m in re.finditer(r"(?:private|protected)\s+\??\\?([A-Za-z0-9_]+)\s+\$([a-zA-Z0-9_]+)\s*(?:=|;)", php):
            target, field = m.group(1), m.group(2)
            if target in entity_names:
                assoc_targets.setdefault(field, set()).add(target)

    # Regex: `<base>.<assoc>.<member>`. Capturing the base lets us drop accesses
    # rooted in well-known aggregate/array variables (KPI/stats dashboards) where
    # a relation-named key is an array entry, not a real entity association.
    access_re = re.compile(r"\b([A-Za-z_][A-Za-z0-9_]*)\.([A-Za-z_][A-Za-z0-9_]*)\.([A-Za-z_][A-Za-z0-9_]*)")
    ARRAY_BASE_VARS = {
        "kpis", "kpi", "stats", "statistics", "metrics", "summary", "totals",
        "counts", "data", "chart", "charts", "report", "reports", "result",
        "results", "config", "settings", "options", "params",
    }

    def blank_noise(text: str) -> str:
        """Replace string literals and <script> bodies with spaces, preserving
        offsets and newlines so line numbers stay correct. This removes
        translation keys (`'emails.workflow.x'|trans`) and inline JS
        (`window.location.href`) — the dominant false-positive sources."""
        def spaces(m: re.Match) -> str:
            return "".join(c if c == "\n" else " " for c in m.group(0))
        text = re.sub(r"<script\b[^>]*>.*?</script>", spaces, text, flags=re.DOTALL | re.IGNORECASE)
        text = re.sub(r"'[^'\n]*'", spaces, text)
        text = re.sub(r'"[^"\n]*"', spaces, text)
        return text

    findings: list[tuple[str, int, str, str, str]] = []
    for tpl in glob.glob(TEMPLATE_GLOB, recursive=True):
        raw = open(tpl, encoding="utf-8", errors="ignore").read()
        for lineno, line in enumerate(blank_noise(raw).splitlines(), 1):
            for m in access_re.finditer(line):
                base, assoc, member = m.group(1), m.group(2), m.group(3)
                if base.lower() in ARRAY_BASE_VARS:
                    continue
                targets = assoc_targets.get(assoc)
                if not targets:
                    continue
                if member.lower() in COLLECTION_AND_TWIG_MEMBERS:
                    continue
                # snake_case -> camelCase (Twig tries this when resolving getters)
                camel = re.sub(r"_([a-z])", lambda x: x.group(1).upper(), member).lower()
                # valid if the member exists on ANY plausible target entity
                if any(member.lower() in accessors[t] or camel in accessors[t] for t in targets):
                    continue
                findings.append((tpl, lineno, assoc, member, "|".join(sorted(targets))))

    if not findings:
        print("OK: no Twig accesses to unknown entity members found.")
        return 0

    print(f"FAIL: {len(findings)} Twig access(es) to members that do not exist on the target entity:\n")
    for tpl, lineno, assoc, member, target in findings:
        print(f"  {tpl}:{lineno}  .{assoc}.{member}  — '{member}' not on {target}")
    return 1


if __name__ == "__main__":
    sys.exit(main())
