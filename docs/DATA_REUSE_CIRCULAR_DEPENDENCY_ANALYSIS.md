# Data Reuse - Circular Dependency Analysis

**Datum:** 2025-11-10
**Status:** Phase 6 Planung - Zirkelschluss-Prüfung

## Ziel

Identifikation und Vermeidung von logischen Zirkelschlüssen in den geplanten Data Reuse Beziehungen, um zu verhindern:
- Endlosschleifen in Auto-Berechnungen
- Inkonsistente Daten
- Implementierungsprobleme
- Unvorhersehbares System-Verhalten

---

## ⚠️ Identifizierte potenzielle Zirkelschlüsse

### 1. Asset Classification ↔ Risk Assessment (KRITISCH)

**Geplante Beziehung (Phase 6F):**
```
Asset.dataClassification ← Risk.assessment (High-Risk Assets → "confidential")
Risk.impact ← Asset.dataClassification (confidential Assets → höherer Impact)
```

**Zirkel:**
```
Asset.classification → Risk.impact → Risk.riskValue →
  "High Risk" → Asset.classification = "confidential" →
  Risk.impact (erhöht) → LOOP!
```

**Problem:**
- Feedback-Loop: Jede Neubewertung erhöht die Classification, die dann den Risk erhöht, usw.
- Keine Konvergenz garantiert

**Lösung: Einseitige Ableitung mit Manual Override**
```php
// ✅ SICHER: Nur initiale Auto-Suggestion, keine Auto-Update
// Asset.dataClassification wird NUR vorgeschlagen, nicht automatisch gesetzt
// User muss manuell bestätigen

class AssetService {
    public function suggestDataClassification(Asset $asset): string {
        $highRiskCount = $this->getHighRiskCountForAsset($asset);

        // Nur Suggestion, kein automatisches Setzen!
        if ($highRiskCount >= 3) {
            return 'confidential'; // Suggestion only
        }

        return $asset->getDataClassification(); // Existing value
    }
}

// Im Asset Form: "Suggested Classification: confidential (based on 3 High Risks)"
// User kann annehmen oder ablehnen
```

**Akzeptanzkriterium UPDATE:**
- ~~Auto-Ableitung~~ → **Suggestion-Only** mit manuellem Approval
- Kein automatisches Setzen von Asset.classification
- UI zeigt Suggestion mit Begründung

---

### 2. Risk Probability ← Incident History → Risk Assessment (MODERAT)

**Geplante Beziehung (Phase 6F):**
```
Risk.probability ← Incident.count (Incidents erhöhen Probability)
Incident.severity ← Risk.riskValue (Hohe Risks → höhere Incident Severity)
```

**Zirkel:**
```
Risk.probability → Risk.riskValue → Incident.severity →
  Neue Incidents → Risk.probability (erhöht) → LOOP?
```

**Problem:**
- Historische Incidents beeinflussen Risk Probability
- Aber die Incident-Bewertung selbst könnte vom Risk beeinflusst worden sein
- Feedback-Loop über Zeit

**Lösung: Temporal Decoupling + One-Way Influence**
```php
// ✅ SICHER: Nur historische Incidents beeinflussen Risk
// Neue Risk.probability beeinflusst NICHT rückwirkend alte Incidents

class RiskService {
    public function adjustProbabilityBasedOnIncidents(Risk $risk): void {
        // Nur abgeschlossene, historische Incidents zählen
        $historicalIncidents = $risk->getIncidents()
            ->filter(fn($i) => $i->getStatus() === 'closed')
            ->filter(fn($i) => $i->getClosedAt() < new \DateTime('-30 days'));

        $incidentCount = count($historicalIncidents);

        // Probability Adjustment (nur nach oben, nie nach unten)
        if ($incidentCount > 0) {
            $currentProbability = $risk->getLikelihood();
            $adjustedProbability = min(5, $currentProbability + ceil($incidentCount / 3));

            // Nur erhöhen, nie reduzieren (unidirektional)
            if ($adjustedProbability > $currentProbability) {
                $risk->setLikelihood($adjustedProbability);
                $risk->addNote("Probability adjusted due to {$incidentCount} historical incidents");
            }
        }
    }
}
```

**Safe Guards:**
1. **Temporal Decoupling:** Nur Incidents älter als 30 Tage zählen
2. **One-Way:** Probability kann nur erhöht werden, nie reduziert (via Incidents)
3. **Manual Reset:** User kann manuell Probability reduzieren (z.B. nach Mitigation)
4. **Audit Trail:** Jede Adjustment wird geloggt

**Akzeptanzkriterium UPDATE:**
- Nur **historische** Incidents (>30 Tage alt, Status=closed) beeinflussen Risk
- **One-Way Adjustment:** Nur Erhöhung, keine automatische Reduktion
- **Audit Log:** Jede Probability-Änderung dokumentiert

---

### 3. Vulnerability → Risk ↔ Asset ↔ Vulnerability (KOMPLEX)

**Geplante Beziehung (Phase 6H):**
```
Vulnerability → Risk (Auto-Erstellung)
  Risk.impact = CVSS.impact * Asset.monetaryValue
Asset ↔ Vulnerability (Many-to-Many)
  Asset.vulnerabilityScore = sum(Vulnerabilities.cvssScore)
Asset.monetaryValue ← (potentiell) Asset.vulnerabilityScore?
```

**Potentieller Zirkel:**
```
Vulnerability → Risk.impact (via Asset.monetaryValue) →
  Asset.vulnerabilityScore → Asset.monetaryValue (wenn implementiert) →
  Risk.impact → LOOP!
```

**Problem:**
- Wenn Asset.monetaryValue von Asset.vulnerabilityScore abhängt
- Dann haben wir einen Zirkel

**Lösung: Asset.monetaryValue ist IMMER manuell gesetzt**
```php
// ✅ SICHER: monetaryValue ist NIEMALS auto-berechnet

class Asset {
    /**
     * @ORM\Column(type="decimal", precision=15, scale=2, nullable=true)
     *
     * WICHTIG: Dieser Wert ist IMMER manuell gesetzt.
     * ER DARF NICHT automatisch aus vulnerabilityScore abgeleitet werden.
     * Dies würde einen Zirkel mit Risk.impact erzeugen.
     */
    private ?string $monetaryValue = null;

    /**
     * Auto-berechnet aus Vulnerabilities (READ-ONLY)
     */
    public function getVulnerabilityScore(): float {
        return array_sum(
            array_map(
                fn($v) => $v->getCvssScore(),
                $this->getVulnerabilities()->toArray()
            )
        );
    }
}

// Asset.monetaryValue wird NIEMALS von vulnerabilityScore beeinflusst
// vulnerabilityScore ist rein informativ (Dashboard, KPI)
```

**Safe Guards:**
1. **monetaryValue ist IMMER manuell:** Kein Auto-Setter
2. **vulnerabilityScore ist READ-ONLY:** Nur Getter, kein Setter
3. **Separate Concerns:** monetaryValue = Business Value, vulnerabilityScore = Security Risk
4. **Dokumentation:** Klare Code-Kommentare gegen zukünftige Zirkel

**Akzeptanzkriterium UPDATE:**
- Asset.monetaryValue ist **IMMER manuell gesetzt** (niemals auto-berechnet)
- Asset.vulnerabilityScore ist **READ-ONLY** (nur Getter)
- **Klare Separation:** monetaryValue = Business Value, vulnerabilityScore = Security Metric

---

### 4. Control Effectiveness Loop (KOMPLEX, aber SICHER)

**Beziehung:**
```
Patch → Control.effectiveness
Control → Risk.mitigation
Risk → Vulnerability (via CVSS → Risk.impact)
Vulnerability → Patch
```

**Scheinbarer Zirkel:**
```
Patch → Control.effectiveness → Risk.mitigationStatus →
  (keine direkte Rückwirkung auf Vulnerability oder Patch)
```

**Analyse:**
- Dies ist KEIN echter Zirkel, sondern ein **Lifecycle:**
  1. Vulnerability entdeckt
  2. Risk erstellt (basierend auf CVSS + Asset.monetaryValue)
  3. Control implementiert (Patch)
  4. Patch-Geschwindigkeit → Control Effectiveness (KPI)
  5. Control → Risk.status = "mitigated"
  6. Risk geschlossen (aber Vulnerability bleibt historisch)

**Safe Guards (bereits implizit):**
1. **Temporal Flow:** Vulnerability → Patch ist zeitlich geordnet
2. **Status-basiert:** Gepatchte Vulnerabilities ändern Status, kein Loop
3. **Metrics sind Snapshots:** Control Effectiveness ist historische Metrik, keine Live-Berechnung

**Akzeptanzkriterium:**
- ✅ KEIN Zirkel vorhanden
- Control Effectiveness ist **Snapshot-basiert** (monatlich berechnet)
- Vulnerability Status verhindert Loop (open → patched = final state)

---

## ✅ Sichere Beziehungen (kein Zirkel)

### Phase 6F
- ✅ **Asset Monetary Value → Risk Impact** (einseitig, manueller Input)
- ✅ **Asset ↔ Control** (Many-to-Many, keine Auto-Berechnung)
- ✅ **Risk Treatment Plan → Control** (einseitig)
- ✅ **BusinessProcess ↔ Risk** (einseitig: BIA.rto/rpo → Risk.priority)

### Phase 6G
- ✅ **AuditorCompetence ↔ Training** (einseitig: Training → Competence)
- ✅ **RiskCommunication ↔ Risk** (Many-to-Many, keine Auto-Berechnung)
- ✅ **ICTThirdPartyProvider ↔ Risk** (einseitig: TPP → Risk)
- ✅ **TISAXAssessment ↔ Asset** (einseitig: Assessment → Asset.assessmentLevel)

### Phase 6H
- ✅ **Incident ↔ Asset** (Many-to-Many, keine Auto-Berechnung)
- ✅ **Incident Timeline → Notification** (einseitig)
- ✅ **Vulnerability ↔ Incident** (Many-to-Many, keine Auto-Berechnung)
- ✅ **Patch → Control** (einseitig: Patch-Speed → Control Effectiveness)

### Phase 6I
- ✅ **CryptographicKey ↔ Asset** (Many-to-Many, keine Auto-Berechnung)
- ✅ **CryptographicKey ↔ Control** (einseitig: Key Rotation → Control Score)
- ✅ **CryptographicKey → Notification** (einseitig)
- ✅ **PenetrationTest → Vulnerability** (einseitig, zeitlich geordnet)

### Phase 6K
- ✅ **Training ↔ Control** (Many-to-Many, keine Auto-Berechnung)
- ✅ **Training ↔ ComplianceRequirement** (Many-to-Many, keine Auto-Berechnung)

---

## 🛡️ Safe Guard Prinzipien für Data Reuse

### 1. **Einseitige Ableitungen bevorzugen**
```
✅ A → B (B wird aus A berechnet, aber A ändert sich nicht durch B)
❌ A ↔ B (beide beeinflussen sich gegenseitig)
```

### 2. **Manual Override für kritische Auto-Berechnungen**
```php
// ✅ SICHER: Auto-Suggestion, kein Auto-Set
public function suggestValue(): mixed;

// ❌ UNSICHER: Auto-Set ohne Manual Approval
public function autoSetValue(): void;
```

### 3. **Temporal Decoupling**
```php
// ✅ SICHER: Nur historische Daten beeinflussen
$historicalData = $collection->filter(fn($x) => $x->getDate() < now() - 30days);

// ❌ UNSICHER: Live-Daten beeinflussen sich gegenseitig
$allData = $collection;
```

### 4. **One-Way Adjustments**
```php
// ✅ SICHER: Nur Erhöhung, keine Auto-Reduktion
if ($newValue > $currentValue) {
    $this->setValue($newValue);
}

// ❌ UNSICHER: Bidirektionale Auto-Adjustments
$this->setValue($newValue); // kann erhöhen ODER reduzieren
```

### 5. **READ-ONLY Computed Properties**
```php
// ✅ SICHER: Berechnete Werte ohne Setter
public function getComputedValue(): float {
    return $this->calculate();
}

// Kein setComputedValue()!
```

### 6. **Clear Separation of Concerns**
```php
// ✅ SICHER: Business Value vs. Technical Metric
private ?string $monetaryValue = null;  // Business (manual)
public function getVulnerabilityScore(): float; // Technical (computed)

// ❌ UNSICHER: Beide beeinflussen sich
private ?string $value = null; // Was ist das? Business oder Technical?
```

---

## 📋 Aktualisierte Akzeptanzkriterien für Phase 6

### Phase 6F: ISO 27001 Inhaltliche Vervollständigung

**Geänderte Kriterien:**
- [ ] **Data Reuse:** Asset.dataClassification **Suggestion-Only** (kein Auto-Set)
- [ ] **Data Reuse:** Risk Probability Adjustment **One-Way** (nur Erhöhung)
- [ ] **Data Reuse:** Risk Probability **Temporal Decoupling** (nur historische Incidents)

### Phase 6H: NIS2 Directive Compliance

**Geänderte Kriterien:**
- [ ] **Safe Guard:** Asset.monetaryValue **IMMER manuell** (niemals auto-berechnet)
- [ ] **Safe Guard:** Asset.vulnerabilityScore **READ-ONLY** (nur Getter)
- [ ] **Documentation:** Code-Kommentare gegen zukünftige Zirkel

---

## 🔄 Implementierungs-Checkliste

Für jede Data Reuse Beziehung:

- [ ] Prüfe: Ist die Beziehung einseitig oder bidirektional?
- [ ] Falls bidirektional: Gibt es einen potenziellen Zirkel?
- [ ] Falls Zirkel: Welches Safe Guard Prinzip wird angewendet?
  - [ ] Manual Override?
  - [ ] Temporal Decoupling?
  - [ ] One-Way Adjustment?
  - [ ] READ-ONLY Properties?
- [ ] Code-Review: Sind Safe Guards im Code dokumentiert?
- [ ] Test: Edge Cases für potenzielle Loops getestet?

---

## 📊 Zusammenfassung

| Beziehung | Zirkel-Risiko | Safe Guard | Status |
|-----------|---------------|------------|--------|
| Asset Classification ← Risk | ⚠️ HOCH | Suggestion-Only | ✅ Gelöst |
| Risk Probability ← Incident | ⚠️ MODERAT | Temporal + One-Way | ✅ Gelöst |
| Vulnerability → Risk ↔ Asset | ⚠️ MODERAT | monetaryValue = Manual | ✅ Gelöst |
| Control Effectiveness Loop | ✅ NIEDRIG | Lifecycle + Status | ✅ Sicher |
| Alle anderen (20+) | ✅ KEIN RISIKO | Einseitig / Many-to-Many | ✅ Sicher |

**Ergebnis:**
- 3 potenzielle Zirkel identifiziert
- Alle 3 durch Safe Guards gelöst
- 20+ sichere Beziehungen bestätigt

---

**Stand:** 2025-11-10
**Nächster Review:** Nach Phase 6F Implementation
