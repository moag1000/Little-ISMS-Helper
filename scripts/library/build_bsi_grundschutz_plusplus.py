#!/usr/bin/env python3
"""Build the BSI Grundschutz++ (BSI-Standard 300-1) fixtures from the official
BSI "Stand-der-Technik-Bibliothek" (OSCAL, CC BY-SA 4.0).

Usage:
    git clone https://github.com/BSI-Bund/Stand-der-Technik-Bibliothek /tmp/sdt
    python3 scripts/library/build_bsi_grundschutz_plusplus.py /tmp/sdt

Writes (overwrites):
    fixtures/library/catalogues/bsi-grundschutz-plusplus/catalogue.json
    fixtures/library/mappings/iso27001-2022_to_bsi-gspp_official_v1.yaml
    fixtures/library/mappings/bsi-gspp_to_iso27001-2022_v1.yaml
    fixtures/library/mappings/bsi-it-grundschutz_to_bsi-gspp_official_v1.yaml
    fixtures/library/mappings/iso27001-2022-clauses_to_bsi-gspp_v1.yaml
    fixtures/library/mappings/bsi-gspp_to_iso27001-2022-clauses_v1.yaml

Re-run it whenever the BSI publishes a new Anwenderkatalog. The loader
`app:load-bsi-grundschutz-plusplus --update` then refreshes existing rows.

Why the ID resolution below exists: both official mapping collections were
authored against an older catalogue build (May/June 2026). The BSI renumbered
several requirements since then. Every catalogue control carries a stable
`alt-identifier` UUID, so the IT-GS mapping (which ships the UUID per target)
resolves exactly. The ISO mapping ships IDs only; its stale IDs were resolved
once via the UUID history of the BSI repository and are pinned in
ISO_ID_RENAMES. Targets whose UUID no longer exists in the catalogue were
withdrawn by the BSI and are dropped.
"""

from __future__ import annotations

import csv
import json
import re
import subprocess
import sys
from collections import OrderedDict, defaultdict
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
CATALOGUE_OUT = ROOT / 'fixtures/library/catalogues/bsi-grundschutz-plusplus/catalogue.json'
MAPPINGS_DIR = ROOT / 'fixtures/library/mappings'

FRAMEWORK_CODE = 'BSI-GSPP'
REPO_URL = 'https://github.com/BSI-Bund/Stand-der-Technik-Bibliothek'
CATALOGUE_PATH = 'control_layer/Grundschutz++/Grundschutz++-resolved_catalog.json'
ISO_MAPPING_PATH = 'control_layer/Mappings/ISO-27001-zu-GSpp/ISO27001-AnnexA-to-GS++-mapping_collection.json'
ITGS_MAPPING_PATH = 'control_layer/Mappings/IT-GS2023-zu-GSpp/ITGS-to-GS++-mapping_collection.json'
TARGET_OBJECTS_PATH = 'documentation/namespaces/target_object_categories.csv'

# Root target-object type (column "Typ" of the BSI namespace) -> Asset.assetType.
# Lets a requirement name the inventoried assets it applies to (data reuse via
# ComplianceMappingService::getDataReuseAnalysis(), dataSourceMapping.asset_types).
ASSET_TYPE_BY_ROOT = {
    'IT-Systeme': 'Hardware',
    'Netze': 'Hardware',
    'Anwendungen': 'Software',
    'Informationen': 'Information',
    'Einkäufe': 'Service',
    'Nutzende': 'Personnel',
    'Mitarbeitende': 'Personnel',
    'Standorte': 'Physical',
}

# Practice -> existing ISMS data that evidences it (dataSourceMapping flags read by
# ComplianceMappingService::getDataReuseAnalysis()).
REUSE_FLAGS_BY_PRACTICE = {
    'DET': {'incident_management': True},
    'REA': {'incident_management': True},
    'NOT': {'bcm_required': True},
    'PERF': {'audit_evidence': True},
}

# Stale target IDs of the ISO mapping (v1.0.1-qa, 2026-07-27) -> current ID,
# resolved via the alt-identifier UUID history of the BSI repository.
# None = requirement withdrawn by the BSI.
ISO_ID_RENAMES: dict[str, str | None] = {
    'GC.6.1.2': 'GC.5.1.2',
    'GC.6.1.3': 'GC.5.1.3',
    'GC.6.1.4': 'GC.5.1.4',
    'GC.7.1.3': 'GC.3.1.3',
    'GC.8.1.1': 'GC.9.1.1',
    'GC.8.1.1.1': 'GC.9.1.1.1',
    'GC.8.1.3': 'GC.9.1.1.3',
    'GC.8.1.4': 'GC.9.1',
    'GC.9.1.2': 'GC.10.1.2',
    'GC.9.1.3': 'GC.11.1',
    'GC.10.2': 'GC.1.2',
    'PERF.5.1.4': 'PERF.4.1.4',
    'PERF.7.1': 'PERF.1.3',
    'PERF.1.1.1': None,
    'PERF.6.1.3': None,
    'BER.5.1.2': 'BER.4.1.2',
    'BER.6.8': 'BER.6.4',
    'DET.3.1.13': 'DET.3.2',
    'GEB.5.4': 'GEB.5.2',
}

CLASS_KIND = {
    'BSI-Methodik-Grundschutz-plus-plus': 'methodik',
    'BSI-Anforderungen-zum-Risikomanagement': 'risikomanagement',
    'BSI-Stand-der-Technik-Kernel': 'kernel',
}

# OSCAL relationship (source relative to target) -> library vocabulary.
RELATIONSHIP = {
    'equivalent-to': 'equivalent',
    'equal-to': 'equivalent',
    'subset-of': 'subset',
    'superset-of': 'superset',
    'intersects-with': 'partial_overlap',
}
INVERSE = {'equivalent': 'equivalent', 'subset': 'superset', 'superset': 'subset',
           'partial_overlap': 'partial_overlap', 'related': 'related'}

# ISO/IEC 27001:2022 clauses 4-10 -> Grundschutz++ methodology (BSI-Standard 300-1).
# No official BSI table exists for the clauses (the BSI mapping covers Annex A
# only); this is an expert mapping of the ISMS core onto the 300-1 practices.
# (iso clause id, [gs++ ids], relationship, rationale)
CLAUSE_MAP: list[tuple[str, list[str], str, str]] = [
    ('ISO27001-4.1', ['GC.2.1', 'GC.2.2'], 'equivalent',
     'Kontext der Organisation (4.1) = externer und interner Kontext der Institution (GC.2).'),
    ('ISO27001-4.2', ['GC.4.1', 'GC.4.2', 'GC.3.1.1', 'GC.3.1.3'], 'equivalent',
     'Interessierte Parteien und ihre Anforderungen (4.2) = Analyse der interessierten Parteien (GC.4) '
     'plus gesetzliche und vertragliche Verpflichtungen (GC.3.1).'),
    ('ISO27001-4.3', ['GC.6.1', 'STM.1.1', 'STM.1.2'], 'equivalent',
     'Anwendungsbereich (4.3) = Geltungsbereich (GC.6) und Abgrenzung des Informationsverbunds samt Schnittstellen (STM.1).'),
    ('ISO27001-4.4', ['GC.1.1', 'GC.1.2'], 'equivalent',
     'ISMS errichten, aufrechterhalten, verbessern (4.4) = Errichtung und Freigabe des ISMS (GC.1).'),
    ('ISO27001-5.1', ['GC.5.1.2', 'GC.1.2', 'GC.8.1'], 'subset',
     'Führung und Verpflichtung (5.1): Verpflichtung der Leitung, Freigabe des ISMS und Ressourcen.'),
    ('ISO27001-5.2', ['GC.5.1.3', 'GC.5.1.4'], 'equivalent',
     'Informationssicherheitspolitik (5.2) = Erstellung und Freigabe der Sicherheitsleitlinie (GC.5.1.3/4).'),
    ('ISO27001-5.3', ['GC.9.1', 'GC.9.1.1', 'GC.9.1.1.1'], 'equivalent',
     'Rollen, Verantwortlichkeiten, Befugnisse (5.3) = Sicherheitsorganisation inkl. ISB (GC.9.1).'),
    ('ISO27001-6.1.1', ['GC.12.1', 'RISK.1.1'], 'subset',
     'Allgemeine Maßnahmen zum Umgang mit Risiken und Chancen (6.1.1) = Methodik für das Risikomanagement.'),
    ('ISO27001-6.1.2', ['RISK.1.1', 'RISK.1.3', 'STM.4.1', 'GC.7.1.2'], 'equivalent',
     'Risikobeurteilung (6.1.2): Methodik, Risikoeigentümer, Risikobetrachtung und Schutzbedarf.'),
    ('ISO27001-6.1.3', ['UMS.2.1', 'UMS.2.2', 'UMS.1.2', 'RISK.1.10', 'UMS.5.1'], 'equivalent',
     'Risikobehandlung (6.1.3): Umsetzungsplan, Priorisierung, Restrisiko-Bewertung, Freigabe und Ausnahmen; '
     'das Anforderungspaket ersetzt die Erklärung zur Anwendbarkeit.'),
    ('ISO27001-6.2', ['GC.5.1', 'GC.5.1.1'], 'equivalent',
     'Informationssicherheitsziele und Planung (6.2) = Festlegung von Zielen und Sicherheitsstrategie (GC.5.1).'),
    ('ISO27001-6.3', ['VRB.2.2'], 'subset',
     'Planung von Änderungen (6.3) = geplante Anpassung des ISMS (VRB.2.2).'),
    ('ISO27001-7.1', ['GC.8.1'], 'equivalent',
     'Ressourcen (7.1) = Verfahren zur Ressourcenplanung (GC.8.1).'),
    ('ISO27001-7.2', ['GC.9.1.1.4'], 'equivalent',
     'Kompetenz (7.2) = Sicherstellung der Qualifikation (GC.9.1.1.4).'),
    ('ISO27001-7.3', ['GC.10.1', 'GC.5.1.3'], 'partial_overlap',
     'Bewusstsein (7.3): Bekanntgabe von Leitlinie und Sicherheitszielen; die Sensibilisierung selbst liegt im Kernel (SENS).'),
    ('ISO27001-7.4', ['GC.10.1', 'GC.10.1.1', 'GC.10.1.2'], 'equivalent',
     'Kommunikation (7.4) = Kommunikationsmanagement (GC.10.1).'),
    ('ISO27001-7.5', ['GC.11.1'], 'equivalent',
     'Dokumentierte Information (7.5) = Dokumentenlenkung (GC.11.1).'),
    ('ISO27001-8.1', ['UMS.6.1', 'UMS.3.1', 'UMS.4.1', 'UMS.7.1'], 'equivalent',
     'Betriebliche Planung und Steuerung (8.1): Umsetzung mit Zuständigen, Fristen, Fortschrittsverfolgung und Compliance.'),
    ('ISO27001-8.2', ['STM.4.1', 'RISK.1.1'], 'equivalent',
     'Durchführung der Risikobeurteilung in geplanten Abständen (8.2) = Durchführung der Risikobetrachtung (STM.4.1).'),
    ('ISO27001-8.3', ['UMS.1.1', 'UMS.6.1', 'UMS.6.2'], 'equivalent',
     'Umsetzung der Risikobehandlung (8.3): Umsetzungsstatus, Fortschrittsverfolgung und Fortschreibung des Umsetzungsplans.'),
    ('ISO27001-9.1', ['PERF.1.1', 'PERF.1.2', 'PERF.5.1'], 'equivalent',
     'Überwachung, Messung, Analyse und Bewertung (9.1) = Leistungsbewertung des ISMS und Monitoring (PERF.1, PERF.5).'),
    ('ISO27001-9.2.1', ['PERF.3.1', 'PERF.3.2'], 'equivalent',
     'Internes Audit (9.2.1) = Auditprogramm und Dokumentation der Auditergebnisse (PERF.3).'),
    ('ISO27001-9.2.2', ['PERF.3.1', 'PERF.3.1.1', 'PERF.3.1.2', 'PERF.3.1.3', 'PERF.3.1.4'], 'equivalent',
     'Internes Auditprogramm (9.2.2) = Aufbau des Auditprogramms, Auditplan, Auditteam und Auditumfang (PERF.3.1).'),
    ('ISO27001-9.3.1', ['PERF.4.1', 'PERF.4.2'], 'equivalent',
     'Managementbewertung, allgemein (9.3.1) = Eignungsprüfung und Bericht an die Institutionsleitung (PERF.4).'),
    ('ISO27001-9.3.2', ['PERF.4.1.1', 'PERF.4.1.2', 'PERF.4.1.3', 'PERF.4.1.4', 'PERF.4.1.5', 'PERF.4.1.6',
                        'PERF.4.1.7', 'PERF.4.1.8'], 'equivalent',
     'Eingaben der Managementbewertung (9.3.2) = Prüfgegenstände der Eignungsprüfung (PERF.4.1.1 bis PERF.4.1.8).'),
    ('ISO27001-9.3.3', ['PERF.4.1.9', 'PERF.4.2'], 'equivalent',
     'Ergebnisse der Managementbewertung (9.3.3) = Maßnahmenvorschläge und Bericht an die Leitung.'),
    ('ISO27001-10.1', ['VRB.1.1', 'VRB.3.1', 'VRB.4.2', 'VRB.6.1'], 'equivalent',
     'Fortlaufende Verbesserung (10.1) = kontinuierliche Verbesserung inkl. Wirksamkeitsprüfung (VRB).'),
    ('ISO27001-10.2', ['VRB.2.1', 'VRB.4.1', 'VRB.5.1', 'VRB.6.1', 'VRB.7.1'], 'equivalent',
     'Nichtkonformität und Korrekturmaßnahmen (10.2) = Umgang mit Nicht-Konformitäten und Korrekturmaßnahmen (VRB).'),
]


def prop(obj: dict, name: str) -> list[str]:
    return [p['value'] for p in obj.get('props', []) if p.get('name') == name]


def walk_controls(node: dict, parent: str | None, practice: dict, topic: dict | None, out: list) -> None:
    for ctrl in node.get('controls', []):
        out.append((ctrl, parent, practice, topic))
        walk_controls(ctrl, ctrl['id'], practice, topic, out)


def render_prose(text: str, params: dict[str, dict]) -> str:
    def repl(m: re.Match) -> str:
        param = params.get(m.group(1))
        if param is None:
            return m.group(0)
        values = param.get('values') or []
        if values:
            return values[0]
        return '[' + (param.get('label') or m.group(1)) + ']'

    return re.sub(r'\{\{\s*insert:\s*param,\s*([^}\s]+)\s*\}\}', repl, text).strip()


def tier_of(sec_level: str, modal_verb: str) -> str:
    """Map GS++ security level + modal verb onto the app's tier vocabulary.

    basis    = MUSS on the normal level (unconditional, no risk acceptance possible)
    standard = SOLLTE/KANN on the normal level (the state of the art, "normal-SdT")
    hoch     = everything on the raised level ("erhöht", high protection needs)
    """
    if sec_level != 'normal-SdT':
        return 'hoch'
    return 'basis' if modal_verb == 'MUSS' else 'standard'


def asset_type_resolver(sdt: Path):
    """Return a function mapping a target-object category name to an Asset.assetType."""
    rows = list(csv.DictReader((sdt / TARGET_OBJECTS_PATH).read_text(encoding='utf-8').splitlines()))
    by_uuid = {r['UUID']: r for r in rows}
    by_name = {r['Zielobjekt']: r for r in rows}

    def resolve(name: str) -> str | None:
        row = by_name.get(name)
        seen = set()
        while row is not None and row['UUID'] not in seen:
            seen.add(row['UUID'])
            for key in (row['Zielobjekt'], row.get('Typ') or ''):
                if key in ASSET_TYPE_BY_ROOT:
                    return ASSET_TYPE_BY_ROOT[key]
            row = by_uuid.get(row.get('ChildOfUUID') or '')
        return None

    return resolve


def build_catalogue(sdt: Path, commit: str) -> tuple[dict, dict[str, str], set[str]]:
    data = json.loads((sdt / CATALOGUE_PATH).read_text(encoding='utf-8'))['catalog']
    asset_type_of = asset_type_resolver(sdt)
    meta = data['metadata']

    groups = []
    rows = []
    for practice in data.get('groups', []):
        topics = []
        for topic in practice.get('groups', []):
            topics.append({'id': topic['id'], 'title': topic.get('title', '')})
            walk_controls(topic, None, practice, topic, rows)
        walk_controls(practice, None, practice, None, rows)
        groups.append({'id': practice['id'], 'title': practice.get('title', ''), 'topics': topics})

    requirements = []
    uuid_to_id: dict[str, str] = {}
    for ctrl, parent, practice, topic in rows:
        params = {p['id']: p for p in ctrl.get('params', [])}
        parts = {p['name']: p for p in ctrl.get('parts', [])}
        stm = parts.get('statement', {})
        guidance = parts.get('guidance', {})
        sec_level = (prop(ctrl, 'sec_level') or [''])[0]
        modal = (prop(stm, 'modal_verb') or [''])[0]
        effort = (prop(ctrl, 'effort_level') or [None])[0]
        uuid = (prop(ctrl, 'alt-identifier') or [None])[0]
        if uuid:
            uuid_to_id[uuid] = ctrl['id']

        categories = [c.strip() for v in prop(stm, 'target_object_categories') for c in v.split(',') if c.strip()]
        asset_types = sorted({t for t in (asset_type_of(c) for c in categories) if t})

        def score(name: str) -> int | None:
            v = prop(ctrl, name)
            return int(v[0]) if v else None

        requirements.append(OrderedDict([
            ('id', ctrl['id']),
            ('parent', parent),
            ('practice', practice['id']),
            ('topic', topic['id'] if topic else practice['id']),
            ('kind', CLASS_KIND.get(ctrl.get('class', ''), 'kernel')),
            ('title', ctrl.get('title', '')),
            ('statement', render_prose(stm.get('prose', ''), params)),
            ('guidance', render_prose(guidance.get('prose', ''), params)),
            ('modal_verb', modal),
            ('sec_level', sec_level),
            ('tier', tier_of(sec_level, modal)),
            ('effort_level', int(effort) if effort is not None else None),
            ('target_object_categories', categories),
            ('asset_types', asset_types),
            ('reuse_flags', REUSE_FLAGS_BY_PRACTICE.get(practice['id'], {})),
            ('documentation', (prop(stm, 'documentation') or [None])[0]),
            ('security_targets', OrderedDict([
                ('confidentiality', score('confidentiality')),
                ('integrity', score('integrity')),
                ('availability', score('availability')),
                ('authenticity', score('authenticity')),
            ])),
            ('threats', prop(ctrl, 'threats')),
            ('tags', prop(ctrl, 'tags')),
            ('uuid', uuid),
        ]))

    catalogue = OrderedDict([
        ('source', OrderedDict([
            ('title', meta.get('title')),
            ('standard', 'BSI-Standard 300-1 (Methodik Grundschutz++)'),
            ('publisher', 'Bundesamt für Sicherheit in der Informationstechnik (BSI)'),
            ('version', meta.get('version')),
            ('last_modified', meta.get('last-modified')),
            ('repository', REPO_URL),
            ('commit', commit),
            ('path', CATALOGUE_PATH),
            ('license', 'CC BY-SA 4.0'),
            ('attribution', 'Anwenderkatalog Grundschutz++ © Bundesamt für Sicherheit in der Informationstechnik, '
                            'CC BY-SA 4.0. Inhalt unverändert übernommen; Parameter-Platzhalter aufgelöst, '
                            'Struktur für den Import vereinfacht.'),
        ])),
        ('groups', groups),
        ('requirements', requirements),
    ])
    return catalogue, uuid_to_id, {r['id'] for r in requirements}


def library_header(lib_id: str, source: str, target: str, effective_from: str, provenance: dict,
                   methodology_type: str, methodology_desc: str, history: list[dict]) -> OrderedDict:
    return OrderedDict([
        ('type', 'mapping'),
        ('id', lib_id),
        ('source_framework', source),
        ('target_framework', target),
        ('version', 1),
        ('effective_from', effective_from),
        ('effective_until', None),
        ('provenance', provenance),
        ('methodology', OrderedDict([('type', methodology_type), ('description', methodology_desc)])),
        ('lifecycle', OrderedDict([('state', 'published'), ('state_history', history)])),
    ])


def iso_annex_id(raw: str) -> str:
    return 'A.' + raw if not raw.startswith('A.') else raw


def build_iso_mapping(sdt: Path, commit: str, known: set[str]) -> tuple[OrderedDict, list[tuple[str, str, str, str]]]:
    coll = json.loads((sdt / ISO_MAPPING_PATH).read_text(encoding='utf-8'))['mapping-collection']
    meta = coll['metadata']
    entries = []
    pairs: list[tuple[str, str, str, str]] = []  # (iso, gspp, relationship, confidence)
    dropped = []
    for mp in coll['mappings']:
        for m in mp['maps']:
            rel = RELATIONSHIP[m['relationship']]
            src = iso_annex_id(m['sources'][0]['id-ref'])
            targets = []
            for t in m['targets']:
                tid = t['id-ref']
                tid = ISO_ID_RENAMES.get(tid, tid) if tid in ISO_ID_RENAMES else tid
                if tid is None or tid not in known:
                    dropped.append((src, t['id-ref']))
                    continue
                if tid not in targets:
                    targets.append(tid)
            if not targets:
                continue
            confidence = 'high' if len(targets) == 1 or rel != 'partial_overlap' else 'medium'
            entries.append(OrderedDict([
                ('source', src),
                ('targets', targets),
                ('relationship', rel),
                ('confidence', confidence),
                ('rationale', 'Amtliches BSI-Mapping ISO/IEC 27001:2022 Annex A → Grundschutz++ '
                              f'({meta.get("version")}): {src} {m["relationship"]} {", ".join(targets)}.'),
            ]))
            for tid in targets:
                pairs.append((src, tid, rel, confidence))

    desc = (
        'Amtliches Mapping des BSI aus der Stand-der-Technik-Bibliothek (OSCAL mapping-collection, '
        f'Version {meta.get("version")}, Stand {meta.get("last-modified")}). Alle 93 Annex-A-Controls auf den '
        'Anwenderkatalog Grundschutz++ (Methodik + Stand-der-Technik-Kernel). Relationship laut BSI '
        '(OSCAL equivalent-to/subset-of/intersects-with, Quelle relativ zum Ziel). '
        'Ziel-IDs, die das BSI seit Erstellung des Mappings umnummeriert hat, sind über die stabile '
        f'UUID (alt-identifier) auf die aktuelle Katalog-ID aufgelöst; {len(dropped)} Ziele wurden vom BSI '
        'zurückgezogen und entfallen: ' + ', '.join(f'{s}→{t}' for s, t in dropped) + '.'
    )
    doc = OrderedDict([
        ('schema_version', '1.1'),
        ('library', library_header(
            'iso27001-2022_to_bsi-gspp_official_v1', 'ISO27001', FRAMEWORK_CODE, '2026-10-26',
            OrderedDict([
                ('primary_source', 'official_bsi_crosswalk'),
                ('primary_source_url', f'{REPO_URL}/blob/{commit}/{ISO_MAPPING_PATH}'),
                ('source_document_title', meta.get('title')),
                ('source_document_date', (meta.get('last-modified') or '')[:10]),
                ('publisher', 'Bundesamt für Sicherheit in der Informationstechnik (BSI)'),
                ('license', 'CC BY-SA 4.0'),
            ]),
            'official_published_crosswalk', desc,
            [{'state': 'published', 'date': (meta.get('published') or '')[:10], 'actor': 'BSI'},
             {'state': 'library-imported', 'date': '2026-10-30', 'actor': 'Little-ISMS-Helper'}],
        )),
        ('mappings', entries),
    ])
    return doc, pairs


def build_reverse_iso(pairs: list[tuple[str, str, str, str]], commit: str) -> OrderedDict:
    by_target: dict[str, list[tuple[str, str, str]]] = defaultdict(list)
    for iso, gspp, rel, conf in pairs:
        by_target[gspp].append((iso, INVERSE[rel], conf))
    entries = []
    for gspp in sorted(by_target, key=natural_key):
        for iso, rel, conf in by_target[gspp]:
            entries.append(OrderedDict([
                ('source', gspp),
                ('target', iso),
                ('relationship', rel),
                ('confidence', conf),
                ('rationale', f'Umkehrung des amtlichen BSI-Mappings {iso} → {gspp}.'),
            ]))
    desc = ('Umkehrrichtung (Grundschutz++ → ISO/IEC 27001:2022 Annex A) des amtlichen BSI-Mappings '
            'iso27001-2022_to_bsi-gspp_official_v1: gleiche Paare, Relationship invertiert '
            '(subset↔superset, equivalent und partial_overlap bleiben). Damit fließt erfasste '
            'Grundschutz++-Umsetzung in die ISO-27001-Sicht zurück.')
    return OrderedDict([
        ('schema_version', '1.1'),
        ('library', library_header(
            'bsi-gspp_to_iso27001-2022_v1', FRAMEWORK_CODE, 'ISO27001', '2026-10-26',
            OrderedDict([
                ('primary_source', 'derived_from_official_bsi_crosswalk'),
                ('primary_source_url', f'{REPO_URL}/blob/{commit}/{ISO_MAPPING_PATH}'),
                ('publisher', 'Little ISMS Helper Maintainers (Umkehrung des BSI-Mappings)'),
                ('license', 'CC BY-SA 4.0'),
            ]),
            'published_official_mapping', desc,
            [{'state': 'published', 'date': '2026-10-30', 'actor': 'Little-ISMS-Helper'}],
        )),
        ('mappings', entries),
    ])


def build_itgs_mapping(sdt: Path, commit: str, uuid_to_id: dict[str, str], known: set[str],
                       itgs_ids: set[str]) -> OrderedDict:
    coll = json.loads((sdt / ITGS_MAPPING_PATH).read_text(encoding='utf-8'))['mapping-collection']
    meta = coll['metadata']
    # (anforderung, target) -> list of (relationship, defaulted)
    grouped: dict[tuple[str, str], list[tuple[str, bool]]] = defaultdict(list)
    unresolved = 0
    unknown_source = 0
    for mp in coll['mappings']:
        for m in mp['maps']:
            raw_src = m['sources'][0]['id-ref']
            src = re.sub(r'-UA\.\d+$', '', raw_src)
            if src not in itgs_ids:
                unknown_source += 1
                continue
            tgt = m['targets'][0]
            uuid = (prop(tgt, 'oscal_uuid') or [None])[0]
            tid = uuid_to_id.get(uuid) if uuid else None
            if tid is None and tgt['id-ref'] in known:
                tid = tgt['id-ref']
            if tid is None:
                unresolved += 1
                continue
            defaulted = 'true' in prop(m, 'relationship_defaulted')
            grouped[(src, tid)].append((RELATIONSHIP[m['relationship']], defaulted))

    entries = []
    for (src, tid) in sorted(grouped, key=lambda k: (natural_key(k[0]), natural_key(k[1]))):
        rels = grouped[(src, tid)]
        kinds = {r for r, _ in rels}
        rel = kinds.pop() if len(kinds) == 1 else 'partial_overlap'
        all_defaulted = all(d for _, d in rels)
        confidence = 'low' if all_defaulted else 'medium'
        entries.append(OrderedDict([
            ('source', src),
            ('target', tid),
            ('relationship', rel),
            ('confidence', confidence),
            ('rationale', 'BSI-Mapping IT-Grundschutz-Kompendium 2023 → Grundschutz++ (Entwurf): '
                          f'{src} → {tid}, {len(rels)} Teilanforderung(en)'
                          + (', Relationship vom BSI noch nicht bewertet' if all_defaulted else '') + '.'),
        ]))

    desc = (
        'Mapping des BSI aus der Stand-der-Technik-Bibliothek (GSMap-Export, Stand '
        f'{meta.get("last-modified")}), Status beim BSI: Entwurf. Das BSI mappt auf Ebene der '
        'Teilanforderungen (z. B. APP.3.6.A1-UA.2); hier auf die Anforderung (APP.3.6.A1) verdichtet, '
        'weil der Kompendium-Katalog der App auf Anforderungsebene liegt. Mehrere Teilanforderungen mit '
        'unterschiedlicher Relationship zum selben Ziel werden zu partial_overlap. Ziel-IDs über die '
        f'stabile UUID auf die aktuelle Katalog-ID aufgelöst ({unresolved} Ziele vom BSI zurückgezogen, '
        f'entfallen). Konfidenz: medium (BSI-Entwurf), low wo das BSI die Relationship noch nicht bewertet '
        'hat. Deckt nur einen Teil des Kompendiums ab; ungemappte Anforderungen bleiben ohne Vorschlag.'
    )
    return OrderedDict([
        ('schema_version', '1.1'),
        ('library', library_header(
            'bsi-it-grundschutz_to_bsi-gspp_official_v1', 'BSI_GRUNDSCHUTZ', FRAMEWORK_CODE, '2026-10-26',
            OrderedDict([
                ('primary_source', 'official_bsi_crosswalk'),
                ('primary_source_url', f'{REPO_URL}/blob/{commit}/{ITGS_MAPPING_PATH}'),
                ('source_document_title', 'IT-Grundschutz-Kompendium 2023 → Grundschutz++ (GSMap OSCAL Mapping Collection)'),
                ('source_document_date', (meta.get('last-modified') or '')[:10]),
                ('publisher', 'Bundesamt für Sicherheit in der Informationstechnik (BSI)'),
                ('license', 'CC BY-SA 4.0'),
            ]),
            'official_published_crosswalk', desc,
            [{'state': 'draft', 'date': (meta.get('last-modified') or '')[:10], 'actor': 'BSI'},
             {'state': 'published', 'date': '2026-10-30', 'actor': 'Little-ISMS-Helper'}],
        )),
        ('mappings', entries),
    ])


def build_clause_mappings(known: set[str]) -> tuple[OrderedDict, OrderedDict]:
    forward = []
    reverse: dict[str, list[tuple[str, str, str]]] = defaultdict(list)
    for iso, targets, rel, rationale in CLAUSE_MAP:
        missing = [t for t in targets if t not in known]
        if missing:
            raise SystemExit(f'CLAUSE_MAP {iso}: unknown Grundschutz++ IDs {missing}')
        forward.append(OrderedDict([
            ('source', iso), ('targets', targets), ('relationship', rel),
            ('confidence', 'medium'), ('rationale', rationale),
        ]))
        for t in targets:
            reverse[t].append((iso, INVERSE[rel], rationale))

    common_desc = (
        'Expertenmapping der ISO/IEC 27001:2022 Kapitel 4–10 (ISMS-Kern) auf die Methodik-Anforderungen '
        'von Grundschutz++ (BSI-Standard 300-1: GC, STM, UMS, VRB, PERF, RISK). Das BSI-Mapping deckt nur '
        'Annex A ab; ohne diese Zuordnung blieben die ISMS-Prozessanforderungen ohne Wiederverwendung. '
        'Konfidenz medium (keine amtliche Tabelle).'
    )
    fwd = OrderedDict([
        ('schema_version', '1.1'),
        ('library', library_header(
            'iso27001-2022-clauses_to_bsi-gspp_v1', 'ISO27001', FRAMEWORK_CODE, '2026-10-26',
            OrderedDict([
                ('primary_source', 'BSI-Standard 300-1 (Methodik Grundschutz++) und ISO/IEC 27001:2022 Kapitel 4–10'),
                ('primary_source_url', 'https://www.bsi.bund.de/SharedDocs/Downloads/DE/BSI/Grundschutz/BSI_Standards/standard_300-1.html'),
                ('publisher', 'Little ISMS Helper Maintainers'),
            ]),
            'text_comparison_with_expert_review', common_desc,
            [{'state': 'published', 'date': '2026-10-30', 'actor': 'Little-ISMS-Helper'}],
        )),
        ('mappings', forward),
    ])
    rev_entries = []
    for t in sorted(reverse, key=natural_key):
        for iso, rel, rationale in reverse[t]:
            rev_entries.append(OrderedDict([
                ('source', t), ('target', iso), ('relationship', rel),
                ('confidence', 'medium'), ('rationale', rationale),
            ]))
    rev = OrderedDict([
        ('schema_version', '1.1'),
        ('library', library_header(
            'bsi-gspp_to_iso27001-2022-clauses_v1', FRAMEWORK_CODE, 'ISO27001', '2026-10-26',
            OrderedDict([
                ('primary_source', 'BSI-Standard 300-1 (Methodik Grundschutz++) und ISO/IEC 27001:2022 Kapitel 4–10'),
                ('primary_source_url', 'https://www.bsi.bund.de/SharedDocs/Downloads/DE/BSI/Grundschutz/BSI_Standards/standard_300-1.html'),
                ('publisher', 'Little ISMS Helper Maintainers'),
            ]),
            'text_comparison_with_expert_review',
            'Umkehrrichtung von iso27001-2022-clauses_to_bsi-gspp_v1. ' + common_desc,
            [{'state': 'published', 'date': '2026-10-30', 'actor': 'Little-ISMS-Helper'}],
        )),
        ('mappings', rev_entries),
    ])
    return fwd, rev


def natural_key(s: str) -> list:
    return [int(p) if p.isdigit() else p for p in re.split(r'(\d+)', s)]


def itgs_catalogue_ids() -> set[str]:
    ids = set()
    for f in sorted((ROOT / 'fixtures/library/catalogues/bsi-it-grundschutz-2023').glob('*.yml')):
        for b in (yaml.safe_load(f.read_text(encoding='utf-8')) or {}).get('bausteine', []):
            for items in (b.get('anforderungen') or {}).values():
                for a in items or []:
                    ids.add(str(a['id']))
    return ids


class _Dumper(yaml.SafeDumper):
    pass


_Dumper.add_representer(OrderedDict, lambda d, data: d.represent_mapping('tag:yaml.org,2002:map', data.items()))


def write_yaml(path: Path, doc: OrderedDict, header: str) -> None:
    body = yaml.dump(doc, Dumper=_Dumper, allow_unicode=True, sort_keys=False, width=110)
    path.write_text(header + body, encoding='utf-8')


def main() -> None:
    if len(sys.argv) != 2:
        raise SystemExit(__doc__)
    sdt = Path(sys.argv[1])
    commit = subprocess.check_output(['git', '-C', str(sdt), 'rev-parse', 'HEAD'], text=True).strip()

    catalogue, uuid_to_id, known = build_catalogue(sdt, commit)
    CATALOGUE_OUT.parent.mkdir(parents=True, exist_ok=True)
    CATALOGUE_OUT.write_text(json.dumps(catalogue, ensure_ascii=False, indent=1) + '\n', encoding='utf-8')

    header = ('# GENERATED by scripts/library/build_bsi_grundschutz_plusplus.py — do not edit by hand.\n'
              f'# Source: {REPO_URL} @ {commit} (CC BY-SA 4.0)\n')
    iso_doc, iso_pairs = build_iso_mapping(sdt, commit, known)
    write_yaml(MAPPINGS_DIR / 'iso27001-2022_to_bsi-gspp_official_v1.yaml', iso_doc, header)
    write_yaml(MAPPINGS_DIR / 'bsi-gspp_to_iso27001-2022_v1.yaml', build_reverse_iso(iso_pairs, commit), header)
    write_yaml(MAPPINGS_DIR / 'bsi-it-grundschutz_to_bsi-gspp_official_v1.yaml',
               build_itgs_mapping(sdt, commit, uuid_to_id, known, itgs_catalogue_ids()), header)
    fwd, rev = build_clause_mappings(known)
    write_yaml(MAPPINGS_DIR / 'iso27001-2022-clauses_to_bsi-gspp_v1.yaml', fwd, header)
    write_yaml(MAPPINGS_DIR / 'bsi-gspp_to_iso27001-2022-clauses_v1.yaml', rev, header)

    print(f'catalogue: {len(catalogue["requirements"])} requirements, '
          f'ISO pairs: {len(iso_pairs)}, clause rows: {len(CLAUSE_MAP)}')


if __name__ == '__main__':
    main()
