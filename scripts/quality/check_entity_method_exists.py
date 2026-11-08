#!/usr/bin/env python3
"""
check_entity_method_exists.py — Gate 83: no method_exists() on entities.

`method_exists($finding, 'getResponsiblePersonUser')` on a typed entity is
either always true (dead defensive code) or always false — and then the
feature silently does nothing. The norm-coverage check (2026-11) found four
such always-false calls in My Day alone: findings never showed up, document
reviews missed their owner, data-subject requests had no deadline. PHPStan
reports only the always-true case (entities are not final) and its baseline
had swallowed those.

For every `method_exists($var, 'name')` in src/ (outside src/Entity/) whose
variable is typed as an App\\Entity class in the enclosing function (parameter
type, `/** @var X $var */`, `$var instanceof X`, `$var = new X`), the gate
fails:
  * always false — the entity (with traits and parents) has no such method;
  * always true  — call the method directly.
Untyped variables (object, mixed, unions of unrelated classes) are left alone.
It also fails when phpstan-baseline.neon swallows a method_exists() finding on
an entity, so the static check cannot be re-baselined away.

    python3 scripts/quality/check_entity_method_exists.py
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SRC = ROOT / "src"
ENTITY_DIR = SRC / "Entity"
PHPSTAN_BASELINE = ROOT / "phpstan-baseline.neon"

CLASS_DECL = re.compile(r"^\s*(?:final\s+|abstract\s+|readonly\s+)*(class|trait|interface)\s+(\w+)"
                        r"(?:\s+extends\s+([\w\\]+))?", re.M)
METHOD = re.compile(r"\bfunction\s+&?(\w+)\s*\(")
TRAIT_USE = re.compile(r"^\s{4}use\s+([\w\\,\s]+);", re.M)
NAMESPACE = re.compile(r"^namespace\s+([\w\\]+);", re.M)
IMPORT = re.compile(r"^use\s+([\w\\]+)(?:\s+as\s+(\w+))?;", re.M)
CALL = re.compile(r"""method_exists\(\s*(?:\$(?P<var>\w+)|\$this->(?P<prop>\w+)|(?P<cls>\\?[\w\\]+)::class)\s*,\s*['"](?P<m>\w+)['"]\s*\)""")
FUNC = re.compile(r"\bfunction\s+&?\w*\s*\(([^)]*)\)", re.S)
# Any callable signature: named functions, closures and arrow functions (`fn(Risk $r) => …`).
SIGNATURE = re.compile(r"\b(?:function\s*&?(?P<name>\w*)|(?P<fn>fn))\s*\((?P<params>[^)]*)\)", re.S)


def imports(text: str) -> tuple[str, dict[str, str]]:
    ns_match = NAMESPACE.search(text)
    namespace = ns_match.group(1) if ns_match else ""
    table: dict[str, str] = {}
    for m in IMPORT.finditer(text):
        fqcn = m.group(1)
        table[m.group(2) or fqcn.rsplit("\\", 1)[-1]] = fqcn
    return namespace, table


def resolve(name: str, namespace: str, table: dict[str, str]) -> str:
    name = name.lstrip("?")
    if name.startswith("\\"):
        return name[1:]
    head = name.split("\\", 1)[0]
    if head in table:
        return table[head] + name[len(head):]
    return f"{namespace}\\{name}" if namespace else name


def entity_index() -> dict[str, dict]:
    """FQCN → {methods, traits, parent} for src/Entity/**."""
    index: dict[str, dict] = {}
    for path in ENTITY_DIR.rglob("*.php"):
        text = path.read_text(encoding="utf-8")
        decl = CLASS_DECL.search(text)
        if not decl:
            continue
        namespace, table = imports(text)
        fqcn = f"{namespace}\\{decl.group(2)}"
        body = text[decl.end():]
        traits = []
        for m in TRAIT_USE.finditer(body):
            traits += [resolve(t.strip(), namespace, table) for t in m.group(1).split(",") if t.strip()]
        index[fqcn] = {
            "methods": {m.lower() for m in METHOD.findall(body)},
            "traits": traits,
            "parent": resolve(decl.group(3), namespace, table) if decl.group(3) else None,
            "magic": "__call" in body,
        }
    return index


def methods_of(fqcn: str, index: dict[str, dict], seen: set[str] | None = None) -> set[str] | None:
    """All methods incl. traits and parents; None when unknowable (magic, outside src/Entity)."""
    seen = seen or set()
    if fqcn in seen:
        return set()
    seen.add(fqcn)
    info = index.get(fqcn)
    if info is None or info["magic"]:
        return None
    out = set(info["methods"])
    for related in info["traits"] + ([info["parent"]] if info["parent"] else []):
        if related.startswith("App\\Entity\\"):
            more = methods_of(related, index, seen)
            if more is None:
                return None
            out |= more
        elif related:  # vendor parent/trait: cannot know its methods
            return None
    return out


def var_type(text: str, pos: int, var: str) -> str | None:
    """Declared type of $var in the function enclosing pos."""
    start = max(text.rfind("function ", 0, pos), 0)
    scope = text[start:pos]
    sig = FUNC.match(scope) or FUNC.search(scope[:400])
    candidates: list[str] = []
    param_type = None
    if sig:
        for param in sig.group(1).split(","):
            m = re.search(r"([?\w\\|]+)\s+(?:&|\.\.\.)?\$" + var + r"\b", param)
            if m:
                param_type = m.group(1)
    for pattern in (r"@var\s+([?\w\\|]+)\s+\$" + var + r"\b",
                    r"\$" + var + r"\s+instanceof\s+([\w\\]+)",
                    r"\$" + var + r"\s*=\s*new\s+([\w\\]+)"):
        found = re.findall(pattern, scope)
        if found:
            candidates.append(found[-1])
    # A declared parameter type wins: `object $entity` stays untyped even when
    # a branch narrows it with instanceof.
    if param_type is None:
        # Closures and arrow functions inside the method declare their own parameters: take the nearest
        # signature before the call that declares $var, but never look past the enclosing named method.
        for sig_m in reversed(list(SIGNATURE.finditer(text[:pos]))):
            declared = None
            for param in sig_m.group("params").split(","):
                pm = re.search(r"([?\w\\|]+)\s+(?:&|\.\.\.)?\$" + var + r"\b", param)
                if pm:
                    declared = pm.group(1)
            if declared is not None:
                param_type = declared
                break
            if sig_m.group("name"):
                break
    if param_type is not None:
        candidates = [param_type]
    for candidate in reversed(candidates):
        parts = [p for p in candidate.lstrip("?").split("|") if p.lower() != "null"]
        if len(parts) == 1:
            return parts[0]
    return None


def baseline_errors() -> list[str]:
    if not PHPSTAN_BASELINE.exists():
        return []
    errors = []
    for i, line in enumerate(PHPSTAN_BASELINE.read_text(encoding="utf-8").splitlines()):
        if "method_exists" in line and "App\\\\Entity\\\\" in line:
            errors.append(f"phpstan-baseline.neon:{i + 1}: method_exists() finding on an entity is baselined "
                          "— fix the call instead")
    return errors


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    index = entity_index()
    errors: list[str] = []
    for path in sorted(SRC.rglob("*.php")):
        if ENTITY_DIR in path.parents:
            continue
        text = path.read_text(encoding="utf-8")
        if "method_exists(" not in text:
            continue
        namespace, table = imports(text)
        for m in CALL.finditer(text):
            var, method = m.group("var") or m.group("prop") or m.group("cls"), m.group("m")
            if var == "this":
                continue
            if m.group("cls"):  # method_exists(Risk::class, 'x')
                declared = m.group("cls")
            elif m.group("prop"):  # method_exists($this->risk, 'x'): typed property or promoted ctor parameter
                pm = re.search(r"(?:private|protected|public)\s+(?:readonly\s+)?([?\w\\|]+)\s+\$" + re.escape(var) + r"\b", text)
                declared = pm.group(1) if pm else None
                if declared is not None and "|" in declared.lstrip("?"):
                    parts = [p for p in declared.lstrip("?").split("|") if p.lower() != "null"]
                    declared = parts[0] if len(parts) == 1 else None
                declared = declared.lstrip("?") if declared else None
            else:
                declared = var_type(text, m.start(), var)
            if declared is None:
                continue
            fqcn = resolve(declared, namespace, table)
            if not fqcn.startswith("App\\Entity\\"):
                continue
            known = methods_of(fqcn, index)
            if known is None:
                continue
            line = text.count("\n", 0, m.start()) + 1
            short = fqcn.rsplit("\\", 1)[-1]
            where = f"{path.relative_to(ROOT)}:{line}"
            if method.lower() in known:
                errors.append(f"{where}: method_exists(${var}, '{method}') is always true — call {short}::{method}()")
            else:
                errors.append(f"{where}: method_exists(${var}, '{method}') is always false — {short} has no "
                              f"{method}(), so this code never runs")
    errors += baseline_errors()

    if errors:
        print(f"Gate 83: {len(errors)} method_exists() call(s) on entities:")
        for e in errors:
            print(f"  {e}")
        return 1
    if not args.quiet:
        print("Gate 83: no method_exists() on typed entities.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
