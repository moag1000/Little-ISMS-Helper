---
name: project-schema-driven-import
description: "Bulk-import ist schema-driven — neue Entity = ein deklarativer ImportSchemaProvider, kein bespoke Mapper."
metadata: 
  node_type: memory
  type: project
  originSessionId: 8524ab1e-7912-42ad-be81-bb1c9410a3e9
---

Bulk-Import (PR #881 + #884, 2026-06-04) ist metadaten-getrieben, nicht mehr bespoke. **Neuen Entity-Import hinzufügen = EINE Klasse** unter `src/Service/Import/Schema/Provider/<Entity>ImportSchemaProvider.php` implementiert `ImportSchemaProviderInterface` (auto-getaggt `app.import.schema_provider`).

Bausteine:
- `ImportFieldSpec` — name, setter, type (string/text/int/float/bool/date/enum/list/relation), required, **module** (Gate), enumValues, relationClass+relationLookup, unique.
- `EntityImportSchema` — entityType, entityClass, fields[], **module** (whole-entity gate).
- `SchemaDrivenMapper` — generisch: validiert, castet, resolved FK by-name (findOneBy[lookup]), **gated jedes Feld per is_module_active**. Wird on-demand von `ImportSchemaRegistry` gebaut.
- `EntityMapperRegistry` konsultiert die SchemaRegistry nach den (jetzt nur noch TisaxRequirement-) bespoke Mappern.

26 Entities live. Wiring pro neuem Entity: (1) Provider-Klasse, (2) Slug in Route-Requirement `BulkImportController` `{entityType}`, (3) Slug in `ImportHubController::GROUPS`, (4) `import.entity.<slug>` + `entity_type.<slug>` i18n de+en.

REGELN beim Provider-Bau (sonst Laufzeit-Bruch):
- setter muss EXAKT existieren (property = lcfirst(substr(setter,3))).
- relationLookup MUSS ein echtes #[ORM\Column] sein (kein computed getter) — sonst bricht findOneBy. Person→fullName, User→email, Asset→name, Control→controlId.
- module-gated Felder spiegeln die `if($this->isModuleActive('X'))`-Blöcke des FormType.
- status (lifecycle) NIE importieren; M:N-Collections ohne Single-Lookup skippen.
- ISMS-Acronym: toPascalCase('isms_context') wird per regex zu 'ISMSContext' gefixt.

Sicherheitsnetz: `ImportSchemaIntegrityTest` prüft JEDES Feld (Setter/Relation-Column/Enum/Unique real) — 936 Assertions. [[project_wizard_manual_checks]]
