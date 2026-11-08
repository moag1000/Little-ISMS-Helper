# Verträge der Nicht-UI-Pfade (S6)

Jede Sicherheitseigenschaft, die die Wellen 3, 5 und 6 (#1147, #1151, #1157, #1159–#1162, S7, W3) für
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
| Das Audit-Log der Konsole trägt die Organisation des Datensatzes — auch ohne Pin: Reihenfolge Pin → Kontext → Datensatz (`AuditLogger::aboutRecord()`) → Person; Ablauf von Vier-Augen-Anfragen und Einwilligungs-Sync protokollieren je Organisation | `tests/Service/AuditLoggerTenantPinTest` |
| Benachrichtigungs-Mails gehen nur an Konten der Organisation (`RecipientFilter`) und tragen nur Kurzfelder (`NotificationMailSummary`), nie den ganzen Datensatz | `tests/Service/Notification/Channel/EmailChannelTest` |
| Der Aktivitäts-Feed zeigt Audit-Zeilen nur Admins | `tests/Service/ActivityFeedTest::aNonAdminSeesNoAuditRows` |
| `/lifecycle/*` nur angemeldet; erlaubte Übergänge und Übergänge nur für Datensätze der eigenen Organisation (sonst 404) | `tests/Controller/LifecycleControllerHistoryTest` |
| Die Trust-Seite nennt nur Frameworks, an denen die Organisation arbeitet | `tests/Service/PostureSnapshotServiceTest::aFrameworkTheOrganisationDoesNotWorkOnIsNotListed` |

## Token und Anmeldung

| Invariante | Beleg |
|---|---|
| Ein Token gilt nur auf `/api` und `/mcp`, öffnet keine Seite der Oberfläche (401) | `ApiTokenBoundaryTest::aTokenOpensNoPageOfTheWebUi` |
| Eine Token-Anfrage setzt kein Session-Cookie; ein mitgeschicktes Cookie wird ignoriert | `ApiTokenBoundaryTest::aTokenRequestLeavesNoSessionCookie`, `…aSessionCookieSentAlongWithATokenIsIgnored` |
| Deaktivierte Personen kommen weder per Token noch per Sitzung noch per Passwort hinein; Deaktivieren widerruft Tokens | `ApiTokenBoundaryTest` (4 Fälle) |
| Jede Firewall prüft den Kontostatus (`user_checker`) | `ApiTokenBoundaryTest::everyFirewallThatAuthenticatesChecksTheAccount` |
| Prüferrollen bekommen kein schreibendes Token; Lese-Token schreiben nirgends (405) | `ApiTokenBoundaryTest::aReviewerGetsNoWritingToken`, `ApiTokenReadOnlyTest` |
| Mitglieder einer deaktivierten Organisation kommen weder per Passwort, SSO noch Token hinein (`UserChecker::refusal()`; Super-Admin ausgenommen) | `tests/Security/UserCheckerOrganisationTest` |
| Fehlgeschlagene Token-Anmeldungen sind je IP gedrosselt (20 in 10 Minuten, dann 429 ohne Datenbankabfrage); das API-Limit gilt auch für `/api.json` u. ä. | `tests/Security/ApiTokenFailureThrottleTest`, `tests/EventSubscriber/RateLimiterWiringTest` |
| Ausgehende Anfragen an URLs, die eine Person eingibt (Geocoder, OIDC-Discovery/JWKS/Token, Webhook), laufen nur über `App\Http\UserUrlHttpClient`: keine privaten/internen Adressen (auch nach DNS), keine Weiterleitungen; kein anderer roher HTTP-Client in `src/` | `tests/Security/OutboundUserUrlClientTest` |
| Die SSO-Discovery-Prüfung braucht CSRF und gibt keine Verbindungsdetails zurück (kein Port-Scan-Orakel) | `tests/Controller/Api/SsoDiscoveryApiControllerTest` |

## Rechte wie im Formular

| Invariante | Beleg |
|---|---|
| Schreibrollen der API = Rollen des Formulars (Methoden- und Klassen-`#[IsGranted]`, `denyAccessUnlessGranted()`); Kernressourcen (Asset, Risiko, Vorfall, Control) entscheiden auf der API mit demselben Datensatz-Voter wie das Formular; jede schreibbare Ressource ist einem Formular zugeordnet | `tests/Api/ApiWriteRoleParityTest` |
| Lifecycle-Status ist über die API nicht schreibbar | `ApiResourceContractTest::lifecycleStatusIsReadOnlyOnTheApi` |
| Wirksamkeits-, Test- und Meldenachweise (Control, BC-Übung, BC-Plan, Ziel-Istwert, Behördenmeldung) sind `#[ApiReadOnlyEvidence]`; die Entdeckungszeit eines Vorfalls wird nur beim Anlegen gesetzt | `ApiResourceContractTest::approvalEvidenceIsNotWritableOverTheApi`, `…theDetectionTimeOfAnIncidentIsSetOnceOnCreate` |
| Ein Import, der bestehende Datensätze aktualisiert (auch Delta-Import), überschreibt keine Nachweisfelder (`EvidenceFieldFilter::forUpdate()`) | `tests/Service/Import/EvidenceFieldFilterTest` |
| Ein Asset wird nur im Endzustand `disposed` gelöscht (oder in den ersten 24 h als Korrektur) — Formular, Massenlöschung und API (`DeletionPolicy::refusal()`) | `tests/Lifecycle/DeletionPolicyTest` |
| Prüferrollen ändern nichts im Audit-Bereich außer Checkliste, Feststellungen, Arbeitsmappe und Berichtseinreichung; der AVV-Picker braucht Manager | `tests/EventSubscriber/ReadOnlyRoleSubscriberTest` |
| Maschinenprotokolle (Krypto-Operationen, Zutrittslog) schreibt über die API nur ein Admin | `ApiTenantVoter::WRITE_ROLES`, `tests/Api/ApiWriteRoleParityTest` |
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
| Aufbewahrung löscht nur Datensätze im Endzustand, und die Frist läuft ab Abschluss bzw. letzter Änderung (Abschlussfelder, `updatedAt`, letzte Audit-Zeile), nicht ab Anlage | `tests/Service/RetentionEnforcementServiceTest` |
| Wiederhergestellte Freigabe- und Nachweisfelder werden je Typ als `restore.evidence`-Batch protokolliert (Herkunft sichtbar); das Admin-Passwort der Wiederherstellung trifft nie „den ersten Nutzer“ | `tests/Service/Restore/RestoredEvidenceTrailTest` |
| Sicherungs-Metadaten werden größenbegrenzt gelesen; der Sichtbarkeitsbereich einer hochgeladenen Sicherung kommt vom Hochladenden, nicht aus der Datei | `BackupService::readBackupMetadata()`, `BackupService::recordUploadScope()` |
| Beispieldaten-Bereinigung nur mit `--tenant-id`, ohne rohes SQL über Organisationen | `src/Command/SampleDataPurgeCommand.php` |
| Quick-Fix ohne Anmeldung: Statusscan höchstens einmal pro Minute (`QuickFixStatusSnapshot`), „Migrationen anwenden“ nur bei offenen Migrationen; alles andere braucht Super-Admin oder Installer-Token | `tests/Service/QuickFixStatusSnapshotTest`, `tests/Controller/QuickFixControllerTest` |
| `/metrics` und die Anmeldeseite nennen keine Versionsnummer; der Screenshot-Nutzer bekommt außerhalb von dev/test ein Zufallspasswort | `tests/Controller/MetricsControllerTest` |
| Der Setup-Assistent ist nach Abschluss nur für den Super-Admin erreichbar, auf jeder Aktion (`SetupAccessChecker::ACCESS`) | `tests/Security/InstanceOperatorOnlyTest`, `EvidenceOutsideWebRootTest::everySetupControllerCarriesTheClassLevelGate` |
| Eine Wiederherstellung nimmt nur Entitäten des Sicherungsformats an (`BackupService::restorableEntityNames()`), fremde Namen wie `ApiToken` werden mit Warnung verworfen | `tests/Service/RestoreForeignEntityTest` |
| Ein globales Framework löscht (Kaskade über alle Organisationen) nur der Super-Admin (`ADMIN_GLOBAL_OP`) | `tests/Security/InstanceOperatorOnlyTest` |
| Nachweisdateien (Dokumente, Versionen, Schulungsmaterial) liegen unter `var/uploads`, nie unter `public/`; Download nur über eine voter-geprüfte, protokollierte Aktion | `tests/Service/Upload/ProtectedUploadsTest`, `tests/Controller/TrainingMaterialDownloadTest`, `tests/Security/EvidenceOutsideWebRootTest` |

## Exporte und abgeleitete Werte

| Invariante | Beleg |
|---|---|
| Jede Tabellenzelle mit Datensatztext läuft durch `CsvSanitizer` — geprüft je Schreibstelle, auch in `src/Command/` | Gate 84 `check_csv_formula_injection.py` |
| Erfüllungswerte schreibt nur `FulfillmentDerivationService`, auch nicht per Property-Name oder SQL | Gate 85 `check_fulfillment_single_writer.py` |
| Controls hinter einer Anforderung nur aus der eigenen Organisation | Gate 86 |
| Risikostufen aus der Skala der Organisation des Datensatzes | Gate 87, `tests/Risk/RiskScaleOfRecordOrganisationTest` |
| Exporte und Downloads werden protokolliert; jeder Datenschutz-Export (VVT, DSFA, Datenpanne, Betroffenenanfrage, Einwilligung, Behörden-VVT) trägt dieselbe Regel `PrivacyExportAccess::RULE` (DSB oder Manager) | `DownloadAuditSubscriber`, `tests/Security/PrivacyExportAccessTest` (findet die Routen selbst) |

Gegenprobe: mit abgeschaltetem Organisationsfilter (`TenantFilter`) werden 25 der Vertragstests rot; die Tests prüfen außerdem, dass der eigene Datensatz in Liste und Suche auftaucht, damit eine leere Antwort nicht als Erfolg zählt.

Selbsttests der Gates 84, 85, 89 und 90: `scripts/quality/tests/test_s6_contract_gates.py`.
