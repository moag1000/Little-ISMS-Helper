#!/usr/bin/env python3
"""
check_raw_json_textarea.py — Gate 34.

Users never edit JSON by hand. The gate flags every way a FormType can
still expose raw JSON:

  textarea     JSON+array entity column (`#[ORM\\Column(type: Types::JSON)]`
               + `private ?array $foo`) added as raw `TextareaType`
               (data_class-aware, see below).
  no-theme     `JsonStructuredType` field without a custom
               `_<block_prefix>_<field>_row|widget` theme block — the type is
               only a transport, the builder lives in that block.
  transformer  `new JsonArrayTransformer()` wired onto a field in a FormType
               (a TextareaType/TextType turned into a JSON editor).
  constraint   Symfony `Json` constraint in a FormType (validates hand-typed
               JSON — the field itself is the problem).
  placeholder  JSON-looking placeholder (`'{"…`, `'[{…`) — an example for
               users to type JSON.

Correct alternatives:
  - list-of-strings (tags)        → App\\Form\\Type\\JsonTagsType
  - list / map / object of fields → App\\Form\\Type\\JsonBuilderType (generic,
                                    schema-driven, server-side shape check)
  - IF/THEN rules                 → CollectionType of ConditionRowType rendered
                                    by _fa_condition_builder (notification-rule pattern)

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
RE_STRUCTURED_ADD = re.compile(r"->add\(\s*'(?P<name>\w+)'\s*,\s*JsonStructuredType::class")
RE_TRANSFORMER = re.compile(r"new\s+(?:\\?App\\Form\\DataTransformer\\)?JsonArrayTransformer\s*\(")
RE_JSON_CONSTRAINT = re.compile(r"new\s+(?:\\?Symfony\\Component\\Validator\\Constraints\\|Assert\\)?Json\s*\(")
RE_JSON_PLACEHOLDER = re.compile(r"""['"]placeholder['"]\s*=>\s*(?:'\s*(?:\{\s*"|\[\s*[{"])|"\s*(?:\{\s*\\?"|\[\s*[{\\]))""")
RE_BLOCK_PREFIX = re.compile(r"function\s+getBlockPrefix\(\)\s*:\s*string\s*\{\s*return\s*'(?P<p>\w+)'")
RE_PHP_COMMENT = re.compile(r"^\s*(?://|\*|/\*|#(?!\[))")


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


def _block_prefix(ft: Path, text: str) -> str:
    """Symfony's default block prefix: FQCN short name minus `Type`, snake_case."""
    m = RE_BLOCK_PREFIX.search(text)
    if m:
        return m.group("p")
    name = ft.stem[:-4] if ft.stem.endswith("Type") else ft.stem
    return re.sub(r"(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])", "_", name).lower()


def _theme_blocks() -> str:
    return "\n".join(
        t.read_text(encoding="utf-8", errors="ignore") for t in (ROOT / "templates").rglob("*.twig")
    )


def _line(text: str, pos: int) -> int:
    return text.count("\n", 0, pos) + 1


def _is_comment(text: str, pos: int) -> bool:
    line_start = text.rfind("\n", 0, pos) + 1
    return bool(RE_PHP_COMMENT.match(text[line_start:pos]))


def scan() -> list[tuple[Path, int, str]]:
    """Return (FormType, line, finding-key); the key is `<kind>` or `<property>`."""
    findings: list[tuple[Path, int, str]] = []
    # Cache: entity-stem -> set of JSON-array property names
    entity_cache: dict[str, set[str]] = {}
    for ent in ENT_DIR.rglob("*.php"):
        entity_cache[ent.stem] = _entity_json_array_props(
            ent.read_text(encoding="utf-8", errors="ignore")
        )
    themes = _theme_blocks()

    for ft in sorted(FORM_DIR.rglob("*Type.php")):
        text = ft.read_text(encoding="utf-8", errors="ignore")
        is_transport_type = ft.parent.name == "Type"  # the JSON transport types themselves

        m_dc = RE_DATA_CLASS.search(text)
        props = entity_cache.get(m_dc.group(1), set()) if m_dc else set()
        for m in RE_FORM_TEXTAREA_ADD.finditer(text):
            if m.group("name") in props:
                findings.append((ft, _line(text, m.start()), m.group("name")))

        prefix = _block_prefix(ft, text)
        for m in RE_STRUCTURED_ADD.finditer(text):
            name = m.group("name")
            if _is_comment(text, m.start()):
                continue
            if not re.search(r"block\s+_%s_%s_(?:row|widget)\b" % (re.escape(prefix), re.escape(name)), themes):
                findings.append((ft, _line(text, m.start()), f"no-theme:{name}"))

        if is_transport_type:
            continue
        for kind, rx in (("transformer", RE_TRANSFORMER), ("constraint", RE_JSON_CONSTRAINT),
                         ("placeholder", RE_JSON_PLACEHOLDER)):
            for m in rx.finditer(text):
                if not _is_comment(text, m.start()):
                    findings.append((ft, _line(text, m.start()), kind))
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
    messages = {
        "transformer": "JsonArrayTransformer wired onto a form field (raw JSON editor)",
        "constraint": "Json constraint validates hand-typed JSON",
        "placeholder": "JSON example as placeholder (users would type JSON)",
    }
    for p, ln, name in new:
        if name.startswith("no-theme:"):
            msg = f"JsonStructuredType field `{name[9:]}` has no `_<form>_{name[9:]}_row` builder theme block"
        else:
            msg = messages.get(name, f"TextareaType bound to JSON+array column `{name}`")
        print(f"FAIL {_rel(p)}:{ln}: {msg}")
    print(f"\ncheck_raw_json_textarea: {len(new)} new ({baselined} baselined, {total} total).")
    print("Fix paths:")
    print("  - list-of-strings → App\\Form\\Type\\JsonTagsType (chips via tom-select)")
    print("  - list/map/object → App\\Form\\Type\\JsonBuilderType (generic builder + JsonShape)")
    print("  - IF/THEN rules   → CollectionType of ConditionRowType + _fa_condition_builder (notification rules)")
    return 1


if __name__ == "__main__":
    sys.exit(main())
