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

DATA_CLASS = re.compile(r"""['"]data_class['"]\s*=>\s*(?:\\?App\\Entity\\)?(?:[\w\\]*\\)?(\w+)::class""")
# isModuleActive('x') / isModuleActive("x") / isModuleActive(self::X) / isModuleActive(Modules::X) (T2: a
# constant of another class used to hide the module); `!` in front = module-OFF case.
MODULE_CALL = re.compile(r"""(?P<recv>(?:!\s*)?(?:\$this->(?:\w+->)?)?)isModuleActive\(\s*(?:(['"])(?P<lit>[a-z0-9_]+)\2|(?P<cls>self|static|\\?[A-Z][\w\\]*)::(?P<const>\w+))\s*\)""")


def class_constant(cls: str, name: str, source: str) -> str | None:
    """Value of `cls::name` — `self`/`static` in `source`, another class from its file under src/."""
    texts = [source]
    if cls not in ('self', 'static'):
        short = cls.rsplit('\\', 1)[-1]
        texts = [p.read_text(encoding='utf-8') for p in (ROOT / 'src').rglob(f'{short}.php')]
    for text in texts:
        const = re.search(r"const\s+(?:string\s+)?" + re.escape(name) + r"\s*=\s*['\"]([a-z0-9_]+)['\"]", text)
        if const:
            return const.group(1)
    return None
EARLY_RETURN = re.compile(r"\bif\s*\(\s*!\s*(?:\$this->(?:\w+->)?)?isModuleActive\([^)]*\)\s*\)\s*\{?\s*return\b")
ADD_FIELD = re.compile(r"->add\(\s*'(\w+)'")
HELPER_CALL = re.compile(r"\$this->(\w+)\(\s*\$builder")
PROPERTY = re.compile(r"(?:private|protected|public)\s+(?:readonly\s+)?[?\w\\|]+\s+\$(?P<name>\w+)\s*[=;]")
RESOURCE_MODULE = re.compile(r"^#\[RequiresModule\('([a-z0-9_]+)'\)\]", re.M)
MODULE_FIELD = re.compile(r"""\bModuleField\(\s*['"]([a-z0-9_]+)['"]\s*\)""")
WRITE_GROUP = re.compile(r"""\bGroups\((?:[^()]|\([^()]*\))*?['"][^'"]*:write['"]""")
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


def modules_in(text: str, source: str) -> set[str]:
    """Modules a condition/statement is gated by (not negated); follows `$on = …isModuleActive('x')` variables."""
    found: set[str] = set()
    for m in MODULE_CALL.finditer(text):
        if m.group('recv').lstrip().startswith('!'):
            continue
        module = m.group('lit')
        if module is None:
            module = class_constant(m.group('cls'), m.group('const'), source)
        if module:
            found.add(module)
    if source:
        for var in set(re.findall(r"\$(\w+)", text)):
            for assign in re.finditer(r"\$" + var + r"\s*=(?!=)([^;]*);", source):
                found |= modules_in(assign.group(1), '')
    return found


def gating_modules(source: str, pos: int) -> set[str]:
    """Modules that gate the code at `pos`: enclosing block headers (`if (…isModuleActive…) {`, also via a
    variable or `&&`), an earlier `if (!isModuleActive) { return; }` in the method, a ternary/`&&` in the
    same statement."""
    start = max(source.rfind('function ', 0, pos), 0)
    scope = source[start:pos]
    modules: set[str] = set()
    stack: list[int] = []
    for i, char in enumerate(scope):
        if char == '{':
            stack.append(i)
        elif char == '}' and stack:
            stack.pop()
    for brace in stack:
        boundary = max(scope.rfind(';', 0, brace), scope.rfind('{', 0, brace), scope.rfind('}', 0, brace))
        modules |= modules_in(scope[boundary + 1:brace], source[:start + brace])
    for m in EARLY_RETURN.finditer(scope):
        modules |= {x.group('lit') for x in MODULE_CALL.finditer(m.group(0)) if x.group('lit')}
    boundary = max(scope.rfind(';'), scope.rfind('{'), scope.rfind('}'))
    modules |= modules_in(scope[boundary + 1:], source[:pos])
    return modules


def module_fields_of_form(source: str) -> dict[str, str]:
    """field -> module for every ->add() that only runs while a module is active (block, early return,
    variable, `&&`/ternary), following `$this->addXyzFields($builder)` helpers."""
    fields: dict[str, str] = {}
    collect(source, 0, len(source), None, set(), fields)
    return fields


def collect(source: str, begin: int, end: int, inherited: str | None, seen: set[str], fields: dict[str, str]) -> None:
    for m in re.finditer(ADD_FIELD.pattern + '|' + HELPER_CALL.pattern, source[begin:end]):
        pos = begin + m.start()
        module = next(iter(sorted(gating_modules(source, pos))), None) or inherited
        if m.group(1):
            if module:
                fields.setdefault(m.group(1), module)
            continue
        helper = m.group(2)
        key = f'{helper}@{module}'
        if key in seen:
            continue
        seen.add(key)
        definition = re.search(r'function\s+' + re.escape(helper) + r'\s*\([^)]*\)[^{]*\{', source)
        if definition:
            body_start = definition.end() - 1
            collect(source, body_start, block_end(source, body_start), module, seen, fields)


def entity_properties(entity: str) -> dict[str, str]:
    path = ENTITIES / f'{entity}.php'
    if not path.exists():  # entities in a sub-namespace (src/Entity/Fte/...)
        path = next(iter(sorted(ENTITIES.rglob(f'{entity}.php'))), path)
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
        entity_source = next(iter(sorted(ENTITIES.rglob(f'{data_class.group(1)}.php'))), ENTITIES / f'{data_class.group(1)}.php')
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
