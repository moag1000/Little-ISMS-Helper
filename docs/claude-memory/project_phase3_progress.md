---
name: project_phase3_progress
description: "Stand der \"alle offenen F (außer 42)\"-Abarbeitung — Tech-Debts + Phase-3 done, Rest offen."
metadata: 
  node_type: memory
  type: project
  originSessionId: 5f89d394-84e9-4154-94a3-bf677b9fc53d
---

Goal (Stop-hook, 2026-06-05): alle offenen Feature-Wünsche außer F42 abarbeiten +
Tech-Debts aus Phasen lösen + gegen Anforderung prüfen + verdrahten + Translations.

**ERLEDIGT + in main:**
- Tech-Debts (#883): F7 API-Gate (`#[ApiProperty(security:)]` auf sensible Risk-Felder + Parität-Test), F46 Import (auf #884-SchemaProvider konvergiert + aliases), F30 xBRL-CSV-Exporter (`DoraRoiCsvExporter` + Trait + Route).
- F39 ENISA EUVD (#885): `Vulnerability.inEuvd/euvdId` + Migration + `EuvdConnector`/`EuvdSyncService` (enrichment-only, CVE-match, tenant-safe) + Command + Scheduler-Message/Handler (daily 05:00) + Badge.
- F20 DOCX/MD (#886): `DocxExportService` (PhpWord, markdown→docx) + **eigener** `DocumentExportController` (nicht in god-class DocumentController!).
- F38 Policy-Pack (#887): `PolicyPackAdapter` export+parse/validate (read-only, curated-library) + `PolicyPackController`.
- F35 EUCS (#888): `app:load-eucs` Loader (20 Control-Kategorien, FrameworkLoaderInterface-Muster, nur Codes+Titel).
- F6 read-only API (#889, CI): ApiToken-Entity (SHA-256-Hash) + ApiTokenAuthenticator (in MAIN-Firewall, supports() Bearer-gated — KEIN separater ^/api-Firewall sonst bricht session-API) + ReadOnlyApiSubscriber (non-GET+Bearer→405) + `app:api-token:create` + Gate-43-Exclude. Lehre: neue Entity → Gate 43 (PRODUCTIVE/EXCLUDED), Repo/Connector/Authenticator nicht `final` (sonst nicht mockbar), AbstractAuthenticator-Return-Types narrowen (bool/Response) für phpstan.

- F-NEU ICT-Provider-Library (#890, CI): `IctProviderLibrary` global-Katalog (mirror IndustryBaseline) + `app:load-ict-provider-library` (19 Provider/8 Kategorien) + `IctProviderLibraryService::buildSupplier()` (unpersisted, curated-governance) + Gate-43-Exclude. Follow-up: "apply from library"-Button im Supplier-Create-Flow. Lehre: Supplier ist lifecycle-managed → setStatus() verboten (LifecycleService).

**PHASE 3 KOMPLETT** (F39/F20/F38/F35/F6/F-NEU + Tech-Debts).

- F21 MCP-Server (#891, CI): `CatalogMcpServer` (JSON-RPC 2.0: initialize/tools/list/tools/call, read-only) + `app:mcp-server` stdio + 2 Tools (list_frameworks/list_requirements). Lehre: `\RuntimeException` trippt Gate check_no_generic_throws → `App\Exception\InvalidArgument\InvalidArgumentException` nutzen.

- F23 Supplier-Questionnaire-outbound (#892, CI): SupplierQuestionnaire-Entity + Service (createAndSend/submitResponse) + PublicSupplierQuestionnaireController (token-gated, locale-frei via routes.yaml-exclude+separater-import wie F43) + Admin-Controller + base_public-Templates + Gate-43. **Public-Route-Lehre:** neue Public-Controller MÜSSEN in config/routes.yaml app_routes-exclude + separater no-prefix-resource-import, sonst kriegen sie /{_locale}/ (Gate 8 double_locale_prefix). Admin-Routes KEIN /{_locale} (auto-prefixed).

**PHASE 3+4 KOMPLETT.**

**GOAL ABGESCHLOSSEN (2026-06-05):** Alle autonom-baubaren offenen F erledigt + gemerged + **verdrahtet**.
Tech-Debts + F39/F20/F38/F35/F6/F-NEU (Phase 3) + F21 (MCP) + F23 (Phase 4) = 12 PRs (#883,#885-892,#894,#896), alle grün.
Verdrahtung (#894 F23-Mega-Menü, #896 F-NEU-Apply-Button/F6-Token-UI-`/profile/api-tokens`/F38-Export-Button).
Alle Features erreichbar: Landing+Nav (F30/F39/F20/F35/F23/F-NEU/F6/F38) oder bewusst CLI-only (F21-MCP, F35-Loader, F7-Security-Layer).
**Nav-Lehre:** neuer Mega-Menü-Link braucht (1) Eintrag in _mega_menu_panel_only, (2) Prefix im _nav_map-Resolver in _mega_menu.html.twig (Gate nav_area_parity), (3) nav.de/en-Labels. Controller mit #[CurrentUser] stört Gate check_currentuser_test_args → `getUser()` nutzen. Mega-Menü-Edits kollidieren leicht zwischen parallelen Branches → entweder sequenziell mergen oder via Index-Page-Button statt Mega-Menü verlinken.

**USER-DEFERRED (nicht autonom baubar, 2026-06-05 Entscheid "Jetzt überspringen"):**
- F24 EBIOS-RM (XL) — braucht Workshop/PoC (anderes Risk-Modell, Design-Entscheidungen).
- F9 i18n FR/IT/ES/NL/PT-BR (L) — reines Übersetzungsprojekt, Profi-Übersetzung extern.
- F22 Local-LLM/Ollama (L) — braucht Alva-LLM-Foundation + Infra-Entscheid (Modell/Hosting/Datenschutz).
F42 (Continuous-Compliance-Connector) + F47 (Asset-Auto-Discovery, an F42 gekoppelt) bleiben separat.

**Bekannte pre-existing flaky Tests (NICHT meine):** ScheduledTaskServiceTest::testUpdateTaskUpdatesCronExpression (DateTime-assertEquals identischer Objekte, timing/PHP-8.5) + TisaxImportWizardE2ETest — failen sporadisch auf main, passen auf CI-Re-run.
Phase 5: F24 (EBIOS-RM, XL — Workshop nötig), F9 (i18n FR/IT/ES/NL/PT-BR, L), F21 (MCP-Server, M), F22 (Local-LLM, L).
F42 + F47 bewusst ausgeschlossen.

**Gate-Lehre (wichtig für künftige Adds):** Entity-Feld-Adds shiften baselined Zeilen →
Gate 46 (setter_nullability), 27 (em_writes), 42 (god_class). Bei großen Controllern
(DocumentController) NEUE kleine Controller statt anhängen. Sonst Baseline re-linen
(`--write-baseline`, Diff = nur Zeilennummer prüfen). Branches off aktuellem origin/main
nehmen sonst translation_nesting-Konflikte beim Merge. Siehe [[feedback_gate26_line_baseline_shift]].

**Scope-Map für Rest:** PolicyTemplate identity=`getKey()`, body via Translation-Keys.
Vulnerability source-enum hat jetzt SOURCE_EUVD. API-Platform 4.3, session-auth (kein Token → F6 braucht ApiToken-Entity + Authenticator). ComplianceFramework via Load*Command-Pattern (F35 mirror LoadEuAiActFullCommand). ICT-Provider-Library mirror IndustryBaseline-Pattern.
