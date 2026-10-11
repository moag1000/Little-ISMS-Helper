---
name: DORA loest VAIT/BAIT/KAIT/ZAIT ab
description: EU-DORA hat die BaFin-IT-Rundschreiben abgeloest — VAIT/KAIT/ZAIT komplett (16.01.2025), BAIT nur gestaffelt bis 31.12.2026. NICHT als separate Wizards vorschlagen.
metadata:
  type: feedback
  modified: 2026-08-14T14:59:33.170Z
originSessionId: f9f6b4bc-9a59-429d-a457-d9b45d40d0f5
---
BaFin hat die IT-Rundschreiben wegen Doppelregulierung mit DORA zurueckgezogen.
Praezise Lage (verifiziert 2026-08-14 an BaFin-Primaerquellen):

- **VAIT / KAIT / ZAIT**: vollstaendig aufgehoben mit Ablauf 16.01.2025.
- **BAIT**: NICHT komplett tot. Institute unter DORA Art. 5-15 bzw. Art. 16
  sind seit 16.01.2025 raus (Kapitel 11 sofort aufgehoben), volle Aufhebung
  erst **31.12.2026**. Kleinere Institute ausserhalb DORA-Scope muessen BAIT
  bis dahin weiter anwenden; DORA erreicht sie am 01.01.2027 via FinmadiG.
- **MaRisk**: lebt weiter, aber 9. Novelle = Rundschreiben 06/2026 (BA) vom
  30.06.2026. Prinzipienbasiert, AT 7.2 (IKT) weitgehend entfallen (→ DORA),
  AT 9 (Auslagerungen) bleibt mit DORA-Abgrenzungsblock. Modulnamen (AT/BTO/BTR)
  bleiben, Inhalte sind verschoben → Modulnummern aus 06/2024 nie ungeprueft
  zitieren.

**Why:** Die frueher hier gespeicherte Pauschale „alle vier seit Jan 2025
obsolet" ist bei BAIT falsch und haette Kleininstitute fehlberaten. Ausserdem
stand im isms-specialist-Skill ein Workflow, der Versicherer nach VAIT und
Zahlungsdienstleister nach ZAIT geroutet hat — korrigiert in PR #1025.

**How to apply:** Nie VAIT/KAIT/ZAIT als eigene Wizards/Frameworks vorschlagen.
Bei „BaFin-Compliance"-Fragen zuerst Entity-Typ UND Groesse erfragen, weil nur
davon abhaengt ob der BAIT-Rest noch greift. Sonst auf DORA-Wizard verweisen.
PSD2/SCA-Pflichten ueberleben ZAIT (kommen aus PSD2 selbst), VAG/SolvencyII
und KAGB sind unberuehrt.

Siehe [[project_certification_blockers]]; Verifikationsdisziplin
[[feedback_run_it_dont_assume]].
