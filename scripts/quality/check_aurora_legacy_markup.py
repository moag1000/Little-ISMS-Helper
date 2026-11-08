#!/usr/bin/env python3
"""
check_aurora_legacy_markup.py — Gate 62.

Ratchet for Bootstrap markup that has an Aurora v4 replacement. Existing
occurrences are counted per file in a baseline; a file may only go down,
never up, and a file outside the baseline must be clean.

Patterns and their Aurora replacement:
  raw_table           <table class="table …">          → _fa_table (fa-table BEM)
  bs_progress         .progress-bar                    → _fa_progress
  legacy_page_header  _components/_page_header         → _fa_page_header
  bs_modal            data-bs-toggle="modal", bootstrap.Modal → fa-modal dispatcher
  bs_badge            .badge + bg-*/text-bg-*          → _badge / fa-status-pill
  bs_badge_literal    any class="badge …" / "badge-{{ x }}" (also single quotes, Twig-built
                      tones) → fa-status-pill fa-status-pill--<tone>
  bs_alert            .alert.alert-*                   → _fa_alert
  bs_btn              .btn.btn-*                       → fa-cyber-btn

Bootstrap classes still render in Aurora style through the CSS bridges in
fairy-aurora-components.css; the ratchet keeps new code on the components.

Skipped: templates/dev/ (design-system showcase), emails/ and PDF/export
templates (any pdf/ directory) (inline-styled for mail clients and dompdf), and the component
files that implement the bridges themselves.

Usage:
    python3 scripts/quality/check_aurora_legacy_markup.py --baseline scripts/quality/baselines/aurora_legacy_markup.txt
    python3 scripts/quality/check_aurora_legacy_markup.py --write-baseline scripts/quality/baselines/aurora_legacy_markup.txt

Exit 0 = clean / within baseline, 1 = regression.
"""
from __future__ import annotations

import argparse
import re
import sys
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TPL = ROOT / "templates"

def _both(src: str) -> str:
    """Pattern source for the double-quoted attribute form plus the single-quoted one (class='btn btn-primary')."""
    sq = src.replace("[\\s\\']", "@@").replace('"', "'").replace("@@", '[\\s"]')
    return src + "|" + sq


# Twig hash passed to a macro: {class: 'btn btn-primary'} / {'class': "btn btn-danger"}.
_HASH_CLASS = r"""['"]?class['"]?\s*:\s*['"](?:[^'"]*\s)?"""

PATTERNS: dict[str, re.Pattern[str]] = {
    "raw_table": re.compile(_both(r'<table\b[^>]*\bclass="(?:[^"]*\s)?table(?:\s[^"]*)?"')),
    "bs_progress": re.compile(_both(r'class="(?:[^"]*\s)?progress-bar(?:\s[^"]*)?"')),
    "legacy_page_header": re.compile(r"_components/_page_header\.html\.twig"),
    "bs_modal": re.compile(
        r"""data-bs-toggle\s*=\s*["']modal["']|bootstrap\.Modal\b|['"]?data-bs-toggle['"]?\s*:\s*['"]modal['"]"""
    ),
    # The class may sit inside a Twig ternary ('badge bg-x') and the tone may be computed
    # (bg-{{ tone }}), so a quote counts as a boundary and "{{" as a tone.
    "bs_badge": re.compile(_both(r'class="(?:[^"]*[\s\'])?badge\s(?:[^"]*\s)?(?:text-)?bg-(?:[a-z]|\{\{)')),
    # Any element whose class list contains the bare word "badge" (or "badge-{{ … }}"): the
    # tone is computed in Twig so bs_badge misses it. "badges", "badge-matrix-zero" and a project
    # class like "timeline-action badge-{{ x }}" do not match.
    "bs_badge_literal": re.compile(r"""class=["'](?:(?:[^"']*\s)?badge[\s"']|badge-\{\{)"""),
    # Also the reversed order (class="alert-danger alert") and the macro-hash form.
    "bs_alert": re.compile(
        _both(r'class="(?:[^"]*[\s\'])?alert\s(?:[^"]*\s)?alert-(?:[a-z]|\{\{)')
        + "|" + _both(r'class="(?:[^"]*\s)?alert-(?:[a-z]|\{\{)[^"]*\salert[\s"]')
        + "|" + _HASH_CLASS + r"alert\s(?:[^'\"]*\s)?alert-[a-z]"
    ),
    "bs_btn": re.compile(
        _both(r'class="(?:[^"]*[\s\'])?btn\s(?:[^"]*\s)?btn-(?:[a-z]|\{\{)')
        + "|" + _both(r'class="(?:[^"]*\s)?btn-(?:[a-z]|\{\{)[^"]*\sbtn[\s"]')
        + "|" + _HASH_CLASS + r"btn\s(?:[^'\"]*\s)?btn-[a-z]"
    ),
}

SKIP_DIRS = ("dev", "emails", "pdf")
# Only documents that are really print/PDF output: "pdf" as a word of the file name. A bare substring
# test ("_export", "export_") also exempted UI such as _export_bar.html.twig.
SKIP_NAME = re.compile(r"(?:^|[_.\-])pdf(?:[_.\-]|$)|export_pdf")
SKIP_FILES = {
    "_components/_page_header.html.twig",
    "_components/_badge.html.twig",
    "_components/_fa_table.html.twig",
    "_components/_fa_progress.html.twig",
    # Stand-alone recovery page with its own inline CSS (no Bootstrap/Aurora loaded),
    # so its .progress-bar is a local class, not Bootstrap markup.
    "quick_fix/job_progress.html.twig",
    # Stand-alone printable HTML documents with their own <style> (.badge-success etc. are local).
    "soa/export.html.twig",
    # Stand-alone BfDI print document with its own <style> (renamed from the "export_" filename skip).
    "processing_activity/vvt_bfdi_export.html.twig",
}

RE_TWIG_COMMENT = re.compile(r"\{#.*?#\}", re.DOTALL)


def skipped(rel: str) -> bool:
    parts = rel.split("/")
    if parts[0] in SKIP_DIRS or "pdf" in parts[:-1] or rel in SKIP_FILES:
        return True
    name = parts[-1]
    return bool(SKIP_NAME.search(name))


def scan() -> Counter[str]:
    counts: Counter[str] = Counter()
    for tpl in sorted(TPL.rglob("*.twig")):
        rel = tpl.relative_to(TPL).as_posix()
        if skipped(rel):
            continue
        text = RE_TWIG_COMMENT.sub("", tpl.read_text(encoding="utf-8", errors="ignore"))
        for key, pat in PATTERNS.items():
            n = len(pat.findall(text))
            if n:
                counts[f"templates/{rel}:{key}"] = n
    return counts


def load_baseline(path: Path) -> dict[str, int]:
    out: dict[str, int] = {}
    if not path.exists():
        return out
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        key, _, n = line.rpartition(":")
        out[key] = int(n)
    return out


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--baseline", type=Path, default=None)
    ap.add_argument("--write-baseline", type=Path, default=None)
    args = ap.parse_args()

    counts = scan()

    if args.write_baseline:
        lines = [
            "# check_aurora_legacy_markup.py baseline — Bootstrap markup with an Aurora replacement.",
            "# Format: <template>:<pattern>:<count>. Counts may only go down.",
        ] + [f"{k}:{v}" for k, v in sorted(counts.items())]
        args.write_baseline.write_text("\n".join(lines) + "\n", encoding="utf-8")
        print(f"check_aurora_legacy_markup: wrote {len(counts)} entries to {args.write_baseline}")
        return 0

    baseline = load_baseline(args.baseline) if args.baseline else {}
    regressions = [(k, v, baseline.get(k, 0)) for k, v in sorted(counts.items()) if v > baseline.get(k, 0)]
    shrunk = [k for k, v in baseline.items() if counts.get(k, 0) < v]

    totals: Counter[str] = Counter()
    for k, v in counts.items():
        totals[k.rsplit(":", 1)[1]] += v
    summary = ", ".join(f"{p} {totals.get(p, 0)}" for p in PATTERNS)

    if regressions:
        print("check_aurora_legacy_markup: Bootstrap markup added where an Aurora component exists\n")
        for k, v, b in regressions:
            print(f"FAIL {k}: {v} (baseline {b})")
        print("\nUse the Aurora component instead (see the pattern list at the top of this script"
              " and CLAUDE.md 'Aurora v4 Components').")
        return 1

    print(f"check_aurora_legacy_markup: OK ({summary})")
    if shrunk:
        print(f"  {len(shrunk)} baseline entr(y/ies) improved — refresh with --write-baseline to lock it in.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
