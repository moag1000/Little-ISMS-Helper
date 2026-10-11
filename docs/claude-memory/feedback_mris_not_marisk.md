---
name: MRIS = Mythos-Resistant-Informationssicherheit (NICHT MaRisk)
description: MRIS-v1.5 im Repo ist Custom-Framework Mythos-Resistant-Informationssicherheit, keine BaFin-MaRisk. 13 Reqs sind angemessen, NICHT zu grob.
type: project
originSessionId: f9f6b4bc-9a59-429d-a457-d9b45d40d0f5
---
`MRIS-v1.5` Framework im ISMS-Helper-Repo ist **Mythos-Resistant
Informationssicherheit**, ein eigenes/internes Framework. NICHT MaRisk
(BaFin Mindestanforderungen an das Risikomanagement).

**Why:** Coarseness-Audit am 2026-05-06 hat MRIS faelschlich als MaRisk
eingestuft und als "13 zu grob, sollte ~150 sein" beschrieben — User
korrigierte: MRIS ist ein anderes Framework, 13 Reqs sind angemessen.

**How to apply:**
- MRIS-v1.5 nicht mit MaRisk verwechseln
- 13 Reqs fuer MRIS sind by-design angemessen, NICHT als Catalogue-Luecke werten
- Wenn User Bedarf an MaRisk-Catalogue hat: separates Framework (z.B. 'MARISK-2024' mit BaFin-AT/BT-Modulen)
- BAIT/MaRisk-Mappings: weiterhin DORA-lex-specialis-Approach
