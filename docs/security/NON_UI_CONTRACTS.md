# Verträge der Nicht-UI-Pfade (S6)

Jede Sicherheitseigenschaft, die die Wellen 3, 5, 6 und 7 (#1147, #1151, #1157, #1159–#1162, S7, W3, S11) für
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
| Word-Exporte escapen jeden Text (kein XML-/Feld-Einschleusen wie `INCLUDEPICTURE` mit UNC-Pfad); Seiten geben Datensatztext nie als HTML aus | Gate 92 `check_raw_output.py`, `DocxDocumentFactory`, `tests/Service/Export/DocxExportServiceTest::titleAndBodyAreEscapedInTheXml`, `tests/Twig/OutputEscapingTest` |
| Das Audit-Log der Konsole trägt die Organisation des Datensatzes — auch ohne Pin: Reihenfolge Pin → Kontext → Datensatz (`AuditLogger::aboutRecord()`) → Person; Ablauf von Vier-Augen-Anfragen und Einwilligungs-Sync protokollieren je Organisation | `tests/Service/AuditLoggerTenantPinTest` |
| Benachrichtigungs-Mails gehen nur an Konten der Organisation (`RecipientFilter`) und tragen nur Kurzfelder (`NotificationMailSummary`), nie den ganzen Datensatz | `tests/Service/Notification/Channel/EmailChannelTest` |
| Der Aktivitäts-Feed zeigt Audit-Zeilen nur Admins | `tests/Service/ActivityFeedTest::aNonAdminSeesNoAuditRows` |
| `/lifecycle/*` nur angemeldet; erlaubte Übergänge und Übergänge nur für Datensätze der eigenen Organisation (sonst 404) | `tests/Controller/LifecycleControllerHistoryTest` |
| Ein Reifegrad (MRIS Ist/Soll, Branchen-Baseline-Soll) gehört der Organisation: er liegt an ihrer Erfüllungszeile, nie an der gemeinsamen Katalog-Anforderung; Schreiben in A ändert B nicht. Code liest und schreibt ihn nur über `RequirementMaturity` (S18) | `tests/Contract/MaturityPerOrganisationContractTest`, `tests/Security/RequirementMaturityAccessTest`, `tests/Service/MrisMaturityServiceTest`, Flush-Backstop `GlobalMappingWriteGuardTest::maturityOnASharedRequirementIsRefusedWithoutTheRight` |
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
| Ausgehende Anfragen an URLs, die eine Person eingibt (Geocoder, OIDC-Discovery/JWKS/Token, Webhook), laufen nur über `App\Http\UserUrlHttpClient`: keine privaten/internen Adressen (auch nach DNS), keine Weiterleitungen; kein anderer roher HTTP-Client in `src/` (auch kein Guzzle, PSR-18, curl, Socket, SOAP oder Web-Push-SDK) | `tests/Security/OutboundUserUrlClientTest` |
| Web-Push: ein Abo nimmt nur https-Endpunkte der Browser-Push-Dienste an (`PushEndpointPolicy`); ein fremdes Abo übernimmt nur der Browser mit dessen Schlüsseln; Zustellung über `UserUrlHttpClient` (PSR-18), nie über den Client der Bibliothek | `tests/Security/WebPushEndpointTest` |
| Hochgeladene Tabellen öffnet nur `SafeSpreadsheetReader`: Reader-Typ fest (xlsx/xls/ods nach Aufrufer), ZIP-Grenzen vor dem Entpacken, Lesefilter auf Zeilen und Spalten; kein `IOFactory::createReaderForFile()`/`load()` in `src/` | `tests/Security/SpreadsheetUploadReaderTest` |
| Wer einen zweiten Faktor hat, erreicht ohne ihn keine Seite – über jeden Authenticator der Web-Firewall, „Angemeldet bleiben“, eine zweite Anmeldung in derselben Sitzung, die Neuanmeldung mit Passwort und eine Sitzung ohne Anmeldeereignis (fail closed). Der Zustand gehört zur Anmeldung und zur Person (`MfaSessionState`), das Remember-me-Cookie kommt erst nach dem Code und verfällt mit jedem neuen oder entfernten Gerät, `/setup` ist nach der Einrichtung zu, ein TOTP-Code zählt einmal | `tests/Security/Mfa/MfaLoginPathsTest` (neuer Authenticator ohne Entscheidung = rot), `tests/Security/Mfa/MfaBypassRegressionTest`, `MfaServiceTest::aTotpCodeCountsOnlyOnce`, Gate 91 |
| Jede SSO-Anmeldung prüft ihre Claims über eine Regel (`SsoClaimPolicy`): OIDC-ID-Token mit Issuer, `exp`, Audience und `nonce` Pflicht, UserInfo ändert weder `sub` noch signierte Claims; Azure (env) nur aus den konfigurierten Verzeichnissen (`tid`); ein bestehendes Konto wird nur mit verifizierter E-Mail und nur auf Wunsch verknüpft; SAML nimmt nur Antworten auf eigene Anfragen, jede Assertion einmal, Entity-ID/ACS aus `DEFAULT_URI` statt Host-Header (`SamlMessageGuard`); Abmelden braucht ein CSRF-Token | `tests/Security/Sso/SsoClaimContractTest` (neuer Authenticator ohne Vertrag = rot), Gate 93 |
| Die SSO-Discovery-Prüfung braucht CSRF und gibt keine Verbindungsdetails zurück (kein Port-Scan-Orakel) | `tests/Controller/Api/SsoDiscoveryApiControllerTest` |
| Abmelden leert den Browser: `Clear-Site-Data: "cache", "storage"` auf jeder Firewall mit Logout; der Service Worker speichert nur feste öffentliche Build-Dateien, nie eine Seite, `.json`, API-Antwort oder Download, nichts mit `no-store`/`private` (S16) | `tests/Security/Logout/ClearSiteDataOnLogoutTest`, `tests/js/service_worker.test.js` |

## Rechte wie im Formular

| Invariante | Beleg |
|---|---|
| Schreibrollen der API = Rollen des Formulars (Methoden- und Klassen-`#[IsGranted]`, `denyAccessUnlessGranted()`); Kernressourcen (Asset, Risiko, Vorfall, Control) entscheiden auf der API mit demselben Datensatz-Voter wie das Formular; jede schreibbare Ressource ist einem Formular zugeordnet | `tests/Api/ApiWriteRoleParityTest` |
| Lifecycle-Status ist über die API nicht schreibbar | `ApiResourceContractTest::lifecycleStatusIsReadOnlyOnTheApi` |
| Wirksamkeits-, Test- und Meldenachweise (Control, BC-Übung, BC-Plan, Ziel-Istwert, Behördenmeldung) sind `#[ApiReadOnlyEvidence]`; die Entdeckungszeit eines Vorfalls wird nur beim Anlegen gesetzt | `ApiResourceContractTest::approvalEvidenceIsNotWritableOverTheApi`, `…theDetectionTimeOfAnIncidentIsSetOnceOnCreate` |
| Kommentieren darf nur, wer die Detailseite öffnen darf: Modul und Datensatz-Voter der Zielentität (`CommentController::TARGET_ACCESS`), aus den Show-Controllern abgeleitet | `tests/Security/CommentTargetAccessTest` |
| Ein Berichts-Widget liefert nur bei aktivem Modul Daten (Designer, Vorschau, PDF); Fehlertexte gehen ins Log, nicht an den Client; Berichtsstile sind auf bekannte Schlüssel und Hex-Farben begrenzt (PDF-`<style>`) | `tests/Security/ReportWidgetModuleTest`, `tests/Security/ReportStylesTest` |
| Ein Lifecycle-Übergang, der einen Datensatz abschließt (z. B. TIA `assess`), ist nie für `ROLE_USER` offen — nur Arbeitsschritte (start, submit, triage …) oder begründete Ausnahmen | Gate 82 `check_workflow_roles.py` |
| Jede regulatorische Freigabekette liefert ihre Schritte aus der YAML (`metadata.steps`, ein Layout für Loader, Gate 82 und Overlay-Editor); jede `approver_role` existiert (oder `owner`); die Datenbankzeilen, aus denen laufende Freigaben den Freigeber lesen, gleichen der YAML (`RegulatoryWorkflowMirror`, Quick-Fix-Abgleich, nachts). Vor S17 las der Loader eine andere Stelle und fand keinen Schritt, Gate 82 prüfte keinen | `tests/Workflow/RegulatoryWorkflowRegistryTest`, Gate 82 `check_workflow_roles.py` |
| Gespeicherte JSON-Bodies (Dashboard-Layout, Berichtsdefinition) sind größen- und tiefenbegrenzt | `DashboardLayoutController::MAX_LAYOUT_BYTES`, `ReportBuilderController::MAX_SAVE_BYTES` |
| Ein Import, der bestehende Datensätze aktualisiert (auch Delta-Import), überschreibt keine Nachweisfelder (`EvidenceFieldFilter::forUpdate()`); ein neu angelegter Datensatz bringt auch keine mit (`forCreate()`, S11) — nur `importable` markierte Ist-Werte wie der Umsetzungsstand eines Controls | `tests/Service/Import/EvidenceFieldFilterTest` |
| Der Umsetzungsstand eines Controls (`implementationStatus`, `implementationPercentage`) speist die Erfüllung und ist über REST/MCP nur lesbar | `ApiResourceContractTest::approvalEvidenceIsNotWritableOverTheApi`, `EvidenceFieldFilterTest::theImplementationStatusIsApiReadOnlyButImportable` |
| Ein Asset wird nur im Endzustand `disposed` gelöscht (oder in den ersten 24 h als Korrektur) — Formular, Massenlöschung und API (`DeletionPolicy::refusal()`) | `tests/Lifecycle/DeletionPolicyTest` |
| Prüferrollen ändern nichts im Audit-Bereich außer Checkliste, Feststellungen, Arbeitsmappe und Berichtseinreichung; der AVV-Picker braucht Manager | `tests/EventSubscriber/ReadOnlyRoleSubscriberTest` |
| Maschinenprotokolle (Krypto-Operationen, Zutrittslog) schreibt über die API nur ein Admin | `ApiTenantVoter::WRITE_ROLES`, `tests/Api/ApiWriteRoleParityTest` |
| Freigabe- und Meldenachweise (`#[ApiReadOnlyEvidence]`) sind über die API nicht schreibbar | `ApiResourceContractTest::approvalEvidenceIsNotWritableOverTheApi` |
| Ein API-schreibbares Feld, dessen Name nach Nachweis aussieht (`*At`, `*By`, `approved*`, `*Notified` …), ist markiert oder mit Grund geprüft (auch Unterordner von `src/Entity`); ein markiertes Feld ist in keinem Formular der Klasse frei editierbar (alle FormTypes mit dieser `data_class`, nicht nur `<Entity>Type`; Ausnahmen mit Grund in `FORM_EDITABLE_EVIDENCE`, S12) | `tests/Api/ApiEvidenceFieldNamesTest` |
| S12: Freigabe-, Umsetzungs-, Prüf- und Abschlussnachweise schreibt der Lebenszyklus-Schritt, der sie erzeugt (`LifecycleEvidenceStamper`: Änderungsantrag approve/implement/verify/close, Vorfall close, BC-Übung complete → „zuletzt getestet“ der getesteten Pläne); „Bericht abgeschlossen“ einer Übung ist abgeleitet (`BCExercise::deriveReportCompleted()`) | `tests/Lifecycle/EventListener/LifecycleEvidenceStamperTest`, `tests/Controller/ChangeRequestControllerTest::testEditIgnoresPostedApprovalEvidence` |
| S12: Meldezeitpunkte eines Vorfalls (NIS-2 Art. 23, DORA Art. 19) setzt nur „Als gemeldet markieren“ — Manager oder Verantwortliche, Zeitpunkt nicht in der Zukunft und nicht vor der Erkennung | `tests/Controller/IncidentReportingMarkTest` |
| S12: Die Risikoakzeptanz einer Auslagerung durch die Leitung (`Supplier::boardLevelRiskAcceptance`, MaRisk AT 4.3) setzt nur eine gebilligte Leitungsentscheidung vom Typ `supplier_risk` der eigenen Organisation | `tests/Service/ManagementDecision/ManagementDecisionServiceTest`, `tests/Controller/SupplierBoardAcceptanceTest` |
| S12: Begründung und Ablauf einer Risikoakzeptanz kommen nur mit dem Antrag (Ablaufdatum Pflicht, höchstens 36 Monate); solange die Akzeptanz steht, ändert kein Schreibweg die Restbewertung (Formular schreibgeschützt, REST/MCP `AcceptedRiskUnchanged`); Verlängerung oder Neubewertung beendet die Akzeptanz und geht als neuer Antrag an die Stufe; die Behandlungsentscheidung gibt „Behandlung freigeben“ frei (wer/wann aus der Anmeldung) | `tests/Validator/AcceptedRiskUnchangedValidatorTest`, `RiskAcceptanceWorkflowServiceTest::reevaluationEndsTheStandingAcceptanceBeforeTheNewRequest`, `RiskControllerTest::testEditIgnoresPostedApprovalEvidence`, `ApiEvidenceFieldNamesTest::frozenFieldsAreReadOnlyInTheirFrozenState` |
| Ein Konstruktor-Vorgabewert passt zum Spaltentyp (sonst 500 beim Anlegen über REST/MCP ohne das Feld) | `tests/Contract/EntityDefaultsMatchColumnTypesTest` |
| Jede Ressource nennt ihre Serialisierungsgruppen; keine Zugangsdaten (Passwort, MFA, Token, Schlüssel, SSO-Kennung) in einer Gruppe | `ApiResourceContractTest::everyResourceNamesItsSerializationGroups`, `…noCredentialIsInASerializationGroup` |
| Modulfelder sind nur bei aktivem Modul schreibbar (auch Felder aus Traits), Modul-Ressourcen nur bei aktivem Modul erreichbar | Gate 89, `tests/Api/ApiRouteModuleGateTest` |
| MCP schreibt nur mit Schreib-Token, Organisationsschalter und Nicht-Prüferrolle; jedes nicht lesende Tool ist als solches klassifiziert, und ein als lesend erklärtes Tool enthält keinen schreibenden Aufruf und schreibt beim Aufruf nichts (Doctrine-Spion) | `tests/Mcp/McpServerTest`, `tests/Mcp/McpToolClassificationTest`, `tests/Mcp/McpReadOnlyToolsWriteNothingTest`, `tests/Controller/McpControllerTest` |
| PUT ersetzt nicht, ausgelassene Felder behalten ihren Wert | `tests/Api/ApiPutSemanticsTest` |

| Rollen-Obergrenze: niemand vergibt, entzieht oder verwaltet eine Rolle über der eigenen; Konzernrollen (jede `ROLE_GROUP_*`/`ROLE_KONZERN_*` der Hierarchie) nur der Super-Admin; Leitung nie an sich selbst; Konten über der Obergrenze nur lesbar (Passwort, E-Mail, MFA) — Formular, Sammelaktion, CSV-Import, SSO-Mapping | `tests/Security/RoleCeilingTest` (echte `role_hierarchy`), `tests/Security/UserAdministrationGuardTest`, `tests/Security/Voter/UserVoterTenantScopeTest::accountsAboveTheActorsRoleAreViewOnly`, `tests/Controller/UserImportExistenceOracleTest` |
| SSO-Anmeldung vergibt nur bekannte, nicht privilegierte Rollen und pflegt nur die Rollen des Anbieters; das Mapping ist eine Auswahlliste | `tests/Service/Sso/OidcAuthenticationFlowRoleSyncTest`, `tests/Controller/Admin/SsoAccountTakeoverTest` |
| API-Tokens laufen höchstens 365 Tage, entstehen nur nach voller Anmeldung und werden bei Rollenwechsel widerrufen | `tests/Service/ApiTokenManagerTest`, `tests/Security/ApiTokenBoundaryTest` |
| SAML-Antworten gelten nur im Browser, der die Anmeldung begonnen hat (Bindungs-Cookie, Login-CSRF) | `tests/Security/Sso/SsoClaimContractTest::samlRefusesUnsolicitedAndUnknownResponses` |

## Wiederherstellung, globale Daten, Quick-Fix

| Invariante | Beleg |
|---|---|
| Eine präparierte Organisationssicherung vergibt keine privilegierte Rolle (alle `RoleNames::PRIVILEGED`, auch in anderer Schreibweise), kein Passwort, keine SSO-Kennung, keine Holding-Stellung, keine Audit-Log-Zeilen | `tests/Service/OrganisationRestoreTenantGuardTest` |
| Fremde Organisationen und Instanzzeilen bleiben bei der Wiederherstellung unberührt | `OrganisationRestoreTenantGuardTest::craftedOrganisationBackupCannotReachOtherOrganisationsOrInstanceData` |
| ZIP-Pfade bleiben im Archiv, Dateien erst nach dem Commit, kein html/svg/js | `tests/Service/BackupArchivePathTest`, `BackupArchiveExtractionTest` |
| Eine Holding-Sicherung ist für den Admin einer Tochter unsichtbar | `tests/Service/BackupServiceTest` |
| Nach einer Wiederherstellung werden Erfüllungswerte neu berechnet | Gate 85 |
| Globale Mappings und mitgelieferte Workflows schreibt bei mehreren Organisationen nur der Super-Admin | `tests/Security/Voter/GlobalCatalogueVoterTest`, `tests/Controller/MappingQualityControllerTest` |
| Den geteilten Katalog (globale Mappings und ihre Lücken, Anforderungen ohne Upload-Organisation, Frameworks) ändert bei mehreren Organisationen nur der Super-Admin – auch über Mapping-Seeds, Loader-Fixer, Beispieldaten-Kommandos und Branchen-Presets; jeder schreibende Weg eines Controllers, der Katalogzeilen anlegt, ist gesperrt oder mit Grund gelistet; der onFlush-Backstop fängt den Rest (S13) | `tests/Security/InstanceScopeRoutesTest::sharedCatalogueWritesNeedTheCatalogueRight` (findet Routen und Schreib-Services selbst), `tests/EventListener/GlobalMappingWriteGuardTest` |
| Instanzbetrieb (Schema-Update der gemeinsamen Datenbank, composer, Cache, Logs löschen, Fehlerlog aller Organisationen, Performance) nur für den Super-Admin: die Instanz-Berechtigungen (`PermissionVoter::instancePermissions()`: MONITORING_*, BACKUP_RESTORE) gibt weder die ROLE_ADMIN-Rückfallregel noch eine eigene Rolle (S13) | `tests/Security/InstanceScopeRoutesTest::instanceOperationsNeedTheSuperAdmin` (jede Route unter /admin/monitoring und jede mit Instanz-Service), `PermissionVoterTest::testInstancePermissionsAreSuperAdminOnly` |
| Quick-Fix lässt geteilte Zeilen ohne Organisation in Ruhe | `tests/Controller/QuickFixControllerTest`, `tests/Service/DataIntegrity/OrphanFinderTest` |
| Identitätswechsel nur auf Konten mit weniger Rechten | `tests/Security/Voter/SwitchUserVoterTest` |
| Aufbewahrung löscht nur Datensätze im Endzustand, und die Frist läuft ab Abschluss bzw. letzter Änderung (Abschlussfelder, `updatedAt`, letzte Audit-Zeile), nicht ab Anlage | `tests/Service/RetentionEnforcementServiceTest` |
| Eine Instanz-Wiederherstellung glaubt `metadata.tenant_scope` der Datei nicht: nur Organisationen im aktuellen Baum der ersten genannten (oder hier unbekannte) — eine präparierte Liste leert keine fremde Organisation | `tests/Service/Restore/RestoreDataPurgerTest::a_recorded_scope_is_cut_to_the_roots_tree` |
| ZIP-Sicherungen tragen jede Nachweis-Ablage (`ProtectedUploads::AREAS`, auch Schulungsmaterial); der Verwaist-Scan sieht `var/uploads/<Bereich>` und kennt Schulungsmaterial, damit es nicht in Quarantäne geht | `BackupArchiveExtractionTest::everyProtectedUploadAreaIsBackedUp`, `tests/Service/DataIntegrity/UploadOrphanCheckerTest` |
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
| Links, die die Sitzung verlassen (Mails, Lieferantenfragebogen, Trust Center, QR-Labels), kommen aus `DEFAULT_URI` über `App\Http\PublicUrlGenerator`, nie aus dem Host-Header; `SYMFONY_TRUSTED_HOSTS` weist fremde Host-Header ab (400); Mail-Vorlagen bauen keine Links mit `url()` | `tests/Security/PublicLinksFromDefaultUriTest` (findet absolute URLs in `src/` selbst), Health-Seite meldet fehlende Werte |
| Jede Export- und Download-Aktion trägt ein `#[IsGranted]` (Aktion oder Controller) | `tests/Security/ExportRoutesGatedTest` (findet die Routen selbst) |
| Webhooks gehen nur signiert raus (HMAC, `X-Signature`); ein Kanal ohne Geheimnis sendet nichts; geteilte URLs (PWA) nur mit http(s) | `WebhookChannelTest::unsignedWebhookIsNotSent`, `NotificationChannelTypeTest`, `tests/Security/ShareTargetUrlTest` |
| Das Docker-Image arbeitet jede Messenger-Warteschlange ab, an die Nachrichten geroutet sind (Mails über `async`) | `tests/Deployment/DockerWorkerConsumesMailQueueTest` |
| Jede Tabellenzelle mit Datensatztext läuft durch `CsvSanitizer` — geprüft je Schreibstelle, auch in `src/Command/` | Gate 84 `check_csv_formula_injection.py` |
| Erfüllungswerte schreibt nur `FulfillmentDerivationService`, auch nicht per Property-Name oder SQL | Gate 85 `check_fulfillment_single_writer.py` |
| Controls hinter einer Anforderung nur aus der eigenen Organisation | Gate 86 |
| Risikostufen aus der Skala der Organisation des Datensatzes | Gate 87, `tests/Risk/RiskScaleOfRecordOrganisationTest` |
| Exporte und Downloads werden protokolliert; jeder Datenschutz-Export (VVT, DSFA, Datenpanne, Betroffenenanfrage, Einwilligung, Behörden-VVT) trägt dieselbe Regel `PrivacyExportAccess::RULE` (DSB oder Manager) | `DownloadAuditSubscriber`, `tests/Security/PrivacyExportAccessTest` (findet die Routen selbst) |

Gegenprobe: mit abgeschaltetem Organisationsfilter (`TenantFilter`) werden 25 der Vertragstests rot; die Tests prüfen außerdem, dass der eigene Datensatz in Liste und Suche auftaucht, damit eine leere Antwort nicht als Erfolg zählt.

Mutationsgegenprobe aller Dauertests und Gates (T, Welle 7): `scripts/README.md` § Mutationsgegenprobe.

Selbsttests der Gates 84, 85, 89 und 90: `scripts/quality/tests/test_s6_contract_gates.py`.
