#!/usr/bin/env python3
"""Gate 87: risk bands come from the organisation's risk scale.

Since P8b the band edges (medium/high/critical) belong to the organisation
(ISO 27001 6.1.2 a/b, Risikokriterien) and are read via
App\\Risk\\RiskMatrixThresholds (PHP: classify(), criticalMin()/highMin()/
mediumMin(); Twig: risk_level_band(), risk_bands(); JS: band-*-min values).
A literal ``score >= 20`` / ``>= 12`` / ``>= 6`` next to a risk score keeps
the old 20/12/6 scale alive after an organisation changed it.

The check flags comparisons of a risk-score-like expression with 20, 12 or 6
in src/, templates/ and assets/controllers/. Genuine non-band uses (another
scale that happens to use these numbers) go into the baseline as
``path:line-text`` with a reason comment. The baseline may only shrink.
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BASELINE = ROOT / "scripts/quality/baselines/risk_band_literals.txt"

SCAN = [("src", "*.php"), ("templates", "*.twig"), ("assets/controllers", "*.js")]
SKIP = {"src/Risk/RiskMatrixThresholds.php"}

# A risk-score-ish operand, then a comparison with a literal band edge.
PATTERN = re.compile(
    r"(?i)(risk_?score|riskscore|risklevel|risk_level|inherentrisk|residualrisk|inherent|residual"
    r"|\bscore\b|probability\s*\*\s*\w*\.?impact|\$level\b|\blevel\b)"
    r"[^;\n{}]{0,40}?(>=|<=|>|<)\s*(20|12|6)\b(?![\d.]|\s*%(?!}))"
)


def normalise(line: str) -> str:
    return re.sub(r"\s+", " ", line.strip())


def scan() -> list[tuple[str, int, str]]:
    hits = []
    for base, glob in SCAN:
        for path in sorted((ROOT / base).rglob(glob)):
            rel = path.relative_to(ROOT).as_posix()
            if rel in SKIP:
                continue
            for no, line in enumerate(path.read_text(encoding="utf-8", errors="replace").splitlines(), 1):
                stripped = line.strip()
                if stripped.startswith(("//", "*", "#", "/*", "{#")):
                    continue
                if PATTERN.search(line):
                    hits.append((rel, no, normalise(line)))
    return hits


def load_baseline() -> set[str]:
    if not BASELINE.exists():
        return set()
    entries = set()
    for raw in BASELINE.read_text(encoding="utf-8").splitlines():
        raw = raw.strip()
        if raw and not raw.startswith("#"):
            entries.add(raw)
    return entries


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--write-baseline", action="store_true")
    args = parser.parse_args()

    hits = scan()
    keys = {f"{rel}:{text}" for rel, _, text in hits}

    if args.write_baseline:
        BASELINE.write_text(
            "# Gate 87 — comparisons with 20/12/6 that are not risk bands (another scale).\n"
            "# Format: path:normalised line. May only shrink.\n"
            + "".join(f"{k}\n" for k in sorted(keys)),
            encoding="utf-8",
        )
        print(f"check_risk_band_literals: baseline written ({len(keys)} entries)")
        return 0

    baseline = load_baseline()
    new = [(rel, no, text) for rel, no, text in hits if f"{rel}:{text}" not in baseline]
    stale = sorted(baseline - keys)

    for rel, no, text in new:
        print(f"{rel}:{no}: literal risk band edge — use RiskMatrixThresholds / risk_level_band(): {text}")
    for entry in stale:
        print(f"stale baseline entry (remove it): {entry}")
    if new or stale:
        print(f"check_risk_band_literals: {len(new)} new, {len(stale)} stale")
        return 1
    print(f"check_risk_band_literals: no literal risk band edges ({len(baseline)} baselined non-band uses)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
