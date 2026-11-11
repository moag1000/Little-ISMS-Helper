#!/usr/bin/env python3
"""
check_kpi_catalog.py — Gate 74: management KPIs are read from the KPI catalog.

UI wave D: `App\\Service\\Kpi\\KpiCatalog` is the one entry point for
management KPIs. It applies the organisation's KPI thresholds (admin →
KPI-Schwellwerte) and adds the euro KPIs. A caller that reads
`DashboardStatisticsService::getManagementKPIs()` directly shows the
hard-coded default traffic lights again, i.e. the threshold setting stops
working on that page. Dashboards pick single KPIs with Twig `kpi('<key>')`
or the `_components/_kpi_tile.html.twig` macro.

    python3 scripts/quality/check_kpi_catalog.py
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
# The catalog itself; ManagementReportService has its own (translated) getManagementKPIs()
# built on the catalog and calls it on $this.
ALLOWED = {"src/Service/Kpi/KpiCatalog.php"}
# Any use of the method on a foreign object, whatever the spelling: `->  getManagementKPIs (`, nullsafe `?->`,
# first-class callable, callable array / Closure::fromCallable (`'getManagementKPIs'` as a string), Twig
# `svc.getManagementKPIs()` or `attribute(svc, 'getManagementKPIs')`. PHP method names are case-insensitive.
# Only `$this->…` (a class calling its own method) is allowed, judged per match, not per line.
# T4: matched on the whole file text — a break or comment between `->` / `?->` and the name (`$x\n  ->\n  getManagementKPIs()`)
# is the same call.
GAP = r"(?:\s|/\*.*?\*/|(?://|\#)[^\n]*\n)*"
CALL = re.compile(
    r"(?P<this>\$this\s*)?(?P<arrow>\?->|->|\.)" + GAP + r"getManagementKPIs\b|['\"]getManagementKPIs['\"]",
    re.I | re.S,
)


def main() -> int:
    quiet = "--quiet" in sys.argv
    hits = []
    files = sorted((ROOT / "src").rglob("*.php")) + sorted((ROOT / "templates").rglob("*.twig"))
    for path in files:
        rel = path.relative_to(ROOT).as_posix()
        if rel in ALLOWED:
            continue
        text = path.read_text(encoding="utf-8")
        lines = text.splitlines()
        for m in CALL.finditer(text):
            if m.group("this") is not None and m.group("arrow") == "->":
                continue  # `$this->getManagementKPIs()`: a class calling its own method
            no = text.count("\n", 0, m.end()) + 1
            hits.append(f"FAIL {rel}:{no}: {lines[no - 1].strip()}")
    if hits:
        print("check_kpi_catalog: VIOLATIONS\n")
        print("\n".join(hits))
        print("\nFix: read KPIs via App\\Service\\Kpi\\KpiCatalog::managementKpis() / get(), or Twig kpi('<key>').")
        return 1
    if not quiet:
        print("check_kpi_catalog: OK")
    return 0


if __name__ == "__main__":
    sys.exit(main())
