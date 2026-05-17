#!/usr/bin/env python3
"""
check_raw_json_textarea.py — Gate 34.

Detects FormTypes that expose a JSON-typed array-shaped entity property
(`#[ORM\\Column(type: Types::JSON)]` + `private ?array $foo`) via raw
`TextareaType`. Users typing JSON into a textarea get zero schema
validation, zero IDE support, and zero UX affordance for the actual
shape (list of tags, list of objects, IF/THEN rules, …).

Correct alternatives:
  - list-of-strings (tags)  → App\\Form\\Type\\JsonTagsType
  - list-of-objects         → Symfony CollectionType + EntryType
  - IF/THEN rules           → CollectionType + ConditionBuilderType
                              (notification-rule pattern)
  - generic k/v config      → schema-aware per-key admin UI

This gate is FormType-data_class-aware: it follows each FormType's
`data_class => Entity::class` and only flags fields whose backing entity
property is JSON-column-typed AND has a PHP `array` (or `?array`) type.
`?string`-on-JSON-column is a different data-modeling smell and not in
this gate's scope.

Exit 0 = clean / all baselined, Exit 1 = new violations.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ENT_DIR = ROOT / "src" / "Entity"
FORM_DIR = ROOT / "src" / "Form"

RE_DATA_CLASS = re.compile(r"data_class['\"]\s*[=:]>\s*([A-Za-z][\w]*)::class")
RE_FORM_TEXTAREA_ADD = re.compile(
    r"->add\(\s*'(?P<name>\w+)'\s*,\s*TextareaType::class",
)


def _entity_json_array_props(entity_text: str) -> set[str]:
    props: set[str] = set()
    lines = entity_text.splitlines()
    re_json_col = re.compile(r"#\[\s*ORM\\Column\([^)]*type\s*[:=]\s*(?:Types::JSON|['\"]json['\"])")
    re_attr = re.compile(r"^\s*#\[")
    re_comment = re.compile(r"^\s*(?://|/\*|\*)")
    re_prop = re.compile(
        r"^\s*(?:private|protected|public)\s+(?:readonly\s+)?(?P<type>[^\s$]+)\s+\$(?P<name>\w+)"
    )
    i = 0
    while i < len(lines):
        if re_json_col.search(lines[i]):
            j = i + 1
            while j < len(lines):
                line = lines[j]
                if not line.strip() or re_attr.match(line) or re_comment.match(line):
                    j += 1
                    continue
                mp = re_prop.match(line)
                if mp and mp.group("type").lstrip("?") == "array":
                    props.add(mp.group("name"))
                break
        i += 1
    return props


def scan() -> list[tuple[Path, int, str]]:
    findings: list[tuple[Path, int, str]] = []
    # Cache: entity-stem -> set of JSON-array property names
    entity_cache: dict[str, set[str]] = {}
    for ent in ENT_DIR.rglob("*.php"):
        entity_cache[ent.stem] = _entity_json_array_props(
            ent.read_text(encoding="utf-8", errors="ignore")
        )

    for ft in FORM_DIR.rglob("*Type.php"):
        text = ft.read_text(encoding="utf-8", errors="ignore")
        m_dc = RE_DATA_CLASS.search(text)
        if m_dc is None:
            continue
        entity_stem = m_dc.group(1)
        props = entity_cache.get(entity_stem, set())
        if not props:
            continue
        for m in RE_FORM_TEXTAREA_ADD.finditer(text):
            name = m.group("name")
            if name in props:
                ln = text.count("\n", 0, m.start()) + 1
                findings.append((ft, ln, name))
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
    keys = [f"{_rel(p)}:{ln}:{name}" for p, ln, name in findings]

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_raw_json_textarea.py baseline\n")
            fh.write("# Format: <FormType-path>:<line>:<property>\n")
            for k in keys:
                fh.write(k + "\n")
        print(f"check_raw_json_textarea: wrote {len(keys)} entries")
        return 0

    baseline = load_baseline(args.baseline)
    new = [(p, ln, n) for (p, ln, n), k in zip(findings, keys) if k not in baseline]
    total = len(findings)
    baselined = total - len(new)

    if not new:
        if not args.quiet:
            print(f"check_raw_json_textarea: OK — {total} legacy, {baselined} baselined.")
        else:
            print(f"check_raw_json_textarea: OK ({total}, all baselined)")
        return 0

    print("check_raw_json_textarea: VIOLATIONS\n")
    for p, ln, name in new:
        print(f"FAIL {_rel(p)}:{ln}: TextareaType bound to JSON+array column `{name}`")
    print(f"\ncheck_raw_json_textarea: {len(new)} new ({baselined} baselined, {total} total).")
    print("Fix paths:")
    print("  - list-of-strings → App\\Form\\Type\\JsonTagsType (chips via tom-select)")
    print("  - list-of-objects → Symfony CollectionType + custom EntryType")
    print("  - IF/THEN rules   → CollectionType + ConditionBuilderType (notification-rule pattern)")
    return 1


if __name__ == "__main__":
    sys.exit(main())
