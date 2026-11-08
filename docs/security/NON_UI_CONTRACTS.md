# Verträge der Nicht-UI-Pfade (S6)

Jede Sicherheitseigenschaft, die die Wellen 3 und 5 (#1147, #1151, #1157, #1159–#1162) für
REST, Assistent (MCP), Import, Wiederherstellung, Export, Hintergrundjobs, Konsole und Quick-Fix
hergestellt haben, steht hier mit dem Test oder Gate, das sie dauerhaft belegt. Grünes CI heißt
damit: die Eigenschaft gilt. Eine neue API-Ressource, ein neues MCP-Tool, ein neuer Export oder
ein neuer Job fällt automatisch unter die Verträge; niemand muss dafür einen Test nachtragen.

Regel beim Ändern: Ein Test, der ein unsicheres Verhalten festschreibt, wird umgedreht, nicht
gelöscht (so war es bei `ApiTokenReadOnlyTest::ignoresNonApiPaths` und
`ApiTenantVoterTest::grantsEditForSameTenant`).

## Organisationsgrenze

| Invariante | Beleg |
|---|---|
| Ein Token der Organisation A sieht in keiner Collection einen Datensatz von B, bekommt B's Item nicht, kann es weder ändern noch löschen — für jede API-Ressource | `tests/Contract/CrossTenantApiContractTest` (Ressourcen werden aus den Entities ermittelt) |
| A's Datensatz lässt sich per IRI nicht mit einem Datensatz von B verknüpfen (jede Relation der Schreibgruppe) | `CrossTenantApiContractTest::ownRecordsCannotReferenceAnotherOrganisationsRecords` |
| Kein MCP-Tool liest, ändert oder entwirft auf einem Datensatz von B (alle Tools aus `tools/list`, alle Ressourcen und Entwurfs-Subjekte, Suche, Listen) | `tests/Contract/CrossTenantMcpContractTest` |
| Ein neuer Datensatz über die API gehört immer der Organisation des Schreibers | `ApiResourceContractTest::everyWritingResourcePinsTheOrganisation` (TenantAwareStateProcessor) |
| Jede API-Operation hat eine `security`-Regel, Item-Operationen stimmen über den Datensatz ab | `ApiResourceContractTest::everyOperationNamesItsSecurity` |
| Ein Token, dessen Nutzer die Organisation gewechselt hat, gilt nicht mehr | `tests/Security/ApiTokenBoundaryTest::aTokenOfAnotherOrganisationIsRefused` |
| Hintergrundcode (Commands, Jobs, Worker) liest keine Mandantendaten ohne Mandant: `findAll()` und `findBy()`/`findOneBy()` ohne `tenant`-, `id`- oder Relationsschlüssel | PHPStan-Regel `NoUnscopedTenantQueryInBackgroundRule` |
| Jobs lesen nur im Mandanten des Auftraggebers; Status und Download eines Jobs nur für den Auftraggeber (oder Super-Admin) | Gate 90 `check_job_id_access.py`, `AsyncJobVoter` |
| Das Audit-Log der Konsole trägt die Organisation des Datensatzes | `tests/Service/AuditLoggerTenantPinTest` |

## Token und Anmeldung

| Invariante | Beleg |
|---|---|
| Ein Token gilt nur auf `/api` und `/mcp`, öffnet keine Seite der Oberfläche (401) | `ApiTokenBoundaryTest::aTokenOpensNoPageOfTheWebUi` |
| Eine Token-Anfrage setzt kein Session-Cookie; ein mitgeschicktes Cookie wird ignoriert | `ApiTokenBoundaryTest::aTokenRequestLeavesNoSessionCookie`, `…aSessionCookieSentAlongWithATokenIsIgnored` |
| Deaktivierte Personen kommen weder per Token noch per Sitzung noch per Passwort hinein; Deaktivieren widerruft Tokens | `ApiTokenBoundaryTest` (4 Fälle) |
| Jede Firewall prüft den Kontostatus (`user_checker`) | `ApiTokenBoundaryTest::everyFirewallThatAuthenticatesChecksTheAccount` |
| Prüferrollen bekommen kein schreibendes Token; Lese-Token schreiben nirgends (405) | `ApiTokenBoundaryTest::aReviewerGetsNoWritingToken`, `ApiTokenReadOnlyTest` |

## Rechte wie im Formular

| Invariante | Beleg |
|---|---|
| Schreibrollen der API = Rollen des Formulars (Methoden- und Klassen-`#[IsGranted]`, `denyAccessUnlessGranted()`); Kernressourcen (Asset, Risiko, Vorfall, Control) entscheiden auf der API mit demselben Datensatz-Voter wie das Formular; jede schreibbare Ressource ist einem Formular zugeordnet | `tests/Api/ApiWriteRoleParityTest` |
| Lifecycle-Status ist über die API nicht schreibbar | `ApiResourceContractTest::lifecycleStatusIsReadOnlyOnTheApi` |
| Freigabe- und Meldenachweise (`#[ApiReadOnlyEvidence]`) sind über die API nicht schreibbar | `ApiResourceContractTest::approvalEvidenceIsNotWritableOverTheApi` |
| Ein API-schreibbares Feld, dessen Name nach Nachweis aussieht (`*At`, `*By`, `approved*`, `*Notified` …), ist markiert, steht im Formular oder ist mit Grund geprüft | `tests/Api/ApiEvidenceFieldNamesTest` |
| Ein Konstruktor-Vorgabewert passt zum Spaltentyp (sonst 500 beim Anlegen über REST/MCP ohne das Feld) | `tests/Contract/EntityDefaultsMatchColumnTypesTest` |
| Jede Ressource nennt ihre Serialisierungsgruppen; keine Zugangsdaten (Passwort, MFA, Token, Schlüssel, SSO-Kennung) in einer Gruppe | `ApiResourceContractTest::everyResourceNamesItsSerializationGroups`, `…noCredentialIsInASerializationGroup` |
| Modulfelder sind nur bei aktivem Modul schreibbar (auch Felder aus Traits), Modul-Ressourcen nur bei aktivem Modul erreichbar | Gate 89, `tests/Api/ApiRouteModuleGateTest` |
| MCP schreibt nur mit Schreib-Token, Organisationsschalter und Nicht-Prüferrolle; jedes nicht lesende Tool ist als solches klassifiziert, und ein als lesend erklärtes Tool enthält keinen schreibenden Aufruf | `tests/Mcp/McpServerTest`, `tests/Mcp/McpToolClassificationTest`, `tests/Controller/McpControllerTest` |
| PUT ersetzt nicht, ausgelassene Felder behalten ihren Wert | `tests/Api/ApiPutSemanticsTest` |

## Wiederherstellung, globale Daten, Quick-Fix

| Invariante | Beleg |
|---|---|
| Eine präparierte Organisationssicherung vergibt keine privilegierte Rolle (alle `RoleNames::PRIVILEGED`, auch in anderer Schreibweise), kein Passwort, keine SSO-Kennung, keine Holding-Stellung, keine Audit-Log-Zeilen | `tests/Service/OrganisationRestoreTenantGuardTest` |
| Fremde Organisationen und Instanzzeilen bleiben bei der Wiederherstellung unberührt | `OrganisationRestoreTenantGuardTest::craftedOrganisationBackupCannotReachOtherOrganisationsOrInstanceData` |
| ZIP-Pfade bleiben im Archiv, Dateien erst nach dem Commit, kein html/svg/js | `tests/Service/BackupArchivePathTest`, `BackupArchiveExtractionTest` |
| Eine Holding-Sicherung ist für den Admin einer Tochter unsichtbar | `tests/Service/BackupServiceTest` |
| Nach einer Wiederherstellung werden Erfüllungswerte neu berechnet | Gate 85 |
| Globale Mappings und mitgelieferte Workflows schreibt bei mehreren Organisationen nur der Super-Admin | `tests/Security/Voter/GlobalCatalogueVoterTest`, `tests/Controller/MappingQualityControllerTest` |
| Quick-Fix lässt geteilte Zeilen ohne Organisation in Ruhe | `tests/Controller/QuickFixControllerTest`, `tests/Service/DataIntegrity/OrphanFinderTest` |
| Identitätswechsel nur auf Konten mit weniger Rechten | `tests/Security/Voter/SwitchUserVoterTest` |
| Aufbewahrung löscht nur Datensätze im Endzustand | `tests/Service/RetentionEnforcementServiceTest` |

## Exporte und abgeleitete Werte

| Invariante | Beleg |
|---|---|
| Jede Tabellenzelle mit Datensatztext läuft durch `CsvSanitizer` — geprüft je Schreibstelle, auch in `src/Command/` | Gate 84 `check_csv_formula_injection.py` |
| Erfüllungswerte schreibt nur `FulfillmentDerivationService`, auch nicht per Property-Name oder SQL | Gate 85 `check_fulfillment_single_writer.py` |
| Controls hinter einer Anforderung nur aus der eigenen Organisation | Gate 86 |
| Risikostufen aus der Skala der Organisation des Datensatzes | Gate 87, `tests/Risk/RiskScaleOfRecordOrganisationTest` |
| Exporte und Downloads werden protokolliert; Datenschutz-Exporte nur für DSB oder Manager | `DownloadAuditSubscriber`, `tests/Security/PrivacyExportAccessTest` |

Gegenprobe: mit abgeschaltetem Organisationsfilter (`TenantFilter`) werden 25 der Vertragstests rot; die Tests prüfen außerdem, dass der eigene Datensatz in Liste und Suche auftaucht, damit eine leere Antwort nicht als Erfolg zählt.

Selbsttests der Gates 84, 85, 89 und 90: `scripts/quality/tests/test_s6_contract_gates.py`.
