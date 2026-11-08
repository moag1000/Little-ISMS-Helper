#!/usr/bin/env python3
"""
check_fulfillment_single_writer.py — Gate 85: one writer of fulfilment values.

`FulfillmentDerivationService` (src/Service/Compliance/Fulfillment/) is the
only writer of a requirement fulfilment's percentage and status: it derives
them from controls, records and audits, keeps hand values as overrides with
reason and review date, and keeps status and percentage consistent. A second
writer (the policy wizard and the certificate import once set the status
themselves) silently disagrees with the rule and is overwritten on the next
recompute, or freezes a status the percentage no longer supports.

The gate scans src/ outside src/Service/Compliance/Fulfillment/ and
src/Entity/ and fails on
  * `->setFulfillmentPercentage(` in a file that mentions
    ComplianceRequirementFulfillment, or on a call chain that mentions a
    fulfilment (`$fulfillment->…`, `getOrCreateFulfillment(…)->…`);
  * `->setStatus(` on a call chain that mentions a fulfilment.
"Mentions a fulfilment" = the receiver chain (variables, properties and method
names, arguments left out) contains `fulfil` (fulfilment / fulfillment).
Other entities with an own `setFulfillmentPercentage()` (PortfolioSnapshot)
stay allowed. A deliberate exception carries `// @fulfillment-writer-ok: <reason>`
on the line. No baseline.

    python3 scripts/quality/check_fulfillment_single_writer.py
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SRC = ROOT / "src"
EXEMPT_DIRS = (SRC / "Service" / "Compliance" / "Fulfillment", SRC / "Entity")
OPT_OUT = "@fulfillment-writer-ok"

CALL = re.compile(r"\??->\s*(setFulfillmentPercentage|setStatus)\s*\(")
IDENT = re.compile(r"[A-Za-z_]\w*$")
CLASS_MENTION = re.compile(r"\bComplianceRequirementFulfillment\b")


def receiver_chain(text: str, pos: int) -> str:
    """The receiver of the call at `pos` (index of `->`), arguments stripped.

    Walks backwards over `->name`, `?->name`, `::name`, `(...)`, `[...]`
    until the chain starts (a `$var`, a class name or anything else).
    """
    parts: list[str] = []
    i = pos
    while i > 0:
        j = i
        while j > 0 and text[j - 1].isspace():
            j -= 1
        if j == 0:
            break
        ch = text[j - 1]
        if ch in ")]":
            opener = "(" if ch == ")" else "["
            depth = 0
            k = j - 1
            while k >= 0:
                if text[k] == ch:
                    depth += 1
                elif text[k] == opener:
                    depth -= 1
                    if depth == 0:
                        break
                k -= 1
            if k < 0:
                break
            i = k
            continue
        m = IDENT.search(text[:j])
        if not m:
            break
        name = m.group(0)
        start = m.start()
        parts.append(name)
        if start > 0 and text[start - 1] == "$":
            break  # chain root: a variable
        k = start
        while k > 0 and text[k - 1].isspace():
            k -= 1
        if text[max(0, k - 3):k] == "?->":
            i = k - 3
        elif text[max(0, k - 2):k] in ("->", "::"):
            i = k - 2
        else:
            break
    return "->".join(reversed(parts))


def scan(path: Path) -> list[str]:
    text = path.read_text(encoding="utf-8", errors="ignore")
    if "setFulfillmentPercentage" not in text and "setStatus" not in text:
        return []
    mentions_class = bool(CLASS_MENTION.search(text))
    lines = text.splitlines()
    errors: list[str] = []
    for m in CALL.finditer(text):
        line_no = text.count("\n", 0, m.start()) + 1
        if OPT_OUT in lines[line_no - 1]:
            continue
        chain = receiver_chain(text, m.start())
        about_fulfilment = "fulfil" in chain.lower()
        method = m.group(1)
        if about_fulfilment or (method == "setFulfillmentPercentage" and mentions_class):
            rel = path.relative_to(ROOT)
            errors.append(f"{rel}:{line_no}: {chain or '?'}->{method}() — "
                          "write fulfilment values through FulfillmentDerivationService")
    return errors


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    errors: list[str] = []
    for path in sorted(SRC.rglob("*.php")):
        if any(path.is_relative_to(d) for d in EXEMPT_DIRS):
            continue
        errors.extend(scan(path))

    if errors:
        print(f"Gate 85: {len(errors)} fulfilment write(s) outside FulfillmentDerivationService:")
        for e in errors:
            print(f"  {e}")
        print("Use apply() / override() / setManual() of "
              "App\\Service\\Compliance\\Fulfillment\\FulfillmentDerivationService.")
        return 1
    if not args.quiet:
        print("Gate 85: fulfilment percentage and status have one writer.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
