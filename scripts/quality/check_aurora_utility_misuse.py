#!/usr/bin/env python3
"""
check_aurora_utility_misuse.py — Gate 35.

Detects Bootstrap size / font-size utility classes applied to Aurora
components — `fa-status-pill`, `fa-alert`, `fa-badge`, `fa-chip`,
`fa-cyber-btn`, `fa-feature-card`. Aurora-CSS owns the typography
inside those components; Bootstrap utilities only appear to "win"
because of selector specificity, but Aurora's `:where()`-scoped rules
or load-order let it override silently and inconsistently.

Symptom in prod: a pill labelled `fs-6` renders the wrong size on
prod browsers but looks correct in dev because of cache differences.

Recurring fix-commits 2026-05 (3 in a single week):
  - f0c180c2 fix(aurora): drop fs-* / size-override misuse on .fa-status-pill + .fa-alert
  - 54b45c6d fix(aurora): drop fs-* / size-override misuse on .fa-status-pill + .fa-alert
  - e0f5e70c fix(aurora): drop fs-* size-overrides via _badge include class: param

Allowed: `text-{primary,success,warning,danger,info,muted,white,body}`
on inner elements (these set color, not size).

Exit 0 = clean / baselined, Exit 1 = new violations.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TPL = ROOT / "templates"

AURORA_COMPONENTS = (
    "fa-status-pill", "fa-alert", "fa-badge", "fa-chip",
    "fa-cyber-btn", "fa-feature-card",
)
# Size-related Bootstrap utilities that silently override Aurora typography.
SIZE_UTILS = re.compile(
    r"\b(fs-[1-6]|fw-bold|fw-semibold|fw-light|"
    r"text-(?:xs|sm|md|lg|xl)|"
    r"small|"
    r"display-[1-6])\b"
)

RE_TWIG_COMMENT = re.compile(r"\{#.*?#\}", re.DOTALL)


def _strip_comments(text: str) -> str:
    return RE_TWIG_COMMENT.sub(lambda m: re.sub(r"[^\n]", " ", m.group(0)), text)


def scan() -> list[tuple[Path, int, str, str]]:
    findings: list[tuple[Path, int, str, str]] = []
    aurora_pat = re.compile(
        r'class="([^"]*\b(?:' + "|".join(AURORA_COMPONENTS) + r')\b[^"]*)"'
    )
    for tpl in TPL.rglob("*.html.twig"):
        # Skip the design-system showcase under _components/ — intentional
        # demo of Aurora-vs-Bootstrap-utility precedence.
        if tpl.parts and "_components" in tpl.parts:
            continue
        text = _strip_comments(tpl.read_text(encoding="utf-8", errors="ignore"))
        for m in aurora_pat.finditer(text):
            klass = m.group(1)
            mu = SIZE_UTILS.search(klass)
            if mu:
                ln = text.count("\n", 0, m.start()) + 1
                comp = next((c for c in AURORA_COMPONENTS if c in klass), "fa-?")
                findings.append((tpl, ln, comp, mu.group(0)))
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
    keys = [f"{_rel(p)}:{ln}:{comp}:{util}" for p, ln, comp, util in findings]

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_aurora_utility_misuse.py baseline\n")
            for k in keys:
                fh.write(k + "\n")
        print(f"check_aurora_utility_misuse: wrote {len(keys)} entries")
        return 0

    baseline = load_baseline(args.baseline)
    new = [
        (p, ln, comp, util)
        for (p, ln, comp, util), k in zip(findings, keys)
        if k not in baseline
    ]
    total = len(findings)
    baselined = total - len(new)

    if not new:
        if not args.quiet:
            print(f"check_aurora_utility_misuse: OK — {total} legacy, {baselined} baselined.")
        else:
            print(f"check_aurora_utility_misuse: OK ({total}, all baselined)")
        return 0

    print("check_aurora_utility_misuse: VIOLATIONS\n")
    for p, ln, comp, util in new:
        print(f"FAIL {_rel(p)}:{ln}: Aurora component '{comp}' + Bootstrap utility '{util}'")
    print(f"\ncheck_aurora_utility_misuse: {len(new)} new ({baselined} baselined, {total} total).")
    print("Fix: drop the BS utility — Aurora owns typography on its components.")
    print("If you need a smaller pill, use the macro's `size:'sm'` / `size:'lg'` prop instead.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
