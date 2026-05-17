#!/usr/bin/env python3
"""
check_auto_form_field_whitelist.py — Gate 40.

Forbids `{% include '_components/_auto_form.html.twig' %}` invocations
that do NOT pass an explicit `sections:` or `fields:` whitelist. Without
one, the helper falls back to rendering every form-row Symfony exposes —
including audit_metadata, lockVersion, and other internal fields the
FormType author never intended to expose.

Symptom in prod: role-management edit form leaked the raw `permissions`
JSON field; fix a8bdf352 had to suppress it explicitly.

Allow:
  - explicit `fields:` array
  - explicit `sections:` map (covers fields via section.fields)
  - explicit `exclude:` array (when paired with an opt-out strategy)

Exit 0 = clean / baselined, Exit 1 = new violations.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TPL = ROOT / "templates"

# Match the include AND the trailing `with { ... }` arg, balanced braces.
RE_INCLUDE = re.compile(
    r"\{%-?\s*include\s+'_components/_auto_form\.html\.twig'"
    r"(?P<args>(?:[^{%]|\{[^%]|%(?!\}))*?)"
    r"%\}",
    re.DOTALL,
)
RE_TWIG_COMMENT = re.compile(r"\{#.*?#\}", re.DOTALL)


def _strip_comments(text: str) -> str:
    return RE_TWIG_COMMENT.sub(lambda m: re.sub(r"[^\n]", " ", m.group(0)), text)


def scan() -> list[tuple[Path, int]]:
    findings: list[tuple[Path, int]] = []
    for tpl in TPL.rglob("*.html.twig"):
        text = _strip_comments(tpl.read_text(encoding="utf-8", errors="ignore"))
        for m in RE_INCLUDE.finditer(text):
            args = m.group("args")
            has_whitelist = bool(
                re.search(r"\b(sections|fields|exclude)\s*:", args)
            )
            if not has_whitelist:
                ln = text.count("\n", 0, m.start()) + 1
                findings.append((tpl, ln))
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
    keys = [f"{_rel(p)}:{ln}" for p, ln in findings]

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_auto_form_field_whitelist.py baseline\n")
            for k in keys:
                fh.write(k + "\n")
        print(f"check_auto_form_field_whitelist: wrote {len(keys)} entries")
        return 0

    baseline = load_baseline(args.baseline)
    new = [(p, ln) for (p, ln), k in zip(findings, keys) if k not in baseline]
    total = len(findings)
    baselined = total - len(new)

    if not new:
        if not args.quiet:
            print(f"check_auto_form_field_whitelist: OK — {total} legacy, {baselined} baselined.")
        else:
            print(f"check_auto_form_field_whitelist: OK ({total}, all baselined)")
        return 0

    print("check_auto_form_field_whitelist: VIOLATIONS\n")
    for p, ln in new:
        print(f"FAIL {_rel(p)}:{ln}: _auto_form include without explicit `sections:`/`fields:`/`exclude:` whitelist")
    print(f"\ncheck_auto_form_field_whitelist: {len(new)} new ({baselined} baselined, {total} total).")
    print("Fix: declare the fields/sections the form should expose:")
    print("    {% include '_components/_auto_form.html.twig' with {")
    print("        form: form,")
    print("        sections: { ... } OR fields: [ ... ]")
    print("    } %}")
    return 1


if __name__ == "__main__":
    sys.exit(main())
