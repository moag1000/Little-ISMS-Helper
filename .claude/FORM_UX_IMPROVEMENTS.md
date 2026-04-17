# Form & Module UX Verbesserungen

> Erstellt: 2026-04-17 | Basis: Junior-Implementer + UX-Specialist Review
> 30 priorisierte Massnahmen, 4 Cross-Cutting Patterns

## Cross-Cutting Patterns (erst loesen, dann Einzel-Fixes)

### Pattern A: Free-Text Owner → EntityType(User)
7 Formulare nutzen Freitext statt User-Referenz:
- AssetType.owner, BCPlanType.planOwner, BusinessProcessType.processOwner
- ControlType.responsiblePerson, IncidentType.reportedBy
- RiskType.acceptanceApprovedBy, TrainingType.trainer

### Pattern B: Native Multi-Select → Select2
6 Felder mit potenziell 100+ Optionen ohne Suche:
- IncidentType.affectedAssets, BusinessProcessType.supportingAssets/identifiedRisks
- ControlType.protectedAssets, TrainingType.coveredControls/complianceRequirements

### Pattern C: Formulare ohne jeglichen Hilfetext
- BusinessContinuityPlanType (0/19 Felder)
- SupplierType (0/21 Felder)

### Pattern D: Fehlende Progressive Disclosure
- RiskType GDPR-Sektion, ProcessingActivityType Conditional Fields
- DataBreachType Notification Reason, DataSubjectRequestType Verification

## HIGH Priority (1-18)

| # | Datei | Problem |
|---|-------|---------|
| 1 | data_breach/index.html.twig | Hardcoded English in Headers/Filters/KPIs |
| 2 | incident/index.html.twig | Status-Mismatch in Overview Cards |
| 3 | BCPlanType.php | 0/19 Felder mit Hilfetext |
| 4 | SupplierType.php | 0/21 Felder mit Hilfetext |
| 5 | ProcessingActivityType.php | 38 Felder ohne Progressive Disclosure |
| 6 | RiskType.php | GDPR-Felder ohne Progressive Disclosure |
| 7 | IncidentType.php | Resolution-Felder bei Ersterfassung sichtbar |
| 8 | IncidentType.php | reportedBy Autofill |
| 9 | IncidentType.php | crossBorderImpact required bei Ersterfassung |
| 10 | AssetType.php | CIA 1-5 ohne Skalenerklaerung |
| 11 | AssetType.php | owner Freitext statt User |
| 12 | BusinessProcessType.php | RTO/RPO/MTPD ohne Erklaerung |
| 13 | BusinessProcessType.php | Impact-Skala 1-5 ohne Labels |
| 14 | TrainingType.php | coveredControls native select fuer 93+ |
| 15 | DataSubjectRequestType.php | requestType ohne Hilfetext |
| 16 | RiskType.php | treatmentStrategy required bei Ersterfassung |
| 17 | SupplierType.php | securityScore 0-100 ohne Methodik |
| 18 | asset/index.html.twig | Hardcoded "durchsuchen" |

## MEDIUM Priority (19-30)

| # | Datei | Problem |
|---|-------|---------|
| 19 | RiskType.php | acceptanceApprovedBy Freitext |
| 20 | RiskType.php | residual Help-Text identisch mit inherent |
| 21 | AssetType.php | location Freitext trotz Location Entity |
| 22 | AssetType.php | 3 Geldfelder ohne Differenzierung |
| 23 | BCPlanType.php | activationCriteria/recoveryProcedures required blockiert Entwuerfe |
| 24 | DataBreachType.php | noSubjectNotificationReason immer sichtbar |
| 25 | DataBreachType.php | severity vs riskLevel unklar |
| 26 | ProcessingActivityType.php | choice_label username statt Vollname |
| 27 | data_breach/index.html.twig | Status-Badges String-Manipulation statt Translation |
| 28 | incident/index.html.twig | Filter ohne aria-label |
| 29 | risk/index.html.twig | Doppelte Filter-Mechanismen |
| 30 | asset/index.html.twig | Asset-Typ-Namen nicht uebersetzt in Chart |