# KPI Verbesserungsplan

> Erstellt: 2026-04-17 | Basis: CISO + Implementer + Compliance Manager Review
> 10 KPIs hinzufuegen, 8 KPIs aendern, 8 KPIs demoten, 10 Thresholds anpassen

## Phase 1 — Bug Fixes (KRITISCH)
- [ ] C1: MTTR Divisor-Bug fixen (nur Incidents mit beiden Datumsfeldern zaehlen)
- [ ] C5: supplier_assessment_rate Default 100% → null/N/A
- [ ] C6: asset_classification_rate alle 3 CIA-Werte pruefen (nicht OR)

## Phase 2 — High-Value (HOCH)
- [ ] A1: Per-Framework Compliance % (aus ComplianceAnalyticsService surfacen)
- [ ] C4: Gewichtete control_compliance (implemented*1.0 + partial*0.5)
- [ ] C3: risk_treatment_rate nur mit echtem Treatment Plan zaehlen
- [ ] C7: Trend-Pfeile auf allen Prozent-KPIs (erfordert KPI-Snapshots)

## Phase 3 — Strategische KPIs (HOCH)
- [ ] A2: Risk Appetite Compliance im Haupt-Widget
- [ ] A3: Residual Risk Exposure KPI
- [ ] A4: ISMS Health Score (Composite)
- [ ] C2: MTTR nach Severity segmentieren
- [ ] R1-R4: Raw Totals zu Detail-Level demoten

## Phase 4 — Compliance Manager (MITTEL)
- [ ] A5: Control Reuse Ratio
- [ ] A6: Days Since Last Management Review
- [ ] A7: Oldest Overdue Item Age
- [ ] A8: Gap Count by Priority
- [ ] C8: Count-basierte Thresholds normalisieren
- [ ] Konfigurierbare Thresholds per Tenant

## Phase 5 — Nice-to-Have (NIEDRIG)
- [ ] A9: Regulatory Deadline Tracker
- [ ] A10: Implementation Readiness Checklist
- [ ] R5-R8: Weitere Demotions