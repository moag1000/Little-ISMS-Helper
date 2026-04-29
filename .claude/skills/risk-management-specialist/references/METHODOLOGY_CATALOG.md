# Risk-Methodology Catalog

Tool-agnostic reference for risk-management methodologies, matrix variants, and method-selection decision trees. Loaded on demand by the `risk-management-specialist` skill.

## Method-Selection Decision Table

| Methode | Wann sinnvoll | Aufwand | Ergebnis-Sprache | Daten-Voraussetzung |
|---|---|---|---|---|
| **5×5-Matrix** (ISO 27005 default) | Mittelstand ohne Risiko-Reife, Erst-ISMS | gering | H/M/L Heatmap | nur Erfahrung der RisikoOwner |
| **EBIOS RM** (ANSSI) | Public Sector, Konzern-IT, KRITIS | mittel | Strategische Treatment-Roadmap | Stakeholder-Mapping, Bedrohungsquellen |
| **CRQ / FAIR-light** | Finanzdienstleister, Versicherer, große Konzerne | hoch | €/Jahr ALE | Incident-Historie, Branchen-Benchmarks |
| **OCTAVE Allegro** | Asset-zentriert mit Workshops | mittel | strukturierte Asset-Profile | Workshop-Bereitschaft |
| **NIST RMF** | US-affine Konzerne, FedRAMP | hoch | 7-step structured | weitläufiges Compliance-Programm |

### Decision Tree

```
Reife-Frage:
├── "Wir machen ISMS zum ersten Mal" → 5×5
├── "Public Sector / KRITIS / Konzern-IT" → EBIOS RM
├── "Finanz / Versicherung / Board fragt nach €/Jahr" → CRQ/FAIR-light
└── "Asset-getriebener Workshop-Stil" → OCTAVE Allegro

Daten-Frage:
├── Incident-Historie ≥ 3 Jahre vorhanden? → CRQ/FAIR-light tragfähig
├── Sonst: → bei 5×5 oder EBIOS RM bleiben

Default: 5×5 (auditfest, Standard-Erwartung der Zertifizierer)
```

---

## EBIOS RM — Five-Workshop Flow

EBIOS RM (Expression des Besoins et Identification des Objectifs de Sécurité — Risk Management) ist der ANSSI-Ansatz für strukturierte Risikoanalyse. **Deutscher Public Sector** orientiert sich zunehmend daran wegen strukturiertem Geschäftswert-Mapping.

| Workshop | Inhalt | Ergebnis |
|---|---|---|
| **W1 — Cadrage & Socle** | Scope-Definition, Geschäftswerte (Business Values) statt Asset-Liste | Schutzgut-Inventar |
| **W2 — Sources de risque** | Bedrohungsquellen-Mapping (Motiv × Mittel × Gelegenheit) | Threat-Actor-Profile |
| **W3 — Scénarios stratégiques** | Strategische Szenarien (Top-Down, Stakeholder-Sicht) | Strategic-Risk-Karte |
| **W4 — Scénarios opérationnels** | Operative Szenarien (Bottom-Up, Asset-Sicht) | Operational-Risk-Inventar |
| **W5 — Traitement** | Treatment-Strategie + Risk-Transfer + Restrisiko | Treatment-Plan + Acceptance-Liste |

**Unterschied zu ISO 27005:**
- ISO 27005 = Asset-Bottom-Up (Identify Asset → Threat → Vulnerability → Risk)
- EBIOS RM = Geschäftswert-Top-Down + Bedrohungsquelle separat → erst dann Asset-Mapping

**Vorteil für DSGVO-Kontext:** Strukturansatz mit Geschäftswerten + Risiko-Akteuren erleichtert DSFA-Argumentation.

---

## CRQ / FAIR-Light — PERT-Verteilung

Cyber Risk Quantification mit FAIR-light (vereinfacht). Pro Risiko zusätzliche Felder:

| Feld | Bedeutung | Beispiel |
|---|---|---|
| `frequency_per_year` | Eintritts-Häufigkeit (Schätzung) | 0.1 = einmal in 10 Jahren, 12 = monatlich |
| `loss_min` | Best-Case-Schaden | EUR 5.000 |
| `loss_expected` | Erwarteter Schaden | EUR 50.000 |
| `loss_max` | Worst-Case-Schaden | EUR 500.000 |
| `ale` | `frequency × loss_expected` | EUR 5.000/Jahr |
| `var_95` | Value-at-Risk 95% (Monte-Carlo, optional) | EUR 250.000 |

### FAIR-Faktor-Decomposition

```
Risk
├── Loss Event Frequency (LEF)
│   ├── Threat Event Frequency (TEF)
│   │   ├── Contact Frequency
│   │   └── Probability of Action
│   └── Vulnerability
│       ├── Threat Capability
│       └── Resistance Strength
└── Loss Magnitude (LM)
    ├── Primary Loss
    │   ├── Productivity
    │   ├── Response
    │   └── Replacement
    └── Secondary Loss
        ├── Reputation
        ├── Competitive Advantage
        └── Fines/Judgments
```

**SMB-Caveat:** quantitative Schätzung selbst hochgradig unsicher → nur dort nutzen wo Datenbasis (Incident-Historie, Branchen-Benchmarks). Default in `Little ISMS Helper` bleibt 5×5-Matrix; CRQ optional pro Tenant aktivierbar.

---

## OCTAVE Allegro — 8 Steps

OCTAVE = Operationally Critical Threat, Asset, and Vulnerability Evaluation (CMU/SEI). Allegro ist die Streamlined-Variante.

| Step | Aktivität | Tool-Mapping |
|---|---|---|
| 1 | Risk Measurement Criteria etablieren | RiskAppetite-Konfiguration |
| 2 | Information Asset Profile entwickeln | Asset-Entity ausfüllen |
| 3 | Information Asset Containers identifizieren | Asset-Beziehungen + dependsOn |
| 4 | Areas of Concern identifizieren | Risk-Entity erfassen |
| 5 | Threat-Szenarien identifizieren | Risk.threatSource + .vulnerability |
| 6 | Risiken identifizieren | Risk-Score-Berechnung |
| 7 | Risk Analysis | Risk.inherentRisk + .residualRisk |
| 8 | Treatment-Approach wählen | Risk.treatmentStrategy + RiskTreatmentPlan |

**Eigenheit:** Workshop-driven, braucht Risk-Owner-Zeit. Deutsche Mittelstands-Reaktion: zu zeitaufwendig → meist nicht gewählt.

---

## NIST RMF — 7 Steps

```
1. Prepare         → Tenant-Setup, RiskAppetite-Definition
2. Categorize      → Asset-Klassifikation (CIA-Bewertung)
3. Select          → Control-Selection aus 93 Annex-A-Controls
4. Implement       → Control.implementationStatus
5. Assess          → Control-Wirksamkeitsmessung
6. Authorize       → Risk-Acceptance-Workflow
7. Monitor         → KRI-Dashboard, Risk-Reviews
```

US-affin, FedRAMP-Path. In DACH-KMU selten direkt verwendet, aber als Strukturhilfe für Mapping zu ISO 27001 nutzbar.

---

## Risk-Matrix-Variants

Die Standard-5×5-Matrix ist der Default in ISO 27005 / `Little ISMS Helper`. Andere Varianten:

| Größe | Anwendung | Vorteil | Nachteil |
|---|---|---|---|
| **3×3** | sehr kleine Organisationen, KMU-Onboarding | leicht zu erklären | wenig Differenzierung |
| **4×4** | Mittelstand, gerade Anzahl vermeidet "Mitte"-Bias | keine neutrale Mitte | unintuitive Skala |
| **5×5** (Standard) | ISO 27005, BSI 200-3, Standard-ISMS | gut differenziert, weite Verbreitung | "neutrale Mitte" 3×3=9 als Default-Bias |
| **7×7** | Konzern-IT, hochreife Risiko-Funktion | feinkörnig | erfordert geschulte Bewerter |
| **10×10** | quantitative Bewertungen | maximale Differenzierung | meist nicht praktikabel ohne Daten |

### Color Conventions

```
1×1 - 1×3   → Grün (Low)        → "acceptable"
1×4 - 3×3   → Gelb (Medium)     → "review_required"
3×4 - 4×5   → Orange (High)     → "exceeds_appetite, treat"
5×5         → Rot (Critical)    → "unacceptable, immediate action"
```

Anpassbar via `RiskAppetite.maxAcceptableRisk` (1-25) + `reviewBufferMultiplier` (1.0-3.0). Beispiel: `maxAcceptableRisk = 9`, `reviewBufferMultiplier = 1.5` → `acceptable ≤ 9`, `review_required ≤ 13`, `exceeds_appetite > 13`.

---

## Standards Cross-Reference

| Standard | Use | Detail-File |
|---|---|---|
| ISO 27005:2022 | IT Risk Management process | `iso-27005-reference.md` |
| ISO 31000:2018 | Enterprise Risk Management framework | `iso-31000-reference.md` |
| BSI 200-3 | DACH Risk Management for IT-Grundschutz | `BSI_200_3.md` |
| ISO 27001:2022 Clause 6.1 | Planning and risk treatment | (nicht hier — siehe `isms-specialist`) |
| NIS2 Art. 21(2) | EU regulatory risk requirements | (nicht hier — siehe `isms-specialist`) |
| DORA Art. 8-15 | Financial-sector ICT risk | (nicht hier — siehe `isms-specialist`) |