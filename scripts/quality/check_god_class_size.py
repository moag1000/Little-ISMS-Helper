#!/usr/bin/env python3
"""
check_god_class_size.py — God-class size ratchet (CI Gate 25).

Every PHP file under src/ is measured against the threshold of its kind:

  kind            path                         LOC max   ctor deps max
  data_catalogue  DATA_CATALOGUE_FILES below      2000        —
  controller      src/Controller/                  600        —
  service         src/Service/                    1500        15
  entity          src/Entity/                     1000        —
  repository      src/Repository/                  600        —
  command         src/Command/                     800        —
  other           everything else in src/          800        —

Service thresholds (1500 LOC / 15 deps) and the controller threshold (600 LOC)
are the original 2026-05 values (ADR-0012). Entities get more room because
Doctrine accessors are boilerplate; data catalogues (commands/providers whose
bulk is a static requirements/workflow catalogue, not logic) are listed
explicitly below and get a higher, but still finite, ceiling.

A "constructor dep" is any parameter of `__construct(...)` (commas at depth 0).

Files over threshold must be listed in the baseline
(scripts/quality/baselines/god_class_size.txt, `<path>:LOC=N deps=M`).
The baseline is a one-way ratchet:

  * a NEW file over threshold fails;
  * a baselined file that GROWS (LOC or deps above its entry) fails;
  * --strict-shrink: a baselined file that SHRANK (or dropped under the
    threshold / was deleted) fails until its entry is lowered/removed —
    the baseline must always equal reality, so the gained headroom cannot
    be spent later;
  * --ratchet-ref <git-ref>: the baseline itself may not grow compared to
    the baseline at <git-ref> (CI passes the PR base). Raising an entry or
    adding one fails, so "bump the baseline" is no longer a way around the
    gate. A file may only take over a base entry if
    `git diff --find-renames --find-copies` reports it as a rename/copy of that
    file (entry dropped, not larger); a delete + unrelated new file cannot
    inherit headroom. Without a usable git base the gate fails closed. The ratchet engages once the
    baseline at <git-ref> carries the `# format: 2` header (bootstrap).

Lowering is mechanical: `--write-baseline <baseline>` rewrites the file from
the current tree (the ratchet in CI rejects any entry it would raise).
"""
from __future__ import annotations

import argparse
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
FORMAT_HEADER = "# format: 2"

SERVICE_DEPS_MAX = 15

# (kind, path prefix, LOC max) — first match wins; data catalogues are matched
# by explicit path before this table is consulted.
KIND_THRESHOLDS: list[tuple[str, str, int]] = [
    ("controller", "src/Controller/", 600),
    ("service", "src/Service/", 1500),
    ("entity", "src/Entity/", 1000),
    ("repository", "src/Repository/", 600),
    ("command", "src/Command/", 800),
    ("other", "src/", 800),
]

DATA_CATALOGUE_LOC_MAX = 2000
# Files whose size is dominated by a static catalogue (requirement texts,
# workflow step definitions, category trees). Adding a file here needs the
# same review as any threshold change — it must be data, not logic.
DATA_CATALOGUE_FILES = frozenset({
    "src/Command/LoadIndustryBaselinesCommand.php",
    "src/Command/LoadKritisRequirementsCommand.php",
    "src/Command/LoadC5RequirementsCommand.php",
    "src/Command/LoadDoraRequirementsCommand.php",
    "src/Command/LoadIso27701v2025RequirementsCommand.php",
    "src/Command/LoadIso27701RequirementsCommand.php",
    "src/Command/LoadBsiRequirementsCommand.php",
    "src/Command/SupplementBsiGrundschutzRequirementsCommand.php",
    "src/Command/LoadIktMinstdChFullCommand.php",
    "src/Command/GenerateRegulatoryWorkflowsCommand.php",
    "src/Service/ComplianceWizard/CategoryProvider/EuRegulatoryFrameworkCategoryProvider.php",
})

RE_CTOR = re.compile(r"public\s+function\s+__construct\s*\(")


def count_loc(path: Path) -> int:
    try:
        return len(path.read_text(encoding="utf-8", errors="ignore").splitlines())
    except OSError:
        return 0


def count_ctor_deps(path: Path) -> int:
    try:
        text = path.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        return 0
    m = RE_CTOR.search(text)
    if not m:
        return 0
    start = m.end() - 1  # the '('
    depth = 0
    end = -1
    for i in range(start, len(text)):
        c = text[i]
        if c == "(":
            depth += 1
        elif c == ")":
            depth -= 1
            if depth == 0:
                end = i
                break
    if end < 0:
        return 0
    body = text[start + 1:end]
    if not body.strip():
        return 0
    depth = 0
    deps = 1
    for c in body:
        if c in "([{":
            depth += 1
        elif c in ")]}":
            depth -= 1
        elif c == "," and depth == 0:
            deps += 1
    if body.rstrip().endswith(","):
        deps -= 1  # trailing comma after the last parameter is not a dependency
    return deps


def kind_of(rel: str) -> tuple[str, int, int | None]:
    """Return (kind, loc_max, deps_max) for a repo-relative path."""
    if rel in DATA_CATALOGUE_FILES:
        return "data_catalogue", DATA_CATALOGUE_LOC_MAX, None
    for kind, prefix, loc_max in KIND_THRESHOLDS:
        if rel.startswith(prefix):
            return kind, loc_max, SERVICE_DEPS_MAX if kind == "service" else None
    return "other", KIND_THRESHOLDS[-1][2], None


def scan() -> dict[str, tuple[int, int, str]]:
    """{rel: (loc, deps, kind)} for every file over its threshold."""
    out: dict[str, tuple[int, int, str]] = {}
    for f in sorted((ROOT / "src").rglob("*.php")):
        rel = f.relative_to(ROOT).as_posix()
        kind, loc_max, deps_max = kind_of(rel)
        loc = count_loc(f)
        # Constructor deps are a metric for services only (deps=0 elsewhere).
        deps = count_ctor_deps(f) if deps_max is not None else 0
        if loc > loc_max or (deps_max is not None and deps > deps_max):
            out[rel] = (loc, deps, kind)
    return out


def parse_baseline(text: str) -> tuple[dict[str, tuple[int, int]], bool]:
    """Parse `<rel>:LOC=N deps=M` lines. Returns (entries, has_format_header)."""
    out: dict[str, tuple[int, int]] = {}
    has_header = False
    for raw in text.splitlines():
        s = raw.strip()
        if s == FORMAT_HEADER:
            has_header = True
            continue
        if not s or s.startswith("#"):
            continue
        rel, _, rest = s.partition(":")
        if not rest:
            continue
        m_loc = re.search(r"LOC=(\d+)", rest)
        m_dep = re.search(r"deps=(\d+)", rest)
        out[rel] = (int(m_loc.group(1)) if m_loc else 0, int(m_dep.group(1)) if m_dep else 0)
    return out, has_header


def baseline_at_ref(ref: str, baseline: Path) -> str:
    rel = baseline.resolve().relative_to(ROOT).as_posix()
    res = subprocess.run(
        ["git", "-C", str(ROOT), "show", f"{ref}:{rel}"],
        capture_output=True, text=True, check=False,
    )
    if res.returncode != 0:
        raise RuntimeError(res.stderr.strip() or f"git show {ref}:{rel} failed")
    return res.stdout


def git_renames(ref: str) -> dict[str, str]:
    """{new_path: old_path} for renames/copies between <ref> and the working HEAD.

    Two-dot (tree vs. tree) on purpose: works with the shallow `--depth=1`
    fetch CI uses, where no merge base is available. Raises RuntimeError.
    """
    res = subprocess.run(
        ["git", "-C", str(ROOT), "diff", "--find-renames", "--find-copies",
         "--name-status", ref, "HEAD", "--", "src"],
        capture_output=True, text=True, check=False,
    )
    if res.returncode != 0:
        raise RuntimeError(res.stderr.strip() or f"git diff {ref} HEAD failed")
    return parse_renames(res.stdout)


def parse_renames(name_status: str) -> dict[str, str]:
    out: dict[str, str] = {}
    for line in name_status.splitlines():
        parts = line.split("\t")
        if len(parts) == 3 and parts[0][:1] in ("R", "C"):
            out[parts[2]] = parts[1]
    return out


def ratchet_violations(
    current: dict[str, tuple[int, int]],
    ref: dict[str, tuple[int, int]],
    renames: dict[str, str],
) -> list[str]:
    """A new baseline entry is only allowed if git reports its file as a
    rename/copy of a base entry whose own entry is dropped, and it is not larger.
    A delete + unrelated new file is NOT a rename and cannot inherit headroom."""
    msgs: list[str] = []
    consumed: set[str] = set()
    for rel, (loc, deps) in sorted(current.items()):
        if rel in ref:
            r_loc, r_deps = ref[rel]
            if loc > r_loc or deps > r_deps:
                msgs.append(f"FAIL {rel}: baseline RAISED to LOC={loc} deps={deps} (base: LOC={r_loc} deps={r_deps})")
            continue
        old = renames.get(rel)
        if old is not None and old in ref and old not in current and old not in consumed:
            o_loc, o_deps = ref[old]
            if loc <= o_loc and deps <= o_deps:
                consumed.add(old)
                continue
            msgs.append(f"FAIL {rel}: renamed from {old} but larger (LOC={loc} deps={deps} vs "
                        f"LOC={o_loc} deps={o_deps}) — baseline entry may not grow")
            continue
        msgs.append(f"FAIL {rel}: baseline entry ADDED (LOC={loc} deps={deps}) — not in the base baseline "
                    "and git reports no rename/copy of a baselined file. A new over-threshold file "
                    "cannot inherit another file's headroom: split it up, or update the baseline "
                    "explicitly in a reviewed change")
    return msgs


def write_baseline(path: Path, offenders: dict[str, tuple[int, int, str]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8") as fh:
        fh.write("# check_god_class_size.py baseline (Gate 25) — a one-way ratchet.\n")
        fh.write(FORMAT_HEADER + "\n")
        fh.write("# Format: <relative-path>:LOC=NNN deps=MMM\n")
        fh.write("# Entries may only be lowered or removed; CI rejects new or raised entries\n")
        fh.write("# (--ratchet-ref) and stale ones (--strict-shrink). See scripts/README.md.\n")
        for rel, (loc, deps, _kind) in sorted(offenders.items(), key=lambda kv: (-kv[1][0], kv[0])):
            fh.write(f"{rel}:LOC={loc} deps={deps}\n")


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--baseline", type=Path, default=None)
    ap.add_argument("--write-baseline", type=Path, default=None)
    ap.add_argument("--strict-shrink", action="store_true",
                    help="fail when a baselined file shrank / is no longer over threshold (stale entry)")
    ap.add_argument("--ratchet-ref", default=None,
                    help="git ref whose baseline the current baseline may not exceed (e.g. origin/main)")
    ap.add_argument("--ratchet-base-file", type=Path, default=None,
                    help="like --ratchet-ref, but read the base baseline from a file")
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    offenders = scan()

    if args.write_baseline is not None:
        write_baseline(args.write_baseline, offenders)
        print(f"check_god_class_size: wrote {len(offenders)} entries to {args.write_baseline}")
        return 0

    baseline: dict[str, tuple[int, int]] = {}
    if args.baseline is not None and args.baseline.exists():
        baseline, _ = parse_baseline(args.baseline.read_text(encoding="utf-8"))

    fails: list[str] = []
    stale: list[str] = []
    for rel, (loc, deps, kind) in sorted(offenders.items()):
        _k, loc_max, deps_max = kind_of(rel)
        limit = f"{kind} max LOC={loc_max}" + (f" deps={deps_max}" if deps_max is not None else "")
        if rel not in baseline:
            fails.append(f"FAIL {rel}:1: NEW god-class LOC={loc} deps={deps} ({limit})")
            continue
        b_loc, b_deps = baseline[rel]
        if loc > b_loc or deps > b_deps:
            fails.append(f"FAIL {rel}:1: GREW to LOC={loc} deps={deps} (baseline LOC={b_loc} deps={b_deps})")
        elif loc < b_loc or deps < b_deps:
            stale.append(f"{rel}: now LOC={loc} deps={deps}, baseline LOC={b_loc} deps={b_deps}")
    for rel in sorted(set(baseline) - set(offenders)):
        stale.append(f"{rel}: no longer over threshold (or removed) — drop the entry")

    if args.ratchet_ref or args.ratchet_base_file:
        if args.baseline is None:
            print("check_god_class_size: the ratchet needs --baseline")
            return 1
        source = args.ratchet_ref or str(args.ratchet_base_file)
        try:
            if args.ratchet_base_file is not None:
                ref_text = args.ratchet_base_file.read_text(encoding="utf-8")
            else:
                ref_text = baseline_at_ref(args.ratchet_ref, args.baseline)
        except (OSError, RuntimeError, ValueError) as exc:
            print(f"check_god_class_size: cannot read the base baseline {source}: {exc}")
            return 1
        ref_entries, ref_has_header = parse_baseline(ref_text)
        if ref_has_header:
            if not args.ratchet_ref:
                renames: dict[str, str] = {}  # --ratchet-base-file has no git base: no takeover
            else:
                try:
                    renames = git_renames(args.ratchet_ref)
                except RuntimeError as exc:
                    print(f"check_god_class_size: cannot determine renames against {source}: {exc}")
                    return 1
            fails.extend(ratchet_violations(baseline, ref_entries, renames))
        else:
            print(f"check_god_class_size: base baseline {source} predates '{FORMAT_HEADER}' "
                  "— ratchet bootstrap, comparison skipped.")

    if stale:
        label = "FAIL" if args.strict_shrink else "NOTE"
        print(f"check_god_class_size: {len(stale)} stale baseline entr{'y' if len(stale) == 1 else 'ies'} "
              "(the file shrank — lower the baseline to lock in the gain):")
        for s in stale:
            print(f"  {label} {s}")
        target = args.baseline or "scripts/quality/baselines/god_class_size.txt"
        print(f"  Run: python3 scripts/quality/check_god_class_size.py --write-baseline {target}\n")

    if fails:
        print("check_god_class_size: VIOLATIONS\n")
        for f in fails:
            print(f)
        print("\nFix: extract cohesive services/traits until the file is back within its baseline/threshold.")
        print("Raising or adding a baseline entry is rejected by the ratchet — see scripts/README.md (Gate 25).")
        return 1
    if stale and args.strict_shrink:
        return 1

    print(f"check_god_class_size: OK — {len(offenders)} file(s) over threshold, all within baseline.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
