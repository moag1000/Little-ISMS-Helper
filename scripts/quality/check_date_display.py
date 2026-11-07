#!/usr/bin/env python3
"""
check_date_display.py — Gate 77: dates are shown through |fa_date / |fa_datetime.

UI wave G: the review found four date formats side by side (31.10.2026 14:05,
2026-10-31, 31.10.2026, 2026-10-31 14:05). Shown dates go through the
filters that apply the admin-configured format and timezone. A hard-coded
`|date('d.m.Y')`, `|date('Y-m-d')` (and the two H:i variants) in text output
is a finding; inside HTML attributes, <script>, path()/url() or string
concatenation it is left alone (machine formats).

Per-file counts of templates not yet swept are baselined and may only shrink.

    python3 scripts/quality/check_date_display.py --baseline scripts/quality/baselines/date_display.txt
    python3 scripts/quality/check_date_display.py --write-baseline scripts/quality/baselines/date_display.txt
    python3 scripts/quality/check_date_display.py --fix templates/foo/show.html.twig
"""
from __future__ import annotations

import argparse
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from date_display import display_dates, fix  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]
TEMPLATES = ROOT / "templates"


def counts() -> dict[str, int]:
    out: dict[str, int] = {}
    for p in sorted(TEMPLATES.rglob("*.html.twig")):
        n = sum(1 for _ in display_dates(p.read_text(encoding="utf-8")))
        if n:
            out[str(p.relative_to(ROOT))] = n
    return out


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--baseline")
    ap.add_argument("--write-baseline")
    ap.add_argument("--fix", nargs="+", metavar="TEMPLATE")
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    if args.fix:
        for name in args.fix:
            p = Path(name)
            text, n = fix(p.read_text(encoding="utf-8"))
            p.write_text(text, encoding="utf-8")
            print(f"check_date_display: {p}: {n} replaced")
        return 0

    current = counts()
    if args.write_baseline:
        Path(args.write_baseline).write_text(
            "# Templates with hard-coded display dates (Gate 77): path<TAB>count. May only shrink.\n"
            + "".join(f"{k}\t{v}\n" for k, v in current.items()),
            encoding="utf-8",
        )
        print(f"check_date_display: wrote {len(current)} entries")
        return 0

    baseline: dict[str, int] = {}
    if args.baseline:
        for line in Path(args.baseline).read_text(encoding="utf-8").splitlines():
            if line.strip() and not line.startswith("#"):
                path, _, n = line.partition("\t")
                baseline[path.strip()] = int(n or 0)

    failed = False
    grown = {k: v for k, v in current.items() if v > baseline.get(k, 0)}
    if grown:
        failed = True
        print("check_date_display: hard-coded display dates — use |fa_date / |fa_datetime"
              " (or run with --fix <template>):")
        for k, v in grown.items():
            print(f"  {k}: {v} (baseline {baseline.get(k, 0)})")
    stale = {k: v for k, v in baseline.items() if current.get(k, 0) < v}
    if stale:
        failed = True
        print("check_date_display: baseline is stale (counts went down) — lower it with --write-baseline:")
        for k, v in stale.items():
            print(f"  {k}: {current.get(k, 0)} (baseline {v})")
    if not failed and not args.quiet:
        print(f"check_date_display: OK ({sum(current.values())} baselined in {len(current)} templates)")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
