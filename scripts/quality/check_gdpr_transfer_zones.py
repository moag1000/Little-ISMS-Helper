#!/usr/bin/env python3
"""
check_gdpr_transfer_zones.py — the GDPR transfer zone list stays current and unique.

`config/gdpr_transfer_zones.yaml` is the one list of EU, EEA, Art. 45 adequacy
and EU-US DPF countries (App\\Privacy\\GdprTransferZones). It drives the
"Drittland" layer of the location map, the supplier chain check and the
DORA register's EEA flag. The gate fails when

  1. `as_of` is missing or older than 12 months (the Commission adopts,
     renews and revokes decisions; check the list and bump the date),
  2. a code is not ISO 3166 alpha-2 or appears in more than one zone, or
  3. a PHP file under src/, a template or a JS controller hard-codes an EU/EEA member list again
     instead of asking GdprTransferZones: five or more EU codes in one list literal, five or
     more EU country NAMES (English or German) in one list literal, or eight or more EU codes spread
     over several short literals of one function (class constants: one unit) / Twig or JS file (T4: a list split in two).

    python3 scripts/quality/check_gdpr_transfer_zones.py [--quiet]
"""
from __future__ import annotations

import datetime as dt
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CONFIG = ROOT / "config/gdpr_transfer_zones.yaml"
ZONES = ("eu", "eea_extra", "adequacy", "dpf")
MAX_AGE_DAYS = 366
EU = {"AT", "BE", "BG", "CY", "CZ", "DE", "DK", "EE", "ES", "FI", "FR", "GR", "HR", "HU", "IE", "IT",
      "LT", "LU", "LV", "MT", "NL", "PL", "PT", "RO", "SE", "SI", "SK"}
ARRAY = re.compile(r"(?:\[|\barray\()[^\[\]()]*[\])]", re.S)
CODE = re.compile(r"['\"]([A-Za-z]{2})['\"]")
KEY = re.compile(r"['\"]([A-Za-z]{2})['\"]\s*=>")
# T4: country names instead of codes, any of the languages the app ships.
EU_NAMES = {n.lower() for n in (
    "Austria Belgium Bulgaria Croatia Cyprus Czechia Czech Republic Denmark Estonia Finland France Germany Greece Hungary "
    "Ireland Italy Latvia Lithuania Luxembourg Malta Netherlands Poland Portugal Romania Slovakia Slovenia Spain Sweden "
    "Österreich Belgien Bulgarien Kroatien Zypern Tschechien Dänemark Estland Finnland Frankreich Deutschland Griechenland "
    "Ungarn Irland Italien Lettland Litauen Luxemburg Niederlande Polen Rumänien Slowakei Slowenien Spanien Schweden"
).replace("Czech Republic", "Czech_Republic").split()} | {"czech republic"}
NAME = re.compile(r"""['"]([A-Za-zÄÖÜäöüß][A-Za-zÄÖÜäöüß .'-]{2,30})['"]""")
SHORT_MIN_CODES = 8  # distinct EU codes over several short literals of one unit
UNIT = re.compile(r"\bfunction\s+\w+")  # PHP: the class header (consts) is one unit, each function another; Twig/JS: the file
CODE_STRING = re.compile(r"['\"]([A-Za-z]{2}(?:\s*[,;|]\s*[A-Za-z]{2}){4,})['\"]")


def parse(text: str) -> tuple[str | None, dict[str, list[str]]]:
    """Minimal reader for the flat file format (key: value / key: [A, B])."""
    as_of, zones = None, {}
    for line in text.splitlines():
        line = line.split("#", 1)[0].rstrip()
        m = re.match(r"^(\w+):\s*(.*)$", line)
        if not m:
            continue
        key, value = m.group(1), m.group(2).strip()
        if key == "as_of":
            as_of = value.strip("'\"")
        elif key in ZONES:
            zones[key] = [c.strip() for c in value.strip("[]").split(",") if c.strip()]
    return as_of, zones


def main() -> int:
    quiet = "--quiet" in sys.argv
    errors: list[str] = []
    as_of, zones = parse(CONFIG.read_text(encoding="utf-8"))

    try:
        age = (dt.date.today() - dt.date.fromisoformat(as_of or "")).days
        if age > MAX_AGE_DAYS:
            errors.append(f"as_of {as_of} is {age} days old: check the Commission's adequacy page and update the list")
    except ValueError:
        errors.append("as_of is missing or not an ISO date (YYYY-MM-DD)")

    seen: dict[str, str] = {}
    for zone in ZONES:
        if zone not in zones:
            errors.append(f"zone '{zone}' is missing")
        for code in zones.get(zone, []):
            if not re.fullmatch(r"[A-Z]{2}", code):
                errors.append(f"{zone}: '{code}' is not an ISO 3166 alpha-2 code")
            elif code in seen:
                errors.append(f"{code} is in both '{seen[code]}' and '{zone}'")
            else:
                seen[code] = zone

    scans = [(p, "php") for p in sorted((ROOT / "src").rglob("*.php"))]
    scans += [(p, "twig") for p in sorted((ROOT / "templates").rglob("*.twig"))]
    scans += [(p, "js") for p in sorted((ROOT / "assets").rglob("*.js")) if "vendor" not in p.parts]
    for path, kind in scans:
        rel = path.relative_to(ROOT).as_posix()
        text = path.read_text(encoding="utf-8", errors="ignore")
        if kind == "twig":
            text = re.sub(r"\{#.*?#\}", lambda c: "\n" * c.group(0).count("\n"), text, flags=re.S)
        hits: list[tuple[int, set[str]]] = []
        short: list[tuple[int, set[str]]] = []
        for m in ARRAY.finditer(text):
            body = m.group(0)
            # Codes as VALUES of an associative array are a choice list (label => code); codes as KEYS
            # (['DE' => true, ...]) are a membership map and count like a plain list.
            codes = KEY.findall(body) if "=>" in body else CODE.findall(body)
            found = {c.upper() for c in codes}
            hits.append((m.start(), found))
            if "=>" not in body and len(found & EU) >= 2:
                short.append((m.start(), found & EU))
            names = {n.lower() for n in NAME.findall(body)} if "=>" not in body else set()
            if len(names & EU_NAMES) >= 5:
                hits.append((m.start(), set(EU)))
        for m in CODE_STRING.finditer(text):  # 'DE,AT,FR,...' / explode(',', ...)
            hits.append((m.start(), {c.upper() for c in re.findall(r"[A-Za-z]{2}", m.group(1))}))
        # One list written as several short literals (`array_merge($west, $east)`, two Twig sets): count per unit.
        starts = [0] + [u.start() for u in UNIT.finditer(text)] * (kind == "php") + [len(text)]
        for a, b in zip(starts, starts[1:]):
            union: set[str] = set()
            for at, codes in short:
                if a <= at < b:
                    union |= codes
            if len(union) >= SHORT_MIN_CODES:
                hits.append((a, set(EU)))
        reported: set[int] = set()
        for start, found in hits:
            if len(found & EU) >= 5:
                line = text.count("\n", 0, start) + 1
                if line not in reported:
                    reported.add(line)
                    errors.append(f"{rel}:{line}: hard-coded EU/EEA country list, use App\\Privacy\\GdprTransferZones")

    if set(zones.get("eu", [])) != EU:
        diff = sorted(set(zones.get("eu", [])) ^ EU)
        errors.append(f"zone 'eu' is not the EU-27 (differs in: {', '.join(diff)})")
    if not {"IS", "LI", "NO"} <= set(zones.get("eea_extra", [])):
        errors.append("zone 'eea_extra' must contain IS, LI and NO")

    if errors:
        print("check_gdpr_transfer_zones: FAIL\n")
        for e in errors:
            print("  " + e)
        return 1
    if not quiet:
        print(f"check_gdpr_transfer_zones: OK (as_of {as_of}, {len(seen)} codes)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
