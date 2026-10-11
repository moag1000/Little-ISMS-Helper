---
name: project-mris-v18-tisax-isa2027
description: "MRIS ist auf v1.8 + eigenen TISAX-ISA-2027-Track gewachsen; Lizenz wechselte auf CC BY-NC 4.0 (offene Entscheidung), App steht auf v1.5"
metadata: 
  node_type: memory
  type: project
  originSessionId: 8e0e42f3-414a-4eb2-8290-c650a1a95dad
  modified: 2026-08-12T21:12:29.732Z
---

Stand 2026-08-12, Quelle https://mris.info (Site statt nur Whitepaper).

**Was sich geändert hat**
- MRIS ISO-Track: v1.5 → **v1.8** (Implementation Guide 1.4). Annex-A-Klassifikation
  ist **identisch** zur lokalen v1.5: 93 Controls, 29 S / 37 T / 4 R / 23 N,
  0 Kategorie-Änderungen, 0 MHC-Mapping-Änderungen (per Diff verifiziert gegen
  `fixtures/seeds/mris_annex_a_classification.csv`).
- **Lizenz CC BY 4.0 → CC BY-NC 4.0.** Lokale Fixture-Provenance sagt noch CC BY 4.0.
  NC berührt kommerzielle Weitergabe der MHC-Texte → offene Entscheidung des Users
  ("erst klären, MRIS unverändert lassen"). Vergleichbar mit [[feedback-tisax-catalog-license]].
- Neue Metadaten im maschinenlesbaren YAML (`MRIS_v1.8_maschinenlesbar.yaml`):
  `framework_basis` (C5:2026, NIST 800-207, DORA Art. 26/27, TIBER-EU, ISO 42001),
  benannte `kpis`, `effort` je Reifestufe. Lokale Fixture hat dafür `maturity_levels`,
  die dem YAML fehlen → mergen, nicht ersetzen.
- **Neu: TISAX-ISA-2027-Track** (MRIS TISAX v1.1): 45 Controls / 324 Requirements,
  10 MHC, davon **MHC-14/15/16 neu** (Unabhängige Lieferantenverifikation /
  KI-resistente Identitätsverifikation / Multi-Agent-Integrität). Lokal existiert
  davon nichts; Repo hat nur VDA **ISA 6** (`fixtures/library/frameworks/vda-isa-tisax-v6.yaml`
  + 8 Mappings). User sagt: beide Versionen sind derzeit zertifizierbar → parallel
  führen, nicht ersetzen.

**Datenlage für den ISA-2027-Aufbau**
`https://mris.info/app-tisax.js` liefert `CTRL` (31 Controls mit Kurztitel + MHC),
`MHC` (10 Einträge mit ISA-Control-Listen), `CATS` (4/26/1/14 = 45). Die 14
„nicht betroffen"-Controls listet die Seite **nicht** — die fehlenden IDs kämen nur
aus dem VDA-Katalog bzw. den MRIS-PDFs.

**Offen:** NC-Lizenzfrage; Quelle für die 14 fehlenden ISA-2027-Control-IDs;
dann Fixture nach dem ISA-6-Muster (nur Nummern + Mappings, Volltext via BYO-Upload).
