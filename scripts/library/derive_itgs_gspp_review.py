#!/usr/bin/env python3
"""Close the gaps of the BSI mapping IT-Grundschutz-Kompendium 2023 -> Grundschutz++.

Usage:
    python3 scripts/library/derive_itgs_gspp_review.py candidates <out-dir> [--batch-size N]
    python3 scripts/library/derive_itgs_gspp_review.py assemble <verdict-dir> <run-date>
    python3 scripts/library/derive_itgs_gspp_review.py build
    python3 scripts/library/derive_itgs_gspp_review.py stats

The BSI mapping (`bsi-it-grundschutz_to_bsi-gspp_official_v1.yaml`, BSI draft)
covers 510 of the 1834 Kompendium requirements. For the other 1324 this script

  1. `candidates`: proposes up to ten Grundschutz++ requirements per Kompendium
     requirement and writes them as review batches. Signals, strongest first:
       - siblings: GS++ targets the BSI gives other requirements of the same Baustein
       - ISO pivot: official BSI CRT ISO 27001 -> Kompendium (inverted) composed
         with the official BSI mapping ISO 27001 -> GS++
       - text similarity: character 4-gram TF-IDF over title and requirement text
  2. reviewers (two independent expert reviews per batch) pick 0-3 targets per
     requirement, or record that Grundschutz++ has no counterpart. Their verdicts
     live in fixtures/library/mappings/panel_verdicts/bsi-it-grundschutz_to_bsi-gspp_review_v1.json.
  3. `assemble`: merges the review files (`batch_NN_A.json`, `batch_NN_B.json`)
     into the verdict fixture, including the list `no_counterpart` the app reads.
  4. `build`: keeps a pair only when both reviewers chose it (relationship
     disagreement -> partial_overlap) and writes
     `bsi-it-grundschutz_to_bsi-gspp_derived_v1.yaml`. Provenance
     `derived_expert_review` keeps every pair in the heuristic tier: inheritance and
     the gap view propose them for review, nothing counts as confirmed.

`stats` prints the coverage the app shows (official + derived + no counterpart).
Deterministic apart from the review step; rerun `build` after editing verdicts.
"""

from __future__ import annotations

import json
import math
import re
import sys
from collections import Counter, OrderedDict, defaultdict
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
MAPPINGS = ROOT / 'fixtures/library/mappings'
CATALOGUE = ROOT / 'fixtures/library/catalogues/bsi-grundschutz-plusplus/catalogue.json'
ITGS_DIR = ROOT / 'fixtures/library/catalogues/bsi-it-grundschutz-2023'
OFFICIAL = MAPPINGS / 'bsi-it-grundschutz_to_bsi-gspp_official_v1.yaml'
ISO_GSPP = MAPPINGS / 'iso27001-2022_to_bsi-gspp_official_v1.yaml'
ISO_ITGS_CRT = MAPPINGS / 'iso27001-2022_to_bsi-grundschutz_official-crt_v1.yaml'
VERDICTS = MAPPINGS / 'panel_verdicts/bsi-it-grundschutz_to_bsi-gspp_review_v1.json'
OUT = MAPPINGS / 'bsi-it-grundschutz_to_bsi-gspp_derived_v1.yaml'

USABLE = ('equivalent', 'subset', 'superset', 'partial_overlap')
MAX_CANDIDATES = 10


def natural_key(s: str) -> list:
    return [int(p) if p.isdigit() else p for p in re.split(r'(\d+)', s)]


def load_itgs() -> OrderedDict:
    reqs: OrderedDict = OrderedDict()
    for f in sorted(ITGS_DIR.glob('*.yml')):
        for b in (yaml.safe_load(f.read_text(encoding='utf-8')) or {}).get('bausteine', []):
            for tier, items in (b.get('anforderungen') or {}).items():
                for a in items or []:
                    reqs[str(a['id'])] = {
                        'id': str(a['id']), 'baustein': b['id'], 'baustein_title': b.get('title', ''),
                        'tier': tier, 'title': a.get('title', ''), 'text': a.get('requirement_text', ''),
                    }
    return reqs


def load_gspp() -> OrderedDict:
    data = json.loads(CATALOGUE.read_text(encoding='utf-8'))
    return OrderedDict((r['id'], r) for r in data['requirements'])


def official_pairs() -> list[dict]:
    return yaml.safe_load(OFFICIAL.read_text(encoding='utf-8'))['mappings']


def expand(entries: list[dict]) -> list[tuple[str, str, str]]:
    out = []
    for m in entries:
        targets = m.get('targets') or [m.get('target')]
        for t in targets:
            if t is not None:
                out.append((str(m['source']), str(t), m.get('relationship') or 'partial_overlap'))
    return out


# ---------------------------------------------------------------- text similarity

def grams(text: str) -> Counter:
    text = re.sub(r'[^a-zäöüß0-9 ]', ' ', text.lower())
    text = re.sub(r'\b(muss|müssen|sollte|sollten|kann|können|darf|dürfen|nicht|werden|wird|der|die|das|und|oder'
                  r'|ein|eine|einer|eines|zu|von|für|mit|auf|in|im|den|dem|des|sich|ist|sind|alle|bei|als|auch)\b',
                  ' ', text)
    c: Counter = Counter()
    for word in text.split():
        w = f' {word} '
        for i in range(max(1, len(w) - 3)):
            c[w[i:i + 4]] += 1
    return c


def tfidf(docs: dict[str, Counter]) -> dict[str, dict[str, float]]:
    df: Counter = Counter()
    for c in docs.values():
        df.update(c.keys())
    n = len(docs)
    out = {}
    for k, c in docs.items():
        vec = {g: (1 + math.log(v)) * math.log(n / df[g]) for g, v in c.items()}
        norm = math.sqrt(sum(x * x for x in vec.values())) or 1.0
        out[k] = {g: x / norm for g, x in vec.items()}
    return out


def cosine(a: dict[str, float], b: dict[str, float]) -> float:
    if len(a) > len(b):
        a, b = b, a
    return sum(x * b.get(g, 0.0) for g, x in a.items())


# ---------------------------------------------------------------- candidates

def build_candidates() -> tuple[list[dict], OrderedDict, OrderedDict]:
    itgs = load_itgs()
    gspp = load_gspp()
    pairs = official_pairs()
    mapped = {p['source'] for p in pairs}

    sibling_targets: dict[str, Counter] = defaultdict(Counter)
    for p in pairs:
        sibling_targets[itgs[p['source']]['baustein']][p['target']] += 1

    iso_gspp: dict[str, set[str]] = defaultdict(set)
    for s, t, _ in expand(yaml.safe_load(ISO_GSPP.read_text(encoding='utf-8'))['mappings']):
        iso_gspp[s].add(t)
    # CRT: ISO -> Kompendium (Baustein or Anforderung). Invert to Kompendium -> GS++ via ISO.
    pivot: dict[str, set[str]] = defaultdict(set)
    for s, t, _ in expand(yaml.safe_load(ISO_ITGS_CRT.read_text(encoding='utf-8'))['mappings']):
        for g in iso_gspp.get(s, ()):
            pivot[t].add(g)

    gspp_docs = {gid: grams(' '.join([r['title'], r['title'], r.get('statement', ''), ' '.join(r.get('tags', []))]))
                 for gid, r in gspp.items()}
    itgs_docs = {rid: grams(' '.join([r['title'], r['title'], r['baustein_title'], r['text']]))
                 for rid, r in itgs.items() if rid not in mapped}
    vecs = tfidf({**{'G:' + k: v for k, v in gspp_docs.items()}, **{'K:' + k: v for k, v in itgs_docs.items()}})

    out = []
    for rid in itgs_docs:
        r = itgs[rid]
        kv = vecs['K:' + rid]
        sim = {g: cosine(kv, vecs['G:' + g]) for g in gspp}
        why: dict[str, set[str]] = defaultdict(set)
        # Each signal contributes its best matches by text similarity, so one strong
        # signal cannot crowd out the others: 6 by text, 3 siblings, 2 over ISO.
        for g in sorted(gspp, key=lambda g: -sim[g])[:6]:
            why[g].add('text')
        for g in sorted(sibling_targets.get(r['baustein'], {}), key=lambda g: -sim[g])[:3]:
            why[g].add('baustein')
        iso = pivot.get(rid, set()) | pivot.get(r['baustein'], set())
        for g in sorted(iso, key=lambda g: -sim[g])[:2]:
            why[g].add('iso')
        ranked = sorted(why, key=lambda g: (-len(why[g]), -sim[g], natural_key(g)))[:MAX_CANDIDATES]
        out.append({
            'id': rid,
            'baustein': f"{r['baustein']} {r['baustein_title']}",
            'tier': r['tier'],
            'title': r['title'],
            'text': r['text'][:600],
            'candidates': [
                {'id': g, 'title': gspp[g]['title'], 'statement': gspp[g].get('statement', '')[:200],
                 'signals': sorted(why[g])}
                for g in ranked
            ],
        })
    return out, itgs, gspp


def cmd_candidates(out_dir: Path, batch_size: int) -> None:
    items, _, _ = build_candidates()
    out_dir.mkdir(parents=True, exist_ok=True)
    for i in range(0, len(items), batch_size):
        path = out_dir / f'batch_{i // batch_size + 1:02d}.json'
        path.write_text(json.dumps(items[i:i + batch_size], ensure_ascii=False, indent=0), encoding='utf-8')
    print(f'{len(items)} requirements, {math.ceil(len(items) / batch_size)} batches in {out_dir}')


# ---------------------------------------------------------------- build

def load_verdicts() -> dict:
    return json.loads(VERDICTS.read_text(encoding='utf-8'))


def consensus(verdicts: dict) -> tuple[list[tuple[str, str, str, list[str]]], set[str]]:
    """Return (pairs, no_counterpart). A pair needs both reviews; a requirement is
    'no counterpart' when both reviews say so."""
    pairs = []
    none = set()
    for rid, reviews in sorted(verdicts['requirements'].items(), key=lambda kv: natural_key(kv[0])):
        if len(reviews) < 2:
            continue
        picks = [{t['target']: t for t in rv.get('targets', [])} for rv in reviews]
        common = set(picks[0])
        for p in picks[1:]:
            common &= set(p)
        for tid in sorted(common, key=natural_key):
            rels = {p[tid]['relationship'] for p in picks}
            rel = rels.pop() if len(rels) == 1 else 'partial_overlap'
            pairs.append((rid, tid, rel, [p[tid].get('rationale', '') for p in picks]))
        if all(not rv.get('targets') for rv in reviews):
            none.add(rid)
    return pairs, none


def cmd_assemble(verdict_dir: Path, run_date: str) -> None:
    itgs = load_itgs()
    mapped = {p['source'] for p in official_pairs()}
    reviews: dict[str, list[dict]] = defaultdict(list)
    files = sorted(verdict_dir.glob('batch_*_[AB].json'))
    for f in files:
        data = json.loads(f.read_text(encoding='utf-8'))
        batch = verdict_dir.parent / 'batches' / (f.stem[:-2] + '.json')
        if batch.exists():
            expected = {item['id'] for item in json.loads(batch.read_text(encoding='utf-8'))}
            if set(data) != expected:
                raise SystemExit(f'{f.name}: ids differ from {batch.name}')
        for rid, verdict in data.items():
            if rid not in itgs or rid in mapped:
                raise SystemExit(f'{f.name}: unexpected requirement {rid}')
            reviews[rid].append({
                'reviewer': f.stem[-1],
                'targets': [OrderedDict([('target', t['target']), ('relationship', t['relationship']),
                                         ('rationale', t.get('rationale', ''))]) for t in verdict.get('targets', [])],
                **({'note': verdict['note']} if verdict.get('note') else {}),
            })
    for rid in reviews:
        reviews[rid].sort(key=lambda r: r['reviewer'])
    doc = OrderedDict([
        ('provenance', OrderedDict([
            ('source_framework', 'BSI_GRUNDSCHUTZ'),
            ('target_framework', 'BSI-GSPP'),
            ('description', 'Two independent expert reviews (A: BSI-Grundschutz-Berater, B: externer Auditor) of '
                            'the candidates from derive_itgs_gspp_review.py for every Kompendium requirement the '
                            'BSI mapping does not cover. build keeps pairs both reviews chose.'),
            ('run_date', run_date),
            ('reviewed', len(reviews)),
        ])),
        ('requirements', OrderedDict(sorted(reviews.items(), key=lambda kv: natural_key(kv[0])))),
    ])
    _pairs, none = consensus(doc)
    doc['no_counterpart'] = sorted(none, key=natural_key)
    VERDICTS.write_text(json.dumps(doc, ensure_ascii=False, indent=1) + '\n', encoding='utf-8')
    print(f'{len(files)} review files, {len(reviews)} requirements -> {VERDICTS.relative_to(ROOT)}')


def cmd_build() -> None:
    verdicts = load_verdicts()
    itgs = load_itgs()
    gspp = load_gspp()
    mapped = {p['source'] for p in official_pairs()}
    pairs, _none = consensus(verdicts)
    entries = []
    for rid, tid, rel, rationales in pairs:
        if rid not in itgs or tid not in gspp or rid in mapped or rel not in USABLE:
            raise SystemExit(f'invalid verdict pair {rid} -> {tid} ({rel})')
        reason = max(rationales, key=len).strip().rstrip('.')
        entries.append(OrderedDict([
            ('source', rid),
            ('target', tid),
            ('relationship', rel),
            ('confidence', 'medium' if rel == 'equivalent' else 'low'),
            ('rationale', f'Ergänzung zum BSI-Mapping (Expertenprüfung, zwei unabhängige Reviews): {reason}.'),
        ]))
    meta = verdicts['provenance']
    doc = OrderedDict([
        ('schema_version', '1.1'),
        ('library', OrderedDict([
            ('type', 'mapping'),
            ('id', 'bsi-it-grundschutz_to_bsi-gspp_derived_v1'),
            ('source_framework', 'BSI_GRUNDSCHUTZ'),
            ('target_framework', 'BSI-GSPP'),
            ('version', 1),
            ('effective_from', '2026-10-26'),
            ('effective_until', None),
            ('provenance', OrderedDict([
                ('primary_source', 'derived_expert_review'),
                ('primary_source_url', 'https://github.com/moag1000/Little-ISMS-Helper/blob/main/'
                                       'fixtures/library/mappings/panel_verdicts/'
                                       'bsi-it-grundschutz_to_bsi-gspp_review_v1.json'),
                ('source_document_title', 'Ergänzung des BSI-Mappings Kompendium 2023 → Grundschutz++'),
                ('source_document_date', meta['run_date']),
                ('publisher', 'Little-ISMS-Helper'),
                ('license', 'CC BY-SA 4.0 (abgeleitet aus BSI-Katalogen)'),
            ])),
            ('methodology', OrderedDict([
                ('type', 'machine_assisted_with_review'),
                ('description', (
                    'Für die Kompendium-Anforderungen ohne BSI-Mapping: bis zu zehn Kandidaten je Anforderung '
                    '(Ziele des BSI-Mappings für andere Anforderungen desselben Bausteins, amtlicher Pfad über '
                    'ISO 27001, Textähnlichkeit), geprüft in zwei unabhängigen Reviews. Ein Paar steht nur hier, '
                    'wenn beide Reviews es gewählt haben. Provenienz derived_expert_review: heuristische Stufe, '
                    'die Vererbung schlägt die Paare zur Prüfung vor und übernimmt nichts ungeprüft. Ersetzt '
                    'kein BSI-Mapping; sobald das BSI eine Anforderung mappt, gilt dessen Paar.')),
            ])),
            ('lifecycle', OrderedDict([
                ('state', 'published'),
                ('state_history', [{'state': 'published', 'date': meta['run_date'], 'actor': 'Little-ISMS-Helper'}]),
            ])),
        ])),
        ('mappings', entries),
    ])
    header = ('# GENERATED by scripts/library/derive_itgs_gspp_review.py build — do not edit by hand.\n'
              '# Input: panel_verdicts/bsi-it-grundschutz_to_bsi-gspp_review_v1.json\n')

    class Dumper(yaml.SafeDumper):
        pass

    Dumper.add_representer(OrderedDict, lambda d, data: d.represent_mapping('tag:yaml.org,2002:map', data.items()))
    OUT.write_text(header + yaml.dump(doc, Dumper=Dumper, allow_unicode=True, sort_keys=False, width=110),
                   encoding='utf-8')
    print(f'{len(entries)} pairs -> {OUT.relative_to(ROOT)}')
    cmd_stats()


def cmd_stats() -> None:
    itgs = load_itgs()
    mapped = {p['source'] for p in official_pairs()}
    derived: set[str] = set()
    none: set[str] = set()
    if VERDICTS.exists():
        pairs, none = consensus(load_verdicts())
        derived = {p[0] for p in pairs}
    total = len(itgs)
    reviewed = mapped | derived | none
    print(f'Kompendium requirements: {total}')
    print(f'  BSI mapping:        {len(mapped)} ({100 * len(mapped) / total:.1f} %)')
    print(f'  derived (review):   {len(derived)}')
    print(f'  no counterpart:     {len(none)}')
    print(f'  open:               {total - len(reviewed)}')
    print(f'  with GS++ target:   {len(mapped | derived)} ({100 * len(mapped | derived) / total:.1f} %)')


def main() -> None:
    args = sys.argv[1:]
    if not args:
        raise SystemExit(__doc__)
    if args[0] == 'candidates':
        size = int(args[args.index('--batch-size') + 1]) if '--batch-size' in args else 150
        cmd_candidates(Path(args[1]), size)
    elif args[0] == 'assemble':
        cmd_assemble(Path(args[1]), args[2])
    elif args[0] == 'build':
        cmd_build()
    elif args[0] == 'stats':
        cmd_stats()
    else:
        raise SystemExit(__doc__)


if __name__ == '__main__':
    main()
