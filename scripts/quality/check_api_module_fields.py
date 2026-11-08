#!/usr/bin/env python3
"""Gate: API-writable fields of an optional module carry #[ModuleField].

The web form adds a module's fields only while the module is active
(`if ($this->isModuleActive('nis2_dora')) { $builder->add(...) }`). REST and
the assistant (MCP) write the same entity through serialisation groups, which
know nothing of modules. `App\\Api\\ModuleFieldContextBuilder` ignores a field
in a request body while its module is off — but only when the entity property
says which module owns it: `#[ModuleField('nis2_dora')]`.

This gate keeps the two sides in step:
  1. every field a FormType adds inside an `isModuleActive('<m>')` block and
     that sits in a `:write` group of its entity carries `ModuleField('<m>')` (own `#[...]` or grouped with `Groups`);
  2. every `#[ModuleField('<m>')]` names a module from config/modules.yaml.

Fields with no form at all (machine-fed DORA/FAIR columns on Risk) are
annotated by hand; rule 2 still checks them.

Traits count (S6, 2026-11): properties an entity takes from `use XyzTrait;`
(src/Entity/Trait/) and field helpers a FormType takes from a trait
(src/Form/Trait/) are read as part of the class.

Usage: python3 scripts/quality/check_api_module_fields.py [--quiet]
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
FORMS = ROOT / 'src' / 'Form'
ENTITIES = ROOT / 'src' / 'Entity'

DATA_CLASS = re.compile(r"'data_class'\s*=>\s*(?:\\?App\\Entity\\)?(\w+)::class")
IF_MODULE = re.compile(r"\bif\s*\((?P<cond>[^{]*?isModuleActive\('(?P<module>[a-z0-9_]+)'\)[^{]*)\)\s*\{")
ADD_FIELD = re.compile(r"->add\(\s*'(\w+)'")
HELPER_CALL = re.compile(r"\$this->(\w+)\(\s*\$builder")
PROPERTY = re.compile(r"(?:private|protected|public)\s+(?:readonly\s+)?[?\w\\|]+\s+\$(?P<name>\w+)\s*[=;]")
RESOURCE_MODULE = re.compile(r"^#\[RequiresModule\('([a-z0-9_]+)'\)\]", re.M)
MODULE_FIELD = re.compile(r"[\[,]\s*ModuleField\('([a-z0-9_]+)'\)")
WRITE_GROUP = re.compile(r"#\[Groups\(\[[^\]]*:write'")
TRAIT_USE = re.compile(r"^    use (\w+(?:\s*,\s*\w+)*);", re.M)


def with_traits(source: str, trait_dir: Path) -> str:
    """The class source followed by the source of every trait it uses (recursively)."""
    seen: set[str] = set()
    parts = [source]
    pending = [name.strip() for m in TRAIT_USE.finditer(source) for name in m.group(1).split(',')]
    while pending:
        name = pending.pop()
        path = trait_dir / f'{name}.php'
        if name in seen or not path.exists():
            continue
        seen.add(name)
        trait = path.read_text(encoding='utf-8')
        parts.append(trait)
        pending += [n.strip() for m in TRAIT_USE.finditer(trait) for n in m.group(1).split(',')]
    return '\n'.join(parts)


def module_keys() -> set[str]:
    keys: set[str] = set()
    in_modules = False
    for line in (ROOT / 'config' / 'modules.yaml').read_text(encoding='utf-8').splitlines():
        if line.startswith('modules:'):
            in_modules = True
            continue
        if in_modules:
            match = re.match(r'^  ([a-z0-9_]+):\s*$', line)
            if match:
                keys.add(match.group(1))
            elif line and not line.startswith(' ') and not line.startswith('#'):
                in_modules = False
    return keys


def block_end(source: str, open_brace: int) -> int:
    depth = 0
    for index in range(open_brace, len(source)):
        char = source[index]
        if char == '{':
            depth += 1
        elif char == '}':
            depth -= 1
            if depth == 0:
                return index
    return len(source)


def module_fields_of_form(source: str) -> dict[str, str]:
    """field -> module for every ->add() inside an isModuleActive() block."""
    fields: dict[str, str] = {}
    for match in IF_MODULE.finditer(source):
        if '!' in match.group('cond').split('isModuleActive')[0][-3:]:
            continue  # `!$this->isModuleActive(...)`: fields for the module-OFF case
        start = match.end() - 1
        for field in fields_in(source, source[start:block_end(source, start)], set()):
            fields.setdefault(field, match.group('module'))
    return fields


def fields_in(source: str, body: str, seen: set[str]) -> list[str]:
    """->add() fields of a block, following `$this->addXyzFields($builder)` helpers."""
    found = ADD_FIELD.findall(body)
    for helper in HELPER_CALL.findall(body):
        if helper in seen:
            continue
        seen.add(helper)
        definition = re.search(r'function\s+' + re.escape(helper) + r'\s*\([^)]*\)[^{]*\{', source)
        if definition:
            start = definition.end() - 1
            found += fields_in(source, source[start:block_end(source, start)], seen)
    return found


def entity_properties(entity: str) -> dict[str, str]:
    path = ENTITIES / f'{entity}.php'
    if not path.exists():
        return {}
    source = with_traits(path.read_text(encoding='utf-8'), ENTITIES / 'Trait')
    properties: dict[str, str] = {}
    for match in PROPERTY.finditer(source):
        # attributes = everything since the previous statement or block end
        before = source[:match.start()]
        start = max(before.rfind(';'), before.rfind('{\n'), before.rfind('}\n'))
        properties[match.group('name')] = before[start + 1:]
    return properties


def main() -> int:
    quiet = '--quiet' in sys.argv
    errors: list[str] = []
    modules = module_keys()

    for form in sorted(FORMS.rglob('*Type.php')):
        source = with_traits(form.read_text(encoding='utf-8'), FORMS / 'Trait')
        data_class = DATA_CLASS.search(source)
        if not data_class:
            continue
        properties = entity_properties(data_class.group(1))
        entity_source = (ENTITIES / f'{data_class.group(1)}.php')
        class_modules = set(RESOURCE_MODULE.findall(entity_source.read_text(encoding='utf-8'))) if entity_source.exists() else set()
        for field, module in module_fields_of_form(source).items():
            if module in class_modules:
                continue  # the whole resource is gated by that module already
            attrs = properties.get(field)
            if attrs is None or not WRITE_GROUP.search(attrs):
                continue
            declared = MODULE_FIELD.findall(attrs)
            if module not in declared:
                errors.append(
                    f"src/Entity/{data_class.group(1)}.php: ${field} is API-writable and added by "
                    f"{form.relative_to(ROOT)} only while '{module}' is active — add #[ModuleField('{module}')]"
                )

    for entity in sorted(ENTITIES.rglob('*.php')):
        for line_no, line in enumerate(entity.read_text(encoding='utf-8').splitlines(), 1):
            for module in MODULE_FIELD.findall(line):
                if module not in modules:
                    errors.append(f"{entity.relative_to(ROOT)}:{line_no}: unknown module '{module}' in #[ModuleField]")

    if errors:
        print(f'API module fields: {len(errors)} problem(s)')
        for error in errors:
            print('  ' + error)
        return 1
    if not quiet:
        print('API module fields: OK')
    return 0


if __name__ == '__main__':
    sys.exit(main())
