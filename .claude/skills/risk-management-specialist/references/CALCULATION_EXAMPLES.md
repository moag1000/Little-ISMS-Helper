# Risk Calculation Worked Examples

Detailed calculation walkthroughs and scenario library. Loaded on demand by the `risk-management-specialist` skill.

## Example 1 — Residual Risk with Multiple Controls

**Inherent Risk:**
- Likelihood: 4 (Likely)
- Impact: 4 (Major)
- Inherent Risk Score: 4 × 4 = **16** (High)

**Apply Controls:**

| Control | Strength | Likelihood Reduction |
|---|---|---|
| C-001 (MFA enforced) | 60% | 4 → 2 |
| C-014 (DLP deployed) | 30% | (Impact 4 → 3) |
| C-027 (Awareness training) | Marginal | — |

**Combined Effectiveness Calculation:**

```php
// Multiplicative residual (bounded reduction)
$baseLikelihood = 4;
$controlEffects = [0.6, 0.0, 0.0]; // MFA only directly reduces likelihood
$combinedReduction = 1 - array_product(array_map(fn($e) => 1 - $e, $controlEffects));
// = 1 - (0.4 × 1.0 × 1.0) = 0.6 → 60% reduction
$residualLikelihood = max(1, round($baseLikelihood * (1 - $combinedReduction)));
// = max(1, round(4 × 0.4)) = 2
```

**Residual Risk:**
- Residual Likelihood: 2
- Residual Impact: 3 (DLP impacts impact dimension)
- **Residual Risk Score: 2 × 3 = 6** (Low → acceptable per default `maxAcceptableRisk = 9`)

**Audit-Pflicht-Doku:** Speicher `riskScoreHistory` mit Pre/Post-Werten + Justification (`Risk.likelihoodJustification`, `Risk.impactDescription`).

---

## Example 2 — Incident-Driven Likelihood Adjustment

**Scenario:** Phishing risk on email systems.

**Initial Risk:**
- Likelihood: 3 (Possible)
- Impact: 4 (Major)
- Risk Score: 3 × 4 = 12 (Medium)

**Incident History (12 months):**
- 3 successful phishing attempts → likelihood validated as actual

**Adjusted Risk:**
- Likelihood: 4 (Likely) ← upgrade based on incident frequency
- Impact: 4 (Major) ← unchanged
- **Updated Risk Score: 4 × 4 = 16** (High → re-evaluation triggered)

**Justification Audit-Trail (`Risk.likelihoodJustification`):**
> "Likelihood von 3 auf 4 erhöht aufgrund 3 erfolgreicher Phishing-Vorfälle in 12 Monaten (INC-2025-103, INC-2025-156, INC-2026-022). Behandlung: zusätzliche E-Mail-Awareness-Maßnahmen + Phishing-Simulator (Q2/2026)."

**Daten-Reuse:** `IncidentRepository::countRelatedToRisk($risk->getId(), '-12 months')` automatisiert die Häufigkeits-Berechnung.

---

## Example 3 — AssetRiskCalculator Aggregation (47/100 → 85/100)

`AssetRiskCalculator` aggregiert über CIA-Score + zugewiesene Risks + Incidents + implementierte Controls + Asset-Kritikalität.

**Asset:** "Customer-Database-Cluster" (PROD, kritisches Geschäft)

| Faktor | Roh-Wert | Gewichtung | Beitrag |
|---|---|---|---|
| Inherent CIA (C=5, I=5, A=4) | (5+5+4)/15 = 0.93 | 30% | 27.9 |
| Zugewiesene Risks (3 hoch, 2 mittel) | 0.74 | 20% | 14.8 |
| Letzte 12 Monate Incidents (5 betroffen) | 0.50 | 15% | 7.5 |
| Implementierte Controls (12 of 18 = 67%) | 0.67 | 20% | 13.4 |
| Asset-Kritikalität (PROD, business-critical) | 1.00 | 15% | 15.0 |
| **Gesamt-Score** | | | **78.6 → 79/100** |

**Bei Verbesserung (z.B. weitere 3 Controls implementiert, 0 Incidents):**

| Faktor | Roh-Wert | Beitrag |
|---|---|---|
| CIA | 0.93 | 27.9 |
| Risks (jetzt 1 hoch, 4 mittel) | 0.55 | 11.0 |
| Incidents (0/12 Monate) | 0.00 | 0.0 |
| Controls (15/18 = 83%) | 0.83 | 16.6 |
| Kritikalität | 1.00 | 15.0 |
| **Gesamt-Score** | | **70.5 → 71/100** |

**Code-Pattern:**
```php
// In AssetRiskCalculator::calculate(Asset $asset): int
$ciaScore = ($asset->getConfidentialityScore() + $asset->getIntegrityScore()
            + $asset->getAvailabilityScore()) / 15;
$riskScore = $this->aggregateRisks($asset->getRisks());
$incidentScore = $this->calculateIncidentImpact($asset, '-12 months');
$controlCoverage = $this->calculateControlCoverage($asset);
$criticality = $asset->isProduction() ? 1.0 : 0.6;

return (int) round(
    $ciaScore * 30 +
    $riskScore * 20 +
    $incidentScore * 15 +
    $controlCoverage * 20 +
    $criticality * 15
);
```

---

## Scenario Library

### Cyber-Risk-Szenarien

| Szenario | Threat Source | Inherent (L×I) | Typische Controls |
|---|---|---|---|
| Ransomware via Phishing | External | 4×5=20 | A.6.3 Awareness, A.8.7 Anti-Malware, A.8.13 Backups |
| SQL-Injection auf Public-API | External | 3×5=15 | A.8.28 Secure Coding, A.8.29 Security Testing |
| Insider-Datenexfiltration | Internal (Disgruntled) | 3×5=15 | A.8.12 DLP, A.8.16 Monitoring, A.5.11 Asset Return |
| Credential-Stuffing | External (Opportunistic) | 4×4=16 | A.5.17 Auth-Info, A.8.5 Secure Auth (MFA) |
| Zero-Day-Exploit Backend | External (Targeted) | 2×5=10 | A.8.8 Vuln Mgmt, A.8.16 Monitoring |

### Supply-Chain-Szenarien

| Szenario | Threat Source | Inherent (L×I) | Controls |
|---|---|---|---|
| Cloud-Provider Outage | Technical (Vendor) | 2×4=8 | A.5.30 ICT Readiness, BCM-Plan |
| Vendor Data Breach | External (Vendor) | 3×4=12 | A.5.19 Supplier Security, A.5.21 Supply-Chain |
| Composer Supply-Chain Attack | External (Targeted) | 2×4=8 | `composer audit` + Trivy + SBOM |
| Sub-Processor Failure (DSGVO Art. 28) | Vendor | 2×4=8 | A.5.20 Agreements, DPA-Reviews |

### Operational-Szenarien

| Szenario | Threat Source | Inherent (L×I) | Controls |
|---|---|---|---|
| Datacenter Power Outage | Natural/Technical | 2×5=10 | A.7.11 Supporting Utilities, A.5.30 ICT Readiness |
| Key-Person Loss (Bus Factor) | Internal | 3×3=9 | A.6.1 Screening, BCM-Plan, Knowledge-Transfer |
| Backup-Restore Failure | Technical | 2×5=10 | A.8.13 Backups (Test-Restore-Quartal) |

---

## Risk-Acceptance Cost-Benefit Example

**Risk #19:** Legacy-System ohne MFA-Support, residual risk = 12 (medium).

**Kosten Treatment:**
- Custom-MFA-Bridge: EUR 80.000 + 2 Monate Engineering
- System-Replacement: EUR 250.000 + 9 Monate Migration

**Kosten Acceptance (Restrisiko):**
- ALE = 0.05 (5%) × EUR 200.000 (Breach-Loss) = EUR 10.000/Jahr
- 3 Jahre bis EOL → 30.000 EUR Erwartungswert

**Decision:**
- Treatment-ROI: (Reduzierung um 90%) × 30k = 27k Saving / 80k Invest = 34% → unterhalb Schwelle
- **Acceptance** mit:
  - Compensating Controls (network-segmentation, monitoring boost)
  - Re-Review halbjährlich
  - EOL fix bei 2027-Q4
  - Sign-off durch Risk-Owner + CISO (nach `RiskAppetite.maxAcceptableRisk` Eskalations-Matrix)

**Audit-Trail:** `RiskAcceptance` Entity mit:
```
acceptedBy: ciso@org
acceptedDate: 2026-04-29
businessJustification: "Custom-MFA-Bridge Aufwand übersteigt Restrisiko um Faktor 2.7. Acceptance mit kompensierenden Controls."
expiryDate: 2026-10-29  (6-Monats-Re-Review)
compensatingControls: ['C-NET-09', 'C-MON-04']
```