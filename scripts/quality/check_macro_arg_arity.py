#!/usr/bin/env python3
"""
check_macro_arg_arity.py — Gate 16.

Detects Twig macro callers that pass a wrong number of positional
arguments. Symptom in production: macros silently ignore extra args
(Twig doesn't error), so `render(form, {...})` against a 1-arg
`render(props)` resolves `props = form` (a FormView). Subsequent
`props.userField` lookups fail at runtime with the cryptic message
"Neither the property 'userField' nor one of the methods..."
(hit: incident/new + edit calling _fa_owner_picker).

Scope:
  - Scans Twig macros under templates/_components/*.html.twig
  - Records each macro's declared positional arg count
  - Scans all templates for `<importedAlias>.<macroName>( ... )` calls
  - Counts top-level positional args (commas at depth 0 of {}/[]/())
  - Flags calls whose positional arg count exceeds declared arity
    AND the extra args are not Twig keyword-style (e.g. `with`).

Conservative — only flags MORE args than expected (false-negative on
under-arity since macros accept missing positional args as null).

Exit 0 = clean / baselined, Exit 1 = new violations.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
COMP_DIR = ROOT / "templates" / "_components"
TEMPLATES_DIR = ROOT / "templates"

RE_MACRO_DEF = re.compile(
    r"\{%\s*macro\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(([^)]*)\)\s*%}",
    re.MULTILINE,
)
RE_TWIG_COMMENT = re.compile(r"\{#.*?#\}", re.DOTALL)


def _strip_comments(text: str) -> str:
    # Replace {# ... #} with same-length whitespace so byte/line offsets stay
    # stable for line-number reporting.
    return RE_TWIG_COMMENT.sub(lambda m: re.sub(r"[^\n]", " ", m.group(0)), text)
RE_IMPORT = re.compile(
    r"\{%\s*import\s+'([^']+)'\s+as\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*%}",
)


def collect_macros() -> dict[str, dict[str, int]]:
    """component_path -> { macro_name -> positional-arg-count }."""
    out: dict[str, dict[str, int]] = {}
    for comp in COMP_DIR.glob("*.html.twig"):
        text = comp.read_text(encoding="utf-8", errors="ignore")
        macros: dict[str, int] = {}
        for m in RE_MACRO_DEF.finditer(text):
            name = m.group(1)
            arg_str = m.group(2).strip()
            arity = 0 if not arg_str else len([
                a for a in _split_args(arg_str) if a.strip()
            ])
            macros[name] = arity
        if macros:
            rel = f"_components/{comp.name}"
            out[rel] = macros
    return out


def _split_args(s: str) -> list[str]:
    """Split args by top-level commas (ignore commas inside {}, [], ())."""
    out: list[str] = []
    buf: list[str] = []
    depth = 0
    for ch in s:
        if ch in "([{":
            depth += 1
        elif ch in ")]}":
            depth -= 1
        if ch == "," and depth == 0:
            out.append("".join(buf))
            buf = []
        else:
            buf.append(ch)
    if buf:
        out.append("".join(buf))
    return out


# Match a method-style call: alias.macroName ( ... )
# Captures: alias, macroName, args-string (balanced parens)
def find_calls(text: str, alias_to_path: dict[str, str]) -> list[tuple[str, str, str, int]]:
    """Return list of (alias, macroName, argsString, line-number)."""
    out: list[tuple[str, str, str, int]] = []
    if not alias_to_path:
        return out
    aliases = "|".join(re.escape(a) for a in alias_to_path)
    pattern = re.compile(rf"\b({aliases})\.([a-zA-Z_][a-zA-Z0-9_]*)\s*\(")
    for m in pattern.finditer(text):
        # walk balanced parens from m.end() - 1
        start = m.end() - 1
        depth = 0
        end = start
        for i in range(start, len(text)):
            if text[i] == "(":
                depth += 1
            elif text[i] == ")":
                depth -= 1
                if depth == 0:
                    end = i
                    break
        args_str = text[start + 1 : end]
        line_no = text.count("\n", 0, m.start()) + 1
        out.append((m.group(1), m.group(2), args_str, line_no))
    return out


def scan() -> list[tuple[Path, int, str, str, int, int]]:
    macros = collect_macros()
    findings: list[tuple[Path, int, str, str, int, int]] = []
    for tpl in TEMPLATES_DIR.rglob("*.html.twig"):
        raw = tpl.read_text(encoding="utf-8", errors="ignore")
        text = _strip_comments(raw)
        alias_to_path: dict[str, str] = {}
        for path, alias in RE_IMPORT.findall(text):
            # Normalize import path to match macros dict keys
            norm = path.lstrip("./")
            if norm in macros:
                alias_to_path[alias] = norm
            elif f"_components/{Path(path).name}" in macros:
                alias_to_path[alias] = f"_components/{Path(path).name}"
        if not alias_to_path:
            continue
        for alias, name, args, line in find_calls(text, alias_to_path):
            comp_path = alias_to_path[alias]
            arity = macros[comp_path].get(name)
            if arity is None:
                continue
            positional = [a for a in _split_args(args) if a.strip()]
            count = len(positional)
            if count > arity:
                findings.append((tpl, line, comp_path, name, arity, count))
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
    keys = [f"{_rel(p)}:{ln}:{comp}:{name}" for p, ln, comp, name, _, _ in findings]

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_macro_arg_arity.py baseline\n")
            fh.write("# Format: <template>:<line>:<component>:<macro>\n")
            for k in keys:
                fh.write(k + "\n")
        print(f"check_macro_arg_arity: wrote {len(keys)} entries to {args.write_baseline}")
        return 0

    baseline = load_baseline(args.baseline)
    new = [
        f for f, k in zip(findings, keys) if k not in baseline
    ]
    total = len(findings)
    baselined = total - len(new)

    if not new:
        if not args.quiet:
            print(f"check_macro_arg_arity: OK — {total} legacy, {baselined} baselined.")
        else:
            print(f"check_macro_arg_arity: OK ({total}, all baselined)")
        return 0

    print("check_macro_arg_arity: VIOLATIONS\n")
    for p, ln, comp, name, arity, count in new:
        print(
            f"FAIL {_rel(p)}:{ln}: {comp}::{name} declared {arity} arg(s) "
            f"but caller passes {count}"
        )
    print(f"\ncheck_macro_arg_arity: {len(new)} new ({baselined} baselined, {total} total).")
    print("Fix: drop extra positional args or change macro call to single props object.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
