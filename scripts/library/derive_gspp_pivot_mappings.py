#!/usr/bin/env python3
"""Derive NIS2 / C5 / DORA / TISAX <-> BSI Grundschutz++ mappings over ISO 27001.

Usage: python3 scripts/library/derive_gspp_pivot_mappings.py

No official crosswalk exists from these frameworks to Grundschutz++. Each one has
a curated mapping to ISO/IEC 27001:2022, and the BSI publishes ISO 27001 -> GS++.
Composing the two gives a candidate X -> GS++ pair whenever both legs exist:

    X --(r1)--> ISO control --(r2)--> GS++ requirement

The pairs are candidates, not facts: provenance `derived_iso27001_pivot` puts them
in the heuristic tier, so inheritance proposes them for review and never applies
them as confirmed. Weak legs are dropped (`related`, missing relationship on both
sides, low confidence); the derived confidence never exceeds `medium`.

Reads fixtures only and is deterministic — rerun after any input mapping changes.
"""

from __future__ import annotations

from collections import OrderedDict
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
MAPPINGS_DIR = ROOT / 'fixtures/library/mappings'

ISO_TO_GSPP = ['iso27001-2022_to_bsi-gspp_official_v1.yaml', 'iso27001-2022-clauses_to_bsi-gspp_v1.yaml']

# slug -> (input file X -> ISO, framework code, display name, default relationship when the file has none)
SOURCES = OrderedDict([
    ('nis2-art21', ('nis2-art21_to_iso27001-2022_v1.0.yaml', 'NIS2', 'NIS2 Art. 21', None)),
    ('bsi-c5-2020', ('bsi-c5-2020_to_iso27001-2022_official-crt_v1.yaml', 'BSI-C5', 'BSI C5:2020', None)),
    ('bsi-c5-2026', ('bsi-c5-2026_to_iso27001-2022_v1.0.yaml', 'BSI-C5-2026', 'BSI C5:2026', None)),
    ('dora', ('dora_to_iso27001-2022_v1.0.yaml', 'DORA', 'DORA', None)),
    # VDA ISA 6 lists its ISO references without a relationship; a reference is an overlap.
    ('tisax', ('tisax_to_iso27001-2022_v1.0.yaml', 'TISAX', 'TISAX (VDA ISA 6)', 'partial_overlap')),
])

USABLE = {'equivalent', 'subset', 'superset', 'partial_overlap'}
INVERSE = {'equivalent': 'equivalent', 'subset': 'superset', 'superset': 'subset', 'partial_overlap': 'partial_overlap'}
STRENGTH = {'equivalent': 3, 'subset': 2, 'superset': 2, 'partial_overlap': 1}


def compose(r1: str, r2: str) -> str:
    """Relationship of X to G given X r1 ISO and ISO r2 G (each source relative to target)."""
    if r1 == r2 and r1 in ('equivalent', 'subset', 'superset'):
        return r1
    if 'equivalent' in (r1, r2):
        other = r2 if r1 == 'equivalent' else r1
        return other
    return 'partial_overlap'


def iso_key(identifier: str) -> str:
    return identifier.removeprefix('ISO27001-')


def targets_of(entry: dict) -> list[str]:
    targets = entry.get('targets')
    if targets is None:
        targets = [entry.get('target')]
    return [str(t) for t in targets if t not in (None, '', 'None')]


def load(name: str) -> dict:
    with (MAPPINGS_DIR / name).open(encoding='utf-8') as fh:
        return yaml.safe_load(fh)


def iso_to_gspp() -> dict[str, list[tuple[str, str]]]:
    legs: dict[str, list[tuple[str, str]]] = {}
    for name in ISO_TO_GSPP:
        for entry in load(name)['mappings']:
            rel = entry.get('relationship')
            if rel not in USABLE:
                continue
            for target in targets_of(entry):
                legs.setdefault(iso_key(str(entry['source'])), []).append((target, rel))
    return legs


def derive(slug: str, legs: dict[str, list[tuple[str, str]]]) -> tuple[OrderedDict, OrderedDict]:
    file_name, code, display, default_rel = SOURCES[slug]
    data = load(file_name)
    pairs: dict[tuple[str, str], dict] = {}
    for entry in data['mappings']:
        rel1 = entry.get('relationship') or default_rel
        if rel1 not in USABLE or entry.get('confidence') == 'low':
            continue
        for iso in targets_of(entry):
            for gspp, rel2 in legs.get(iso_key(iso), []):
                rel = compose(rel1, rel2)
                pair = pairs.setdefault((str(entry['source']), gspp), {'rels': [], 'via': []})
                pair['rels'].append(rel)
                pair['via'].append(iso_key(iso))

    forward, reverse = [], []
    for (source, gspp), pair in sorted(pairs.items()):
        # Several ISO paths: keep the strongest relationship, cite every ISO control used.
        rel = max(pair['rels'], key=lambda r: STRENGTH[r])
        via = ', '.join(sorted(set(pair['via'])))
        rationale = f'Abgeleitet über ISO/IEC 27001:2022 ({via}): {display} {source} → ISO → Grundschutz++ {gspp}.'
        forward.append(OrderedDict([
            ('source', source), ('target', gspp), ('relationship', rel), ('confidence', 'medium'),
            ('rationale', rationale),
        ]))
        reverse.append(OrderedDict([
            ('source', gspp), ('target', source), ('relationship', INVERSE[rel]), ('confidence', 'medium'),
            ('rationale', rationale),
        ]))

    def document(doc_id: str, src: str, tgt: str, mappings: list) -> OrderedDict:
        return OrderedDict([
            ('schema_version', '1.1'),
            ('library', OrderedDict([
                ('type', 'mapping'),
                ('id', doc_id),
                ('source_framework', src),
                ('target_framework', tgt),
                ('version', 1),
                ('effective_from', '2026-10-31'),
                ('effective_until', None),
                ('provenance', OrderedDict([
                    ('primary_source', 'derived_iso27001_pivot'),
                    ('secondary_sources', [file_name, *ISO_TO_GSPP]),
                    ('publisher', 'Little ISMS Helper Maintainers'),
                ])),
                ('methodology', OrderedDict([
                    ('type', 'machine_assisted_with_review'),
                    ('description', (
                        f'Kein amtliches Mapping {display} ↔ Grundschutz++ verfügbar. Paare entstehen aus der '
                        f'Verkettung {display} → ISO/IEC 27001:2022 ({file_name}) und dem amtlichen BSI-Mapping '
                        'ISO 27001 → Grundschutz++. Schwache Glieder (related, low) entfallen; Konfidenz höchstens '
                        'medium. Provenienz derived_iso27001_pivot = heuristisch: Vererbung schlägt diese Paare '
                        'zur Prüfung vor und übernimmt sie nicht ungeprüft.'
                    )),
                ])),
                ('lifecycle', OrderedDict([('state', 'published')])),
            ])),
            ('mappings', mappings),
        ])

    return (
        document(f'{slug}_to_bsi-gspp_derived_v1', code, 'BSI-GSPP', forward),
        document(f'bsi-gspp_to_{slug}_derived_v1', 'BSI-GSPP', code, reverse),
    )


class _Dumper(yaml.SafeDumper):
    pass


_Dumper.add_representer(OrderedDict, lambda d, data: d.represent_mapping('tag:yaml.org,2002:map', data.items()))

HEADER = '# GENERATED by scripts/library/derive_gspp_pivot_mappings.py — do not edit by hand.\n'


def main() -> None:
    legs = iso_to_gspp()
    for slug in SOURCES:
        forward, reverse = derive(slug, legs)
        for doc in (forward, reverse):
            path = MAPPINGS_DIR / f"{doc['library']['id']}.yaml"
            body = yaml.dump(doc, Dumper=_Dumper, allow_unicode=True, sort_keys=False, width=110)
            path.write_text(HEADER + body, encoding='utf-8')
            print(f"{path.name}: {len(doc['mappings'])} pairs")


if __name__ == '__main__':
    main()
