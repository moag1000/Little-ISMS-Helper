---
name: project-person-picker-gap
description: "GESCHLOSSEN 2026-06-07 — Person-Picker-Lücke abgearbeitet. Control war schon fertig; 6 Entities (ISMSObjective/InterestedParty/Location/Patch/Supplier/Vulnerability) auf Dual-State-Picker umgestellt."
metadata: 
  node_type: memory
  type: project
  originSessionId: 8524ab1e-7912-42ad-be81-bb1c9410a3e9
---

Der Person-Picker / Person-Relation-Umbau (OwnerPickerFormTrait + EntityType statt
Freitext) wurde als „abgeschlossen" gemeldet, deckt aber **nicht alle** Entities ab.
Beim Drawer-Piloten (Location, 2026-06-06) fiel auf: `Location.responsiblePerson`
ist noch `TextType` (Freitext). Audit aller FormTypes ergab die Lücke.

**Bereits umgestellt** (Picker/Relation): die 8 OwnerPickerFormTrait-Forms
(Training, AuditFinding, Risk, BusinessProcess, Asset, BusinessContinuityPlan,
Incident, ProcessingActivity) + EntityType-Forms (CorrectiveAction, CustomReport,
DataBreach, DPIA, DataSubjectRequest, RiskTreatmentPlan, ThreatIntelligence).

**NOCH FREITEXT (TextType) — müssen umgestellt werden:**
- `ControlType` → `responsiblePerson` (Z.192)
- `ISMSObjectiveType` → `responsiblePerson` (Z.116)
- `InterestedPartyType` → `contactPerson` (Z.69)
- `LocationType` → `responsiblePerson` (Z.154)
- `PatchType` → `responsiblePerson` (Z.159)
- `SupplierType` → `contactPerson` (Z.66)
- `VulnerabilityType` → `responsiblePerson` (Z.186)

**Umbau pro Entity = Datenmodell-Change** (nicht nur Form): String-Feld →
`ManyToOne Person` (oder Dual-State wie [[project_form_pattern_a_deferred]]:
String-Wert behalten + `*PersonUser`-Relation, effective-Accessor im Template),
+ Migration, + Freitext-Bestandsdaten mappen/backfillen, + `_fa_person_picker`
bzw. EntityType im Form, + SectionMap-Eintrag. CorrectiveAction/RiskTreatmentPlan
zeigen das Dual-State-Muster (`responsiblePersonUser` + `responsiblePerson`).

**ERLEDIGT 2026-06-07 (Sweep):**
- `ControlType` war bereits voll umgestellt (Memory war stale) — übersprungen.
- 6 Entities auf Dual-State umgestellt (Muster exakt von Control kopiert):
  je `<base>PersonUser` (User-FK), `<base>PersonRef`/`<base>Ref` (Person-FK),
  `<base>DeputyPersons` (M2M), Legacy-String behalten, `OwnerResolver`-basierte
  `getEffective*`/`getAll*`-Accessor. Form: 3 EntityType + Legacy-TextType +
  SectionMap. ISMSObjective (war NotBlank=required) → `validateResponsibleSlot`
  Callback (User ODER Person ODER Freitext). Andere 5 optional (kein Validator).
- EINE konsolidierte Migration `Version20260630100000_PersonPickerSweep`
  (`isTransactional()=false`, plain ALTER/CREATE, keine PREPARE/EXECUTE).
- Show-Templates: rohes Legacy-Feld → `effective*`-Accessor.
- i18n de+en je Domain. **Falle:** Domain-Root-Key ist oft SINGULAR trotz
  Plural-Dateiname (location/patch/supplier/vulnerability/interested_party);
  Form-Label-Prefix MUSS Root-Key matchen sonst roher Key. LocationType-Agent
  hatte `locations.` statt `location.` — gefixt.
- 4 Baselines rebaselined (reine Line-Shifts durch Key/Feld-Insert):
  translation_nesting, freetext_legacy (Legacy-Person-Felder bleiben bewusst
  baselined wie Control), setter_nullability, notblank_on_not_null.

**Lehre Rollout-Verifikation:** bei solchen Sweeps Ist-Stand pro Entity grepen
statt Memory vertrauen (Control war längst fertig). Verwandt:
[[project_form_pattern_a_deferred]], [[project_form_pattern_b_deferred]].
