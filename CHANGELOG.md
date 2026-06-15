# Changelog

Alle wesentlichen Aenderungen an diesem Projekt werden in dieser Datei dokumentiert.
Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/).

## [3.12.0](https://github.com/moag1000/Little-ISMS-Helper/compare/v3.11.0...v3.12.0) (2026-06-14)


### Added

* **bsi:** CRT-corroboration of heuristic ISO↔BSI mappings (WS-5b stage 1) ([#926](https://github.com/moag1000/Little-ISMS-Helper/issues/926)) ([b1a5955](https://github.com/moag1000/Little-ISMS-Helper/commit/b1a5955e56b8aaa131b100bfe1b71c61fa32c2bd))
* **bsi:** ISO 27001 → BSI IT-Grundschutz gap (honest, guided, tour-ready) ([#924](https://github.com/moag1000/Little-ISMS-Helper/issues/924)) ([19792c0](https://github.com/moag1000/Little-ISMS-Helper/commit/19792c098e85aa685636ba0bb66148144b909477))
* **catalogue:** complete BSI IT-Grundschutz 2023 (Basis+Standard+Hoch, ~1816 Anforderungen from official XML) + fix obsolete Baustein IDs ([#946](https://github.com/moag1000/Little-ISMS-Helper/issues/946)) ([e88383c](https://github.com/moag1000/Little-ISMS-Helper/commit/e88383c772024ac3ffb0282eb8d906a9f4a05953))
* **compliance:** auto-apply panel verdicts at setup so quality grades + deprecations surface in the UI ([#956](https://github.com/moag1000/Little-ISMS-Helper/issues/956)) ([f31a7ba](https://github.com/moag1000/Little-ISMS-Helper/commit/f31a7ba1a7289a2974f2b9ad71f5b31e4056802f))
* **compliance:** catalog consistency gate + canonical FrameworkCode + alias-merge migration ([#963](https://github.com/moag1000/Little-ISMS-Helper/issues/963)) ([51eb285](https://github.com/moag1000/Little-ISMS-Helper/commit/51eb2854228b4b57f5c4647a5c7d525bc5a56622))
* **eucs:** full ENISA EUCS control catalogue (~120 controls) + registry surface ([#970](https://github.com/moag1000/Little-ISMS-Helper/issues/970)) ([252b8ef](https://github.com/moag1000/Little-ISMS-Helper/commit/252b8efe640456d17ebf8bfa373f8f61e97aa91c))
* **framework:** add DACH frameworks — Austrian NISG, Swiss revDSG + IKT-Minimalstandard (catalogues + mappings, registry-bound) ([#962](https://github.com/moag1000/Little-ISMS-Helper/issues/962)) ([ccae7a9](https://github.com/moag1000/Little-ISMS-Helper/commit/ccae7a965ef131500a44dd5d7be895cbb82c9fce))
* **gdpr:** additively load full Art.1-99 catalogue alongside thematic requirements ([#965](https://github.com/moag1000/Little-ISMS-Helper/issues/965)) ([a09d833](https://github.com/moag1000/Little-ISMS-Helper/commit/a09d8337cc306aea94416d81d5eb165f780d28ce))
* **iso27701:** additively load full Annex-A/B catalogue alongside prefixed requirements ([#967](https://github.com/moag1000/Little-ISMS-Helper/issues/967)) ([bdebe6d](https://github.com/moag1000/Little-ISMS-Helper/commit/bdebe6dfa77c7ba50694429b374c9a852d3db9bb))
* **mapping:** BSI-C5:2026↔ISO 27001 AI-expert-panel verdicts + completeness candidates ([#943](https://github.com/moag1000/Little-ISMS-Helper/issues/943)) ([8e1892c](https://github.com/moag1000/Little-ISMS-Helper/commit/8e1892c431fb377d5e04374f6a51ae01d0550634))
* **mapping:** BSI-C5:2026↔NIS2 AI-expert-panel verdicts + completeness candidates ([#944](https://github.com/moag1000/Little-ISMS-Helper/issues/944)) ([7d4c5b7](https://github.com/moag1000/Little-ISMS-Helper/commit/7d4c5b70fb0a99190c2dc0b91134c3c3952336f9))
* **mapping:** BSI-C5↔EUCS AI-expert-panel verdicts + completeness candidates ([#940](https://github.com/moag1000/Little-ISMS-Helper/issues/940)) ([0ddcf12](https://github.com/moag1000/Little-ISMS-Helper/commit/0ddcf121377547d9f3acdc7d137bcea2fbb6cb9a))
* **mapping:** DACH+EU Tier-A mapping quality — C5/GDPR/DORA/NIST (P3) ([#934](https://github.com/moag1000/Little-ISMS-Helper/issues/934)) ([8020515](https://github.com/moag1000/Little-ISMS-Helper/commit/802051540fb39d19160ffc62d793d8d490e4343b))
* **mapping:** depth pass — promote 198 panel-validated correspondences into operational mappings (multi-anchor) ([#947](https://github.com/moag1000/Little-ISMS-Helper/issues/947)) ([72b9620](https://github.com/moag1000/Little-ISMS-Helper/commit/72b9620526f04c69034c21d39eea53027a299fe3))
* **mapping:** depth pass round-2 — +63 validated multi-anchor correspondences ([#955](https://github.com/moag1000/Little-ISMS-Helper/issues/955)) ([80aa989](https://github.com/moag1000/Little-ISMS-Helper/commit/80aa9895bbfe03678ed18cf2d85025dc44e7342d))
* **mapping:** DORA RTS/ITS (Level-2) → ISO 27001 — audit-detail mapping depth ([#949](https://github.com/moag1000/Little-ISMS-Helper/issues/949)) ([75a7489](https://github.com/moag1000/Little-ISMS-Helper/commit/75a74898670c60cc26125cb372726ba74af08099))
* **mapping:** DORA↔ISO 27001 quality — ID-fix + AI-expert-panel verdicts ([#935](https://github.com/moag1000/Little-ISMS-Helper/issues/935)) ([b3c2a86](https://github.com/moag1000/Little-ISMS-Helper/commit/b3c2a865bada7dae1e20e42e9777f9e4962b933d))
* **mapping:** EU-AI-Act↔ISO 42001 quality — AI-expert-panel verdicts ([#937](https://github.com/moag1000/Little-ISMS-Helper/issues/937)) ([7ec44a4](https://github.com/moag1000/Little-ISMS-Helper/commit/7ec44a4b5c4ca9cfdc655caf312dc5658c5907a7))
* **mapping:** EU-CRA↔NIS2 quality — codes-guard + AI-expert-panel verdicts ([#936](https://github.com/moag1000/Little-ISMS-Helper/issues/936)) ([2dea2eb](https://github.com/moag1000/Little-ISMS-Helper/commit/2dea2eb6306c61800a3097c2f34b30b0abd23297))
* **mapping:** EUCS↔ISO 27001 AI-expert-panel verdicts + completeness candidates ([#939](https://github.com/moag1000/Little-ISMS-Helper/issues/939)) ([cf5e62f](https://github.com/moag1000/Little-ISMS-Helper/commit/cf5e62fb89df302ad7bbcf96e20e91a26dadbfc9))
* **mapping:** GDPR↔ISO 27018 AI-expert-panel verdicts + completeness candidates ([#941](https://github.com/moag1000/Little-ISMS-Helper/issues/941)) ([8169b63](https://github.com/moag1000/Little-ISMS-Helper/commit/8169b636bfaef25132107e30b192aeab25a93622))
* **mapping:** generalize mapping-quality pipeline (P2) ([#929](https://github.com/moag1000/Little-ISMS-Helper/issues/929)) ([372e237](https://github.com/moag1000/Little-ISMS-Helper/commit/372e237fbe675e50914042bc063277db5aecd660))
* **mapping:** KRITIS-DachG↔NIS2-UmsuCG AI-expert-panel verdicts + completeness candidates ([#942](https://github.com/moag1000/Little-ISMS-Helper/issues/942)) ([503e8ca](https://github.com/moag1000/Little-ISMS-Helper/commit/503e8ca9451e8779e0e5d02078b5b31276efa59e))
* **mapping:** NIS2-UmsuCG↔DORA AI-expert-panel verdicts + completeness candidates ([#945](https://github.com/moag1000/Little-ISMS-Helper/issues/945)) ([ed8d005](https://github.com/moag1000/Little-ISMS-Helper/commit/ed8d00579f412f8ed556cd90409021369f348fda))
* **mapping:** NIS2↔ISO 27001 quality — ID-fix + AI-expert-panel verdicts ([#938](https://github.com/moag1000/Little-ISMS-Helper/issues/938)) ([0728ab9](https://github.com/moag1000/Little-ISMS-Helper/commit/0728ab9fdf143d05a450a543e8f60605691b7686))
* **mapping:** panel-verdict quality evidence for 6 more mappings (DORA-RTS, TISAX↔Grundschutz, Grundschutz↔NIS2/C5, AI-Act↔NIS2/GDPR) ([#953](https://github.com/moag1000/Little-ISMS-Helper/issues/953)) ([7bdcd86](https://github.com/moag1000/Little-ISMS-Helper/commit/7bdcd868b3d68962cc265cdb1e523c9e0cc60158))
* **nis2:** additively load full Art.N catalogue alongside thematic requirements ([#966](https://github.com/moag1000/Little-ISMS-Helper/issues/966)) ([848b48d](https://github.com/moag1000/Little-ISMS-Helper/commit/848b48de8e2f0a7126eae6367c82522636355bbc))
* **nis2:** NIS2↔BSI mapping quality (P1) ([#928](https://github.com/moag1000/Little-ISMS-Helper/issues/928)) ([ca00638](https://github.com/moag1000/Little-ISMS-Helper/commit/ca00638513844504f566bb4df609b4d014928701))


### Fixed

* **compliance:** load DORA RTS/ITS via the framework registry so Level-2 mappings resolve ([#951](https://github.com/moag1000/Little-ISMS-Helper/issues/951)) ([049964f](https://github.com/moag1000/Little-ISMS-Helper/commit/049964f813ac84c079675ab6f88ea2210a321bed))
* **compliance:** registry-bind full C5/NIST catalogues so their mappings resolve ([#952](https://github.com/moag1000/Little-ISMS-Helper/issues/952)) ([6c8a83a](https://github.com/moag1000/Little-ISMS-Helper/commit/6c8a83aefac63fda575d3955c428431c7e21653c))
* **dora:** complete RTS/ITS Level-2 catalogue — add CTPP designation + oversight fees, correct OJ numbers ([#964](https://github.com/moag1000/Little-ISMS-Helper/issues/964)) ([e7501ca](https://github.com/moag1000/Little-ISMS-Helper/commit/e7501cae3c8caa3c1ed89f89e58f724eac031f25))
* **mapping:** correct framework-code mismatches so cross-framework mappings resolve at runtime ([#957](https://github.com/moag1000/Little-ISMS-Helper/issues/957)) ([7dc8dfd](https://github.com/moag1000/Little-ISMS-Helper/commit/7dc8dfd59a98154504db4e7065149cf5f6615e38))
* **modules:** enforce vulnerability_intel gate on Vulnerability + ThreatIntelligence controllers ([#921](https://github.com/moag1000/Little-ISMS-Helper/issues/921)) ([3b7b318](https://github.com/moag1000/Little-ISMS-Helper/commit/3b7b3181e94f7875eba178e29d6db91b6b387f7a))
* **quick-fix:** never drop Doctrine/Messenger infra tables on destructive force (B2) ([#948](https://github.com/moag1000/Little-ISMS-Helper/issues/948)) ([2325eac](https://github.com/moag1000/Little-ISMS-Helper/commit/2325eac9f40defb028ed7bfb97e1c45b48d3f77f))
* **quick-fix:** schema-repair hardening — 10 safety findings (QF-1..QF-10) ([#927](https://github.com/moag1000/Little-ISMS-Helper/issues/927)) ([19de2c3](https://github.com/moag1000/Little-ISMS-Helper/commit/19de2c3a3318e2524e4ba18f6e2f0c15c7cdc720))


### Changed

* **notifications:** observability logging for DomainEventNotifier rule engine ([#920](https://github.com/moag1000/Little-ISMS-Helper/issues/920)) ([f564582](https://github.com/moag1000/Little-ISMS-Helper/commit/f56458236a1e7023ec55ef484a47367e05694e4a))


### Documentation

* **catalogue:** single-source-of-truth map + deprecate superseded legacy loaders ([#954](https://github.com/moag1000/Little-ISMS-Helper/issues/954)) ([ecaa030](https://github.com/moag1000/Little-ISMS-Helper/commit/ecaa030fab4d1e84b9fb0a07bdbae410316ae6c8))
* **compliance:** decision-ready dangling-mapping inventory ([#969](https://github.com/moag1000/Little-ISMS-Helper/issues/969)) ([09c6c0a](https://github.com/moag1000/Little-ISMS-Helper/commit/09c6c0a73b66c59ddaf9f51a201ced134e758661))

## [3.11.0](https://github.com/moag1000/Little-ISMS-Helper/compare/v3.10.0...v3.11.0) (2026-06-07)


### Added

* **alva-ui:** focus-notice polish — regression net + filter-preserving clear + konzern-drift inherited view ([#897](https://github.com/moag1000/Little-ISMS-Helper/issues/897)) ([0169b21](https://github.com/moag1000/Little-ISMS-Helper/commit/0169b21a8029e7b4acb8490cdf3da711b6d114fe))
* **alva:** deep-link count-hints to exactly the entities they count ([#895](https://github.com/moag1000/Little-ISMS-Helper/issues/895)) ([7303490](https://github.com/moag1000/Little-ISMS-Helper/commit/7303490e50a38406244432488233e188113056a9))
* **api:** F6 read-only REST API via Bearer tokens (MVP) ([#889](https://github.com/moag1000/Little-ISMS-Helper/issues/889)) ([e802439](https://github.com/moag1000/Little-ISMS-Helper/commit/e80243965a04ed52977244663e89b86958c7bd8a))
* **compliance:** F35 EUCS framework loader ([#888](https://github.com/moag1000/Little-ISMS-Helper/issues/888)) ([0fd1d36](https://github.com/moag1000/Little-ISMS-Helper/commit/0fd1d3640fe80f18b80932ff1b64a47d9565b92e))
* **document:** F20 — DOCX + Markdown export ([#886](https://github.com/moag1000/Little-ISMS-Helper/issues/886)) ([9b68525](https://github.com/moag1000/Little-ISMS-Helper/commit/9b685252b7026bd40e73b783f30e4df225fbd8ea))
* **dora:** F-NEU curated ICT-provider library (DORA Art. 28) ([#890](https://github.com/moag1000/Little-ISMS-Helper/issues/890)) ([b86d8ba](https://github.com/moag1000/Little-ISMS-Helper/commit/b86d8badb5452c9ae07ce315d818bb3e45ee361e))
* **forms:** in-page drawer infra + Location pilot (P1) ([#905](https://github.com/moag1000/Little-ISMS-Helper/issues/905)) ([02c979e](https://github.com/moag1000/Little-ISMS-Helper/commit/02c979e50a31a491622dd22018e793fca15f4b23))
* **forms:** in-page drawer/modal for BCM domain (BCP, BCExercise, CrisisTeam, BusinessProcess) ([#909](https://github.com/moag1000/Little-ISMS-Helper/issues/909)) ([05f1587](https://github.com/moag1000/Little-ISMS-Helper/commit/05f158774da3b938747040e824ba1e7d002eb110))
* **forms:** in-page drawer/modal for ISMSObjective, ChangeRequest, PrototypeProtection, TIA ([#912](https://github.com/moag1000/Little-ISMS-Helper/issues/912)) ([be5ce94](https://github.com/moag1000/Little-ISMS-Helper/commit/be5ce948e65d38f6c954be148ca5d519bb27476e))
* **forms:** in-page drawer/modal for Privacy domain (ProcessingActivity, DataSubjectRequest, Consent) ([#910](https://github.com/moag1000/Little-ISMS-Helper/issues/910)) ([7f4723b](https://github.com/moag1000/Little-ISMS-Helper/commit/7f4723b4923e852e05df6b3173bd07b78fb62f79))
* **forms:** in-page drawer/modal for Resources domain (Supplier, Patch, InterestedParty, Training) ([#911](https://github.com/moag1000/Little-ISMS-Helper/issues/911)) ([b70f407](https://github.com/moag1000/Little-ISMS-Helper/commit/b70f407500b7039d5331a88a93f9064b442585e8))
* **forms:** in-page drawer/modal sweep — Risk domain (B1) + Asset/Document/ComplianceRequirement (B2) ([#907](https://github.com/moag1000/Little-ISMS-Helper/issues/907)) ([781fb0b](https://github.com/moag1000/Little-ISMS-Helper/commit/781fb0b5e6d326ae5b964d414d281cab11c977ff))
* **forms:** in-page modal-wizard for Incident + DataBreach (P3, final sweep batch) ([#914](https://github.com/moag1000/Little-ISMS-Helper/issues/914)) ([d65aa43](https://github.com/moag1000/Little-ISMS-Helper/commit/d65aa4392df6c220e7956a3c9b095fe26a212d56))
* **forms:** in-page sweep Batch 3 — Audit domain (4 entities) ([#908](https://github.com/moag1000/Little-ISMS-Helper/issues/908)) ([5ad8ed3](https://github.com/moag1000/Little-ISMS-Helper/commit/5ad8ed3b2c379daf3062c5908bf197f927fc9301))
* **forms:** in-page sweep Batch 8 — AuditProgram, ScheduledReport, DoraDataFlow, DoraExitPlan ([#913](https://github.com/moag1000/Little-ISMS-Helper/issues/913)) ([96d25a6](https://github.com/moag1000/Little-ISMS-Helper/commit/96d25a6704c7471ce4e47f9d5dd17aec438e8942))
* **import:** 360° schema-driven bulk import for 26 entities + hub ([#884](https://github.com/moag1000/Little-ISMS-Helper/issues/884)) ([582c36b](https://github.com/moag1000/Little-ISMS-Helper/commit/582c36b71fdcf754a3d8998d60725185335d2f4a))
* **import:** schema-driven bulk import + privacy imports (VVT/DSR/Consent) ([#881](https://github.com/moag1000/Little-ISMS-Helper/issues/881)) ([a619faa](https://github.com/moag1000/Little-ISMS-Helper/commit/a619faaded9aa98c8c039532bcb273ca6b40e5f0))
* **mcp:** F21 read-only MCP server for compliance catalogue ([#891](https://github.com/moag1000/Little-ISMS-Helper/issues/891)) ([50136f3](https://github.com/moag1000/Little-ISMS-Helper/commit/50136f3d621706d4d4e209124a5ae5c949291e77))
* **notifications:** wire the domain-event -&gt; rule engine (10 dead triggers now fire) ([#901](https://github.com/moag1000/Little-ISMS-Helper/issues/901)) ([fbefa98](https://github.com/moag1000/Little-ISMS-Helper/commit/fbefa98eeb54cf6bd7db84bf75e2e9cb5515118b))
* **phase0:** F13 TIA, F45 UAR-MVP, F25/F26 authority, F3 digest — wave 2 ([#867](https://github.com/moag1000/Little-ISMS-Helper/issues/867)) ([582c83b](https://github.com/moag1000/Little-ISMS-Helper/commit/582c83bffc77a094955a9a4e82bd55250a99e9dc))
* **phase0:** trust + mandatory quick-wins — wave 1 (catalog cleanup, F14, F2, F8, F33) ([#862](https://github.com/moag1000/Little-ISMS-Helper/issues/862)) ([d7491ea](https://github.com/moag1000/Little-ISMS-Helper/commit/d7491eaa72e3e2d8b4a467fcb40c5695b7c0dd7d))
* **phase1:** DPA-generator (F32), answer-library (F44), full UAR-UX (F45), quantitative risk (F46) ([#874](https://github.com/moag1000/Little-ISMS-Helper/issues/874)) ([1d27979](https://github.com/moag1000/Little-ISMS-Helper/commit/1d279799cef6adbeb616714388b35828bb76f850))
* **phase2:** sectoral DPIA (F31), trust-center (F43), field-level RBAC (F7) ([#878](https://github.com/moag1000/Little-ISMS-Helper/issues/878)) ([fa53d82](https://github.com/moag1000/Little-ISMS-Helper/commit/fa53d82cbf2a1a02176303418ab0f170584d9899))
* **planning:** Resource-Planning module (Maßnahmen · Roadmap · Capacity) ([#906](https://github.com/moag1000/Little-ISMS-Helper/issues/906)) ([abeb250](https://github.com/moag1000/Little-ISMS-Helper/commit/abeb2502812d0fb94da018b3efd161e47bfc0a5a))
* **policy-wizard:** 6 more operational baselines + RACI markdown-table rendering ([#829](https://github.com/moag1000/Little-ISMS-Helper/issues/829)) ([d2d49c6](https://github.com/moag1000/Little-ISMS-Helper/commit/d2d49c6a157b6c0f5c54bb9bb8c27f683ae88a82))
* **policy:** F38 policy-pack format adapter + export ([#887](https://github.com/moag1000/Little-ISMS-Helper/issues/887)) ([753eb7c](https://github.com/moag1000/Little-ISMS-Helper/commit/753eb7c3b616f1b19459a358e27d6a7b546a58f5))
* **privacy:** Alva hint for marketing purpose on wrong legal basis (N-6) ([#869](https://github.com/moag1000/Little-ISMS-Helper/issues/869)) ([25747a2](https://github.com/moag1000/Little-ISMS-Helper/commit/25747a266e2d267b7cce4be6d2ab43f811ad4156))
* **privacy:** Art. 10 criminal-data legal-basis gate + Art. 30(1)(d) recipients (W3a) ([#865](https://github.com/moag1000/Little-ISMS-Helper/issues/865)) ([1ba3cfa](https://github.com/moag1000/Little-ISMS-Helper/commit/1ba3cfa3c99e80ed8fcad3180675ce0cb2616de2))
* **privacy:** consent withdrawal single-path + audit-log PII redaction (W2a) ([#861](https://github.com/moag1000/Little-ISMS-Helper/issues/861)) ([102a2b3](https://github.com/moag1000/Little-ISMS-Helper/commit/102a2b3109d99d78fdb6ae107198bf30ea3cedff))
* **privacy:** controller postal address + supplier third-country transfer (W3b) ([#866](https://github.com/moag1000/Little-ISMS-Helper/issues/866)) ([5d4c73e](https://github.com/moag1000/Little-ISMS-Helper/commit/5d4c73ef1216e7dad86b17eef49c5ae83d4e4c52))
* **privacy:** data-retention enforcement engine (W2c) ([#864](https://github.com/moag1000/Little-ISMS-Helper/issues/864)) ([e5c4142](https://github.com/moag1000/Little-ISMS-Helper/commit/e5c4142a91285365bb39f90fa69a6d42646ab575))
* **privacy:** DPO appointment register + DSR extension-notice proof (W4) ([#868](https://github.com/moag1000/Little-ISMS-Helper/issues/868)) ([bda8683](https://github.com/moag1000/Little-ISMS-Helper/commit/bda8683a9e435ee613aa064a50bca7a502f83563))
* **privacy:** enforce DSR identity + DPIA consultation gates (DSGVO audit W1) ([#860](https://github.com/moag1000/Little-ISMS-Helper/issues/860)) ([f54f38e](https://github.com/moag1000/Little-ISMS-Helper/commit/f54f38e3d3ab465564662fbb6a7c53fd9d746052))
* **privacy:** lock consent proof fields after verification (N-1) ([#870](https://github.com/moag1000/Little-ISMS-Helper/issues/870)) ([09c3f93](https://github.com/moag1000/Little-ISMS-Helper/commit/09c3f936706549611fbed65949e407d5af8955a4))
* **privacy:** per-data-category retention period (N-4) ([#871](https://github.com/moag1000/Little-ISMS-Helper/issues/871)) ([6f604fe](https://github.com/moag1000/Little-ISMS-Helper/commit/6f604fe599bfae533c2b4ea7d6c8932f7018fae1))
* **privacy:** processor-role record fields (Art. 30(2), N-7) ([#872](https://github.com/moag1000/Little-ISMS-Helper/issues/872)) ([960672a](https://github.com/moag1000/Little-ISMS-Helper/commit/960672a4c34716b5e177c61694436f75bf98cfd6))
* **privacy:** real Art. 15/20 personal-data export (W2b) ([#863](https://github.com/moag1000/Little-ISMS-Helper/issues/863)) ([ae9deb3](https://github.com/moag1000/Little-ISMS-Helper/commit/ae9deb3cb750909e6e26407b1ccbe836cf6abf6e))
* **security:** enforce the dead-config security policies (MFA, SSO domain-binding, SSO role-sync) + README showcase ([#900](https://github.com/moag1000/Little-ISMS-Helper/issues/900)) ([efee065](https://github.com/moag1000/Little-ISMS-Helper/commit/efee06577015788b9204a4d926283b50a2d87c7e))
* **suppliers:** F23 — outbound supplier security questionnaire ([#892](https://github.com/moag1000/Little-ISMS-Helper/issues/892)) ([c2dca37](https://github.com/moag1000/Little-ISMS-Helper/commit/c2dca37deacfdb8736f76ad0994ab9566859d84c))
* **tisax:** framework consolidation — canonical TISAX, migration, audit-freeze, evidence ([#828](https://github.com/moag1000/Little-ISMS-Helper/issues/828)) ([0ccb4be](https://github.com/moag1000/Little-ISMS-Helper/commit/0ccb4be494d4b5d8a29619f0922c9715fb16846c))
* **ui:** wire CLI/endpoint features into UI — F-NEU apply, F6 token UI, F38 export ([#896](https://github.com/moag1000/Little-ISMS-Helper/issues/896)) ([7d58ee4](https://github.com/moag1000/Little-ISMS-Helper/commit/7d58ee48c5a713e4557869f6ea619036319c1bb7))
* **vuln:** F39 — ENISA EUVD feed connector + daily sync ([#885](https://github.com/moag1000/Little-ISMS-Helper/issues/885)) ([9ece839](https://github.com/moag1000/Little-ISMS-Helper/commit/9ece8395923c8ab8e63fffb1670b46102e5de168))


### Fixed

* **a11y:** clear aria-hidden on open fa-modal (not just inert) ([#845](https://github.com/moag1000/Little-ISMS-Helper/issues/845)) ([0c2dc71](https://github.com/moag1000/Little-ISMS-Helper/commit/0c2dc71bbcc27b5ea5da6822e80d4ee16002bf19))
* **a11y:** label icon-only controls + repair empty workflow cancel buttons ([#825](https://github.com/moag1000/Little-ISMS-Helper/issues/825)) ([14d1121](https://github.com/moag1000/Little-ISMS-Helper/commit/14d11211997b3b1b09b714cfa124c88b4c878d76))
* **a11y:** wizard-form-guard never attached its submit listener ([8cce566](https://github.com/moag1000/Little-ISMS-Helper/commit/8cce566507e37620ef93d831730aa0f7989dde44))
* **compliance-wizard:** auto-detect 7 more clauses from tenant data ([#855](https://github.com/moag1000/Little-ISMS-Helper/issues/855)) ([2899764](https://github.com/moag1000/Little-ISMS-Helper/commit/2899764135d5ed9f54dbb5b993c6b3430b41feeb))
* **compliance-wizard:** detect populated tenant data + allow manual sign-off ([#850](https://github.com/moag1000/Little-ISMS-Helper/issues/850)) ([a22772d](https://github.com/moag1000/Little-ISMS-Helper/commit/a22772ddeaf0cc9b906961d73ca64248cfdb2cd0))
* **compliance:** stop create-mappings 500 (protected getEntityManager + orphan guard) ([#840](https://github.com/moag1000/Little-ISMS-Helper/issues/840)) ([d601d79](https://github.com/moag1000/Little-ISMS-Helper/commit/d601d7942630105d23749116576fab625869a416))
* **correctness:** async-safety guards + dead-logic cleanup (audit June 2026) ([#836](https://github.com/moag1000/Little-ISMS-Helper/issues/836)) ([76726ad](https://github.com/moag1000/Little-ISMS-Helper/commit/76726ad7f1d79ecebc0c866a2691c1f5a5ff7c7e))
* **dashboards:** honest-numbers follow-ups — risk-trend, DORA ICT, thresholds, training, gap ([#899](https://github.com/moag1000/Little-ISMS-Helper/issues/899)) ([a26e46c](https://github.com/moag1000/Little-ISMS-Helper/commit/a26e46c77bb1981a2270054fb1c77a3090f9c070))
* **dashboards:** replace fabricated/hardcoded KPI numbers with real data ([#898](https://github.com/moag1000/Little-ISMS-Helper/issues/898)) ([0cb6a3a](https://github.com/moag1000/Little-ISMS-Helper/commit/0cb6a3aefd4d6c03ba926599193853cc7c611580))
* **docker:** run pending migrations on embedded-DB upgrade (500 on start) ([#819](https://github.com/moag1000/Little-ISMS-Helper/issues/819)) ([5999d75](https://github.com/moag1000/Little-ISMS-Helper/commit/5999d75160819c59f511a27f0984b9ce2e663181))
* **dora:** real EBA RoI taxonomy 4.0 namespace (F30, was placeholder) ([#873](https://github.com/moag1000/Little-ISMS-Helper/issues/873)) ([45eed2e](https://github.com/moag1000/Little-ISMS-Helper/commit/45eed2e29cdd3952be57ca62d0fdf6cbe2484342))
* **frontend:** Turbo/Chart/tooltip re-init guards + a11y (audit TB-6/7/8/9, T-11) ([#844](https://github.com/moag1000/Little-ISMS-Helper/issues/844)) ([3543c2d](https://github.com/moag1000/Little-ISMS-Helper/commit/3543c2d45f8cbcd798340e44b06def5be5bb38b7))
* **import:** snake_case i18n slug for multi-word entity-type labels ([#893](https://github.com/moag1000/Little-ISMS-Helper/issues/893)) ([c3531b0](https://github.com/moag1000/Little-ISMS-Helper/commit/c3531b08499efefed2418ce15e97663207a2f4e9))
* **nav:** add missing supplier bulk-import link to mega-menu ([#877](https://github.com/moag1000/Little-ISMS-Helper/issues/877)) ([e7ef0e0](https://github.com/moag1000/Little-ISMS-Helper/commit/e7ef0e08b77596e3f64605fa6dec74477a12a8d4))
* **nav:** wire F23 supplier-questionnaire into the mega-menu ([#894](https://github.com/moag1000/Little-ISMS-Helper/issues/894)) ([38b98e5](https://github.com/moag1000/Little-ISMS-Helper/commit/38b98e57e4d0955b0b0bb4a4c8f0f5b8e400f649))
* **policy-wizard:** add missing operational_baselines access/identity i18n ([#851](https://github.com/moag1000/Little-ISMS-Helper/issues/851)) ([3f32cf3](https://github.com/moag1000/Little-ISMS-Helper/commit/3f32cf32393fab342aa8860e7467ac6b35256f3d))
* **policy-wizard:** Bestandsaufnahme "Weiter" always responds + more bulk actions ([#833](https://github.com/moag1000/Little-ISMS-Helper/issues/833)) ([5755f59](https://github.com/moag1000/Little-ISMS-Helper/commit/5755f59dbc5681756c3f543c48554c0bf66b9181))
* **policy-wizard:** expose patch-SLA + continuity-RTO as render vars; add baseline round-trip E2E ([#830](https://github.com/moag1000/Little-ISMS-Helper/issues/830)) ([7d45f50](https://github.com/moag1000/Little-ISMS-Helper/commit/7d45f50dfca28fc46e3c8a2c62acf7a433438e76))
* **policy-wizard:** pre-fill Bestandsaufnahme decisions on re-render ([#839](https://github.com/moag1000/Little-ISMS-Helper/issues/839)) ([2455180](https://github.com/moag1000/Little-ISMS-Helper/commit/2455180c6e2e96bf38755192d124ee85c315f2b0))
* **policy-wizard:** seed 7 sector industry-preset bundles via migration ([#831](https://github.com/moag1000/Little-ISMS-Helper/issues/831)) ([f9be24f](https://github.com/moag1000/Little-ISMS-Helper/commit/f9be24facf6d6a34565994dcbce9c1a877a179b1))
* **privacy:** DPO finalization + screenshot persona audit (owner-picker, smoke suite, +31 demo pages) ([#880](https://github.com/moag1000/Little-ISMS-Helper/issues/880)) ([134ce09](https://github.com/moag1000/Little-ISMS-Helper/commit/134ce097343751f9d45cae74817d25f61af6e84c))
* **privacy:** DSR flash keys + document retention exclusions (DPO review follow-ups) ([#879](https://github.com/moag1000/Little-ISMS-Helper/issues/879)) ([2c3e6ce](https://github.com/moag1000/Little-ISMS-Helper/commit/2c3e6cefafefd80da40dd6fc302a46029a74fdea))
* **privacy:** processing-activity status Assert\Choice rejected every status ([#875](https://github.com/moag1000/Little-ISMS-Helper/issues/875)) ([c9003f5](https://github.com/moag1000/Little-ISMS-Helper/commit/c9003f553002d234b6212d3daad9ee7017917be9))
* **privacy:** processing-activity validator messages were in the wrong domain ([#876](https://github.com/moag1000/Little-ISMS-Helper/issues/876)) ([c7dc099](https://github.com/moag1000/Little-ISMS-Helper/commit/c7dc0990b99e3bd5504226cb681f981c3920ca0c))
* **restore:** coerce backing scalars back into enums on restore ([#882](https://github.com/moag1000/Little-ISMS-Helper/issues/882)) ([d675b43](https://github.com/moag1000/Little-ISMS-Helper/commit/d675b434dbdec3420f1a4c48074d67aeb9563fde))
* **security:** implement MFA rate-limiter, settings encryption, real Web Push ([#904](https://github.com/moag1000/Little-ISMS-Helper/issues/904)) ([dd797fe](https://github.com/moag1000/Little-ISMS-Helper/commit/dd797fe86c35329e1c93f6ee3200b18c5d521994))
* **soa+ui:** framework-appropriate ref examples + un-clip TomSelect dropdown ([#823](https://github.com/moag1000/Little-ISMS-Helper/issues/823)) ([e5d5d98](https://github.com/moag1000/Little-ISMS-Helper/commit/e5d5d98dd95467382a6ca6dfd109118c660fb6f5))
* **stubs:** fill user-facing data stubs + remove dead fake-data methods ([#903](https://github.com/moag1000/Little-ISMS-Helper/issues/903)) ([6ee86cd](https://github.com/moag1000/Little-ISMS-Helper/commit/6ee86cd871cd5a1d77c60832bfb26a7ee6ec9c8b))
* **tech-debt:** close Phase-1/2 gaps — F7 API gate, F46 import, F30 xBRL-CSV ([#883](https://github.com/moag1000/Little-ISMS-Helper/issues/883)) ([bde2e0c](https://github.com/moag1000/Little-ISMS-Helper/commit/bde2e0c080835aeb5d056cad3661d1bf07ea4d38))
* **tenant:** stop cross-tenant leak in ComplianceMapping listings (audit E-6) ([#849](https://github.com/moag1000/Little-ISMS-Helper/issues/849)) ([68208fa](https://github.com/moag1000/Little-ISMS-Helper/commit/68208fa67e12e683328509dc895a1a71d87c8eeb))
* **tisax:** import all 3 dimensions + measures/documents + version-aware parsing ([#824](https://github.com/moag1000/Little-ISMS-Helper/issues/824)) ([a042048](https://github.com/moag1000/Little-ISMS-Helper/commit/a042048b6d2ee44a1101ddbadd38af4cac62e9d8))
* **turbo:** real Stimulus/Turbo UX bugs from the June-2026 audit ([#835](https://github.com/moag1000/Little-ISMS-Helper/issues/835)) ([73a39c1](https://github.com/moag1000/Little-ISMS-Helper/commit/73a39c15af8409df5f9f82f93db99406d12d046e))
* **ui:** replace native browser alert()/confirm() with Aurora toast/confirm ([#822](https://github.com/moag1000/Little-ISMS-Helper/issues/822)) ([4557dbd](https://github.com/moag1000/Little-ISMS-Helper/commit/4557dbd4010fb2ddd5898279ac6f9050fe88622a))


### Changed

* **audit-bucket1:** safe best-practice wins (audit June 2026) ([#834](https://github.com/moag1000/Little-ISMS-Helper/issues/834)) ([049679c](https://github.com/moag1000/Little-ISMS-Helper/commit/049679c96da77bb01563ace10520713eeeef545e))
* **compliance:** stop injecting Command objects as services (audit S-2) ([#853](https://github.com/moag1000/Little-ISMS-Helper/issues/853)) ([84cf733](https://github.com/moag1000/Little-ISMS-Helper/commit/84cf733f11c5f656b5b68b45c790f594d4e40594))
* **controllers:** extract service layer from 3 fat controllers (audit C-7) ([#852](https://github.com/moag1000/Little-ISMS-Helper/issues/852)) ([1269009](https://github.com/moag1000/Little-ISMS-Helper/commit/1269009572b88a67632acc4fa768dfa421b1e314))
* **di:** #[Autowire] kernel.project_dir + drop ParameterBag (audit S-5/S-6) ([#848](https://github.com/moag1000/Little-ISMS-Helper/issues/848)) ([bb8a377](https://github.com/moag1000/Little-ISMS-Helper/commit/bb8a3777e0cfe44848e5625ede15bd4762acb564))
* **di:** inject RegulatoryWorkflowLoader instead of container-&gt;get (audit S-1) ([#846](https://github.com/moag1000/Little-ISMS-Helper/issues/846)) ([78bc445](https://github.com/moag1000/Little-ISMS-Helper/commit/78bc4453b26ae9039ed52356dacd8755dc82ab6e))
* **events:** migrate 19 EventSubscriberInterface to #[AsEventListener] ([#842](https://github.com/moag1000/Little-ISMS-Helper/issues/842)) ([8752a2e](https://github.com/moag1000/Little-ISMS-Helper/commit/8752a2ed4858b19cd47ed8558b0f42f2bbf281a6))
* **php:** match expressions, #[\Override], createStub (audit P-6/P-7/P-8) ([#843](https://github.com/moag1000/Little-ISMS-Helper/issues/843)) ([83075d7](https://github.com/moag1000/Little-ISMS-Helper/commit/83075d7122d247ceb94ae9be7a6ec3a92eba86dd))
* **workflow:** drop deprecated WorkflowAutoProgressionService from 3 services (audit S-3) ([#847](https://github.com/moag1000/Little-ISMS-Helper/issues/847)) ([2b7b324](https://github.com/moag1000/Little-ISMS-Helper/commit/2b7b324d57900bcb9cb17b3a5baa151ade044b3b))


### Documentation

* **audit:** add Turbo/Stimulus UX + dead-code passes + remediation log ([#837](https://github.com/moag1000/Little-ISMS-Helper/issues/837)) ([03310e6](https://github.com/moag1000/Little-ISMS-Helper/commit/03310e6654811e3054f2f0ca7defd88a4e885cb4))
* **audit:** backlog cleared — remediation log + accept-decisions ([#854](https://github.com/moag1000/Little-ISMS-Helper/issues/854)) ([a325e90](https://github.com/moag1000/Little-ISMS-Helper/commit/a325e90eccd23313d74a966d5bc726f64d839c8a))
* **audit:** dead-service investigation + paused-backlog state ([#838](https://github.com/moag1000/Little-ISMS-Helper/issues/838)) ([e6888f7](https://github.com/moag1000/Little-ISMS-Helper/commit/e6888f768ff97f8b3a468d1d954385216ff3a649))
* **audit:** Symfony 7.4 / PHP 8.5 best-practice audit (June 2026, audit-only) ([#832](https://github.com/moag1000/Little-ISMS-Helper/issues/832)) ([124dec2](https://github.com/moag1000/Little-ISMS-Helper/commit/124dec2565ed8dc58510379782dfc82008cbb78f))
* **design-system:** sync DESIGN_SYSTEM, mirror CSS + alva section ([#821](https://github.com/moag1000/Little-ISMS-Helper/issues/821)) ([bbb2b8d](https://github.com/moag1000/Little-ISMS-Helper/commit/bbb2b8d7bd03a0c82564b80d565d270d4e32be99))
* **roadmap:** code-verified feature-catalog status + new-wish gaps ([#859](https://github.com/moag1000/Little-ISMS-Helper/issues/859)) ([a7c7811](https://github.com/moag1000/Little-ISMS-Helper/commit/a7c7811e29d6354b5a6b70c52ca642ef9354d986))
* **roadmap:** refresh + tidy — v3.x reality, strategic backlog, current metrics ([#902](https://github.com/moag1000/Little-ISMS-Helper/issues/902)) ([60d541e](https://github.com/moag1000/Little-ISMS-Helper/commit/60d541eca5120f1785c2af3d9d0861ad6a6584fc))

## [3.10.0](https://github.com/moag1000/Little-ISMS-Helper/compare/v3.9.0...v3.10.0) (2026-05-31)


### Added

* **compliance:** mapping-list evaluation info + bulk-marking ([#814](https://github.com/moag1000/Little-ISMS-Helper/issues/814)) ([9e34266](https://github.com/moag1000/Little-ISMS-Helper/commit/9e34266dc678eeeb0964454f20839f64646a22f4))
* **soa:** control-form overhaul (sections + evidence + validation + status&lt;-&gt;% + framework-ref autocomplete) ([#813](https://github.com/moag1000/Little-ISMS-Helper/issues/813)) ([dca5af2](https://github.com/moag1000/Little-ISMS-Helper/commit/dca5af26405ba0ffa15e01ff00d05a1184d90418))


### Fixed

* **admin:** loader-fixer 500 — EntityManager::clear() detached the whole identity map ([#808](https://github.com/moag1000/Little-ISMS-Helper/issues/808)) ([ba76f53](https://github.com/moag1000/Little-ISMS-Helper/commit/ba76f53502dd62ea61874dbc48c8b678c6f1d7c8))
* **audits:** empty-state for finding/CAPA forms when no parent exists ([#816](https://github.com/moag1000/Little-ISMS-Helper/issues/816)) ([063d458](https://github.com/moag1000/Little-ISMS-Helper/commit/063d458cef8332e6487632e1731f375be7b17417))
* **compliance:** expand DORA mapping IDs to catalogue sub-articles (de-orphan) ([#815](https://github.com/moag1000/Little-ISMS-Helper/issues/815)) ([c98b6f1](https://github.com/moag1000/Little-ISMS-Helper/commit/c98b6f150e77fc14eb96301f3a946bd13aa60aee))
* **policy-wizard:** Bestandsaufnahme "Weiter" silently blocked by empty required select ([#810](https://github.com/moag1000/Little-ISMS-Helper/issues/810)) ([b3774c5](https://github.com/moag1000/Little-ISMS-Helper/commit/b3774c5970651e58b04dda465be71f5e3c6623cf))
* **wizard:** exclude not-applicable controls from coverage + render %count% gap params ([#809](https://github.com/moag1000/Little-ISMS-Helper/issues/809)) ([bf963ce](https://github.com/moag1000/Little-ISMS-Helper/commit/bf963ce9b8318541d6e78ce9e1e7bf5ac73163ae))
* **wizard:** TISAX readiness scores imported Reifegrad (maturity_coverage) ([#812](https://github.com/moag1000/Little-ISMS-Helper/issues/812)) ([c8ecaa0](https://github.com/moag1000/Little-ISMS-Helper/commit/c8ecaa0a3eaa01bb37ef5a127d1b639fb8cc940e))


### Documentation

* **testing:** 3 complete E2E ISMS workflows as reusable test scenarios ([#817](https://github.com/moag1000/Little-ISMS-Helper/issues/817)) ([e3742a1](https://github.com/moag1000/Little-ISMS-Helper/commit/e3742a1030950f9c6585ef6db66fd778d98b1a01))

## [3.9.0](https://github.com/moag1000/Little-ISMS-Helper/compare/v3.8.0...v3.9.0) (2026-05-31)


### Added

* **alva-hint:** coverage expansion to 30 rules ([#521](https://github.com/moag1000/Little-ISMS-Helper/issues/521)) ([b04d7be](https://github.com/moag1000/Little-ISMS-Helper/commit/b04d7be9c792a26c896e1d4edea0502848b11d8a))
* **alva:** bc_exercise.target_missed AlvaHint rule (Closes K-07) ([#583](https://github.com/moag1000/Little-ISMS-Helper/issues/583)) ([84aa793](https://github.com/moag1000/Little-ISMS-Helper/commit/84aa793958c2c01e8c7c004f46acb9c1d201ea67))
* **alva:** expand Form-Step-Inline-Hint coverage to 15+ rules across 9 FormTypes ([#644](https://github.com/moag1000/Little-ISMS-Helper/issues/644)) ([57eff17](https://github.com/moag1000/Little-ISMS-Helper/commit/57eff172ce05a0dba4b89a64bc32802282d1a90f))
* **alva:** Form-Step-Inline-Hint Pattern (P-19 expansion) ([#620](https://github.com/moag1000/Little-ISMS-Helper/issues/620)) ([34d1194](https://github.com/moag1000/Little-ISMS-Helper/commit/34d1194d1acf556490f0a17707dece1a29f1864a))
* **analytics:** resize tab-pane charts on reveal (H-12, the bounded slice) ([#797](https://github.com/moag1000/Little-ISMS-Helper/issues/797)) ([5248f6b](https://github.com/moag1000/Little-ISMS-Helper/commit/5248f6b600f6875062fff4e2bf35c873905cd752))
* **asset:** remove dead monetaryValue field (S14+ §15) ([#656](https://github.com/moag1000/Little-ISMS-Helper/issues/656)) ([b9d58a3](https://github.com/moag1000/Little-ISMS-Helper/commit/b9d58a37d6d6245b023f60e609830c6e0ab8b1c5))
* **asset:** tenant-konfigurierbarer Sub-Type Layer + Branchen-Seeds (S18 B2) ([#684](https://github.com/moag1000/Little-ISMS-Helper/issues/684)) ([d521530](https://github.com/moag1000/Little-ISMS-Helper/commit/d521530060fced3df1097bea27b736e8fd3ab1ca))
* **audit:** AuditProgram entity Phase 2.5 (ISO 19011 §5.4) ([a4bd8ae](https://github.com/moag1000/Little-ISMS-Helper/commit/a4bd8ae20876c898add8fe18c9e93453be3ab1a7))
* **audit:** C4-B4 cross-framework coverage analysis for multi-framework audits ([da8766d](https://github.com/moag1000/Little-ISMS-Helper/commit/da8766d489fa9a511050ca7da89ec1002eaaa5b8))
* **audit:** C5 cluster — correlation-help + scoped-asset toggle + audit templates (S14) ([#606](https://github.com/moag1000/Little-ISMS-Helper/issues/606)) ([9a1a950](https://github.com/moag1000/Little-ISMS-Helper/commit/9a1a950a7c3402bf02bd5739787fac97aee512cf))
* **audit:** CAPA Builder Stimulus controller for nonconformityDetails (S17 B4 follow-up) ([#682](https://github.com/moag1000/Little-ISMS-Helper/issues/682)) ([a93b8b6](https://github.com/moag1000/Little-ISMS-Helper/commit/a93b8b6ed9d3b07af139f67f9023e17d3c0b412c))
* **audit:** persona-driven browser-console audit (Stufe 1, capture-only) ([#570](https://github.com/moag1000/Little-ISMS-Helper/issues/570)) ([7563de6](https://github.com/moag1000/Little-ISMS-Helper/commit/7563de60a9cef08da8b906d21547a1d402b3315c))
* **audit:** structured NC details via Hybrid JSON (S17 B4) ([#680](https://github.com/moag1000/Little-ISMS-Helper/issues/680)) ([eedbcbc](https://github.com/moag1000/Little-ISMS-Helper/commit/eedbcbc4f81fa9506abd17c452f1207c889aad2d))
* **aurora+menu:** Aurora v4 Welle 2/3/4 foundation + Mega-Menu Phase 1+2 ([#732](https://github.com/moag1000/Little-ISMS-Helper/issues/732)) ([0719f1d](https://github.com/moag1000/Little-ISMS-Helper/commit/0719f1d88b3988d320da1853879bb4d3769628fb))
* **aurora:** bucket-3 — fa-matrix-table/fa-settings-table/fa-action-bar extensions + 4 migrations ([#700](https://github.com/moag1000/Little-ISMS-Helper/issues/700)) ([6e68737](https://github.com/moag1000/Little-ISMS-Helper/commit/6e687374f51ec17788d3c2f36909a6b69f43086d))
* **aurora:** fa-modal foundation — unified modal library (confirm/settings/wizard) ([#547](https://github.com/moag1000/Little-ISMS-Helper/issues/547)) ([c9c36e8](https://github.com/moag1000/Little-ISMS-Helper/commit/c9c36e88a03372aca96a90851a8bb0e790d08de4))
* **aurora:** icon-migration sweep — close 8 TODO(aurora-*) ([#698](https://github.com/moag1000/Little-ISMS-Helper/issues/698)) ([0ce2c2b](https://github.com/moag1000/Little-ISMS-Helper/commit/0ce2c2b572ce4cc4f491e5931d5658b64f892ae3))
* **aurora:** modal sweep wave 1 — shared partials + workflow macro (sweep 2.1 + 2.2) ([#551](https://github.com/moag1000/Little-ISMS-Helper/issues/551)) ([7a438fd](https://github.com/moag1000/Little-ISMS-Helper/commit/7a438fd8bc854c224403f4d2f6314f31eb6d002a))
* **aurora:** modal sweep wave 2 — regulatory + workflow + delete page modals (batch A/B/C) ([#555](https://github.com/moag1000/Little-ISMS-Helper/issues/555)) ([f175975](https://github.com/moag1000/Little-ISMS-Helper/commit/f175975c1311012ddb73f9ce2f1022bcba1047d9))
* **aurora:** modal sweep wave 3 — admin/export/info modals + Bootstrap.Modal() cleanup ([#558](https://github.com/moag1000/Little-ISMS-Helper/issues/558)) ([823551a](https://github.com/moag1000/Little-ISMS-Helper/commit/823551adde8284c1cf10a23e597d2a04d59a88bc))
* **aurora:** sweep wave 4 — migrate backup.html.twig custom shells to fa-modal ([#559](https://github.com/moag1000/Little-ISMS-Helper/issues/559)) ([ba0a3c1](https://github.com/moag1000/Little-ISMS-Helper/commit/ba0a3c1fc7a00af6fab1f2fc65459cae1ada8156))
* **awareness:** Cluster C — Training-Workflow + Document-Review (S14) ([#608](https://github.com/moag1000/Little-ISMS-Helper/issues/608)) ([f24566a](https://github.com/moag1000/Little-ISMS-Helper/commit/f24566aa90f4fe6160b6b567aa4edcef6cce1334))
* **bcm:** crisisTeams + businessProcess Quick-Create in BCP-form (S15 A1+A2) ([#670](https://github.com/moag1000/Little-ISMS-Helper/issues/670)) ([d4bb1a1](https://github.com/moag1000/Little-ISMS-Helper/commit/d4bb1a1b1cbf4f8fab7eb0aba2e34f8288201a5d))
* **bcm:** typed M2M for BC-Exercise observers (S15 A3) ([#669](https://github.com/moag1000/Little-ISMS-Helper/issues/669)) ([04b73da](https://github.com/moag1000/Little-ISMS-Helper/commit/04b73da630a000b247bd5b16f467bc1db3ff63a5))
* **bcm:** typed M2M for BusinessProcess dependencies (S14+ §17) ([#664](https://github.com/moag1000/Little-ISMS-Helper/issues/664)) ([3bb21f3](https://github.com/moag1000/Little-ISMS-Helper/commit/3bb21f3b8cc11514373773570124956e27ac2a78))
* **bcm:** validate RPO ≤ RTO ≤ MTPD recovery chain (S11 M-01) ([#658](https://github.com/moag1000/Little-ISMS-Helper/issues/658)) ([a0a3082](https://github.com/moag1000/Little-ISMS-Helper/commit/a0a3082cae387ae121931ed9891791322ef01cdb))
* **bcm:** wire regulatory + operational impact tooltips into BP help drawer (S14+ §17) ([#660](https://github.com/moag1000/Little-ISMS-Helper/issues/660)) ([0b00834](https://github.com/moag1000/Little-ISMS-Helper/commit/0b008349a2468f175b7723916769f879f67ebccd))
* **bp:** server-validate recovery chain RPO ≤ RTO ≤ MTPD ([#586](https://github.com/moag1000/Little-ISMS-Helper/issues/586)) ([4e1da05](https://github.com/moag1000/Little-ISMS-Helper/commit/4e1da05e3408928816ad31e18887e74d4b323a49))
* **bp:** tooltip drawer pattern (S14 [#17](https://github.com/moag1000/Little-ISMS-Helper/issues/17) - PR [#607](https://github.com/moag1000/Little-ISMS-Helper/issues/607) follow-up) ([#624](https://github.com/moag1000/Little-ISMS-Helper/issues/624)) ([3d04cde](https://github.com/moag1000/Little-ISMS-Helper/commit/3d04cdeb0d6308abc37e390d16a6d6e7e261eebd))
* **bsi:** expand IT-Grundschutz Baustein catalogue (106→117 Bausteine) ([1f2160c](https://github.com/moag1000/Little-ISMS-Helper/commit/1f2160ca99998bea8e2cc366c3a41d1820e89e80))
* **bulk:** bulk-delete-check for all 20 entities (real deps for top-5 + stubs for rest) ([#575](https://github.com/moag1000/Little-ISMS-Helper/issues/575)) ([433f54a](https://github.com/moag1000/Little-ISMS-Helper/commit/433f54aea7e837d3d20324c1a7f36f6ea07bcd56))
* **bulk:** full bulk-action endpoint rollout (audit C-2/C-3/CC-5) ([#534](https://github.com/moag1000/Little-ISMS-Helper/issues/534)) ([32577c5](https://github.com/moag1000/Little-ISMS-Helper/commit/32577c56f999fa99a91d7ed77761437bea722dd7))
* **capa:** auto-create CorrectiveAction from high-severity Incident with root-cause ([#597](https://github.com/moag1000/Little-ISMS-Helper/issues/597)) ([b0a0e05](https://github.com/moag1000/Little-ISMS-Helper/commit/b0a0e059fcc09ac58b856a5236aced358659e46d))
* **capa:** C4 cluster — re-loop, multi-control, lineage, due-hint (S13) ([#602](https://github.com/moag1000/Little-ISMS-Helper/issues/602)) ([0fbb8b6](https://github.com/moag1000/Little-ISMS-Helper/commit/0fbb8b6f2431560077844524050e83627bee1720))
* **capa:** M-07 Phase 1 — schema + backfill + UI filter ([#637](https://github.com/moag1000/Little-ISMS-Helper/issues/637)) ([f7ebb73](https://github.com/moag1000/Little-ISMS-Helper/commit/f7ebb73390166dafb8d35e7d930d4ef0fdf17007))
* **ci:** 3 new quality gates — disabled+mapped, enum→JSON, Twig unsupported tags ([#714](https://github.com/moag1000/Little-ISMS-Helper/issues/714)) ([0363a7f](https://github.com/moag1000/Little-ISMS-Helper/commit/0363a7f997465fcd906839135315253f47975c20))
* **ci:** 3 new quality gates encoding recent bug patterns ([#713](https://github.com/moag1000/Little-ISMS-Helper/issues/713)) ([34ce427](https://github.com/moag1000/Little-ISMS-Helper/commit/34ce427ad27eaad9f016b5bd9ea3f07621f704d0))
* **clone:** C4-C1 Klon-Funktionen — 8 entities + EntityClonerInterface ([77ce129](https://github.com/moag1000/Little-ISMS-Helper/commit/77ce129829093ef45391efa3a9576c8d1041e0d3))
* **compliance:** add CrossFrameworkLookupService for bidirectional + transitive lookup ([a94e35f](https://github.com/moag1000/Little-ISMS-Helper/commit/a94e35f7fa674dbbf5f8f1734a90e7d4707575e3))
* **compliance:** EU AI Act → full Art.X catalogue + AIACT re-key (Welle 3) ([#803](https://github.com/moag1000/Little-ISMS-Helper/issues/803)) ([53aee50](https://github.com/moag1000/Little-ISMS-Helper/commit/53aee50f69a83a0d056ffe548d0455fb7f1140ff))
* **compliance:** EU framework mapping audit toolchain + evidence-backed findings ([#756](https://github.com/moag1000/Little-ISMS-Helper/issues/756)) ([d652584](https://github.com/moag1000/Little-ISMS-Helper/commit/d652584c6d886955949da551cdabfbd2f32f75a0))
* **compliance:** EU-mapping audit Wave 1 — provenance + verified fixes ([#761](https://github.com/moag1000/Little-ISMS-Helper/issues/761)) ([d2b1aa2](https://github.com/moag1000/Little-ISMS-Helper/commit/d2b1aa229da99c46e65a7ab8d348fa22a193933d))
* **compliance:** EU-mapping Phase C — audit library YAML crosswalks ([#773](https://github.com/moag1000/Little-ISMS-Helper/issues/773)) ([32dfd5c](https://github.com/moag1000/Little-ISMS-Helper/commit/32dfd5c11becb9026d75c06c74e45c81b28b2efe))
* **compliance:** EU-mapping Wave 4+5 — audit all remaining EU frameworks + apply fixes ([#771](https://github.com/moag1000/Little-ISMS-Helper/issues/771)) ([ef1f2c1](https://github.com/moag1000/Little-ISMS-Helper/commit/ef1f2c165463e50143602e13822b38cabfc461e2))
* **compliance:** expand ISO 27005, ISO 22301 + fill GDPR DPO/consent gaps ([#800](https://github.com/moag1000/Little-ISMS-Helper/issues/800)) ([805a80d](https://github.com/moag1000/Little-ISMS-Helper/commit/805a80d93b382946f73ca20afe4002835fd5b4a9))
* **compliance:** full BSI IT-Grundschutz catalogue (360 vs 32 Anforderungen) ([#802](https://github.com/moag1000/Little-ISMS-Helper/issues/802)) ([f7a90c0](https://github.com/moag1000/Little-ISMS-Helper/commit/f7a90c0e5de0c2401f53fcc728124f786be287b5))
* **compliance:** i18n CSV/Excel headers + CsvSanitizer extraction + export smoke tests ([fdc436a](https://github.com/moag1000/Little-ISMS-Helper/commit/fdc436ae0739c88747f91b054bedf6d709c74369))
* **compliance:** mapping audit Wave 6 — full-corpus coverage + cross-regime fixes ([#775](https://github.com/moag1000/Little-ISMS-Helper/issues/775)) ([68abfdb](https://github.com/moag1000/Little-ISMS-Helper/commit/68abfdb855c637613d54975ea7b3575285c6b961))
* **compliance:** optional decompositions + mapping-quality dashboard fixes (charts/status/MQS) ([#783](https://github.com/moag1000/Little-ISMS-Helper/issues/783)) ([71cbf0a](https://github.com/moag1000/Little-ISMS-Helper/commit/71cbf0a3aa0e95a5540b3c0e370b46883591e30b))
* **compliance:** sub-requirement-level mapping decomposition (32 crosswalks, 4845 rows) + app integration ([#780](https://github.com/moag1000/Little-ISMS-Helper/issues/780)) ([448e425](https://github.com/moag1000/Little-ISMS-Helper/commit/448e425b98893530e3e1221351ad2489d152b726))
* **compliance:** wire ISO 42001 + 4 ready catalogues into framework loader ([#793](https://github.com/moag1000/Little-ISMS-Helper/issues/793)) ([6642ff8](https://github.com/moag1000/Little-ISMS-Helper/commit/6642ff8b5b184d2f63b32bd7a61d4ca8fe816b12))
* **controls:** add Wirksamkeits-Monitor route + service (ISO 27001 §9.1) ([485cef3](https://github.com/moag1000/Little-ISMS-Helper/commit/485cef3e869f99930cf2324ece4b60645ab1703b))
* **controls:** add Wirksamkeits-Monitor route + service (ISO 27001 §9.1) ([529183b](https://github.com/moag1000/Little-ISMS-Helper/commit/529183b9878cef6dda14370c23021f710ba82320))
* **controls:** add Wirksamkeits-Monitor route + service (ISO 27001 §9.1) ([87ad538](https://github.com/moag1000/Little-ISMS-Helper/commit/87ad538de6657d732d039603c054df2798baec0a))
* **controls:** merge Wirksamkeits-Monitor route + service (ISO 27001 §9.1) ([0547d06](https://github.com/moag1000/Little-ISMS-Helper/commit/0547d063127c8e8dac36e5cf02c0d1b6363dde90))
* **databreach:** migrate to fa-modal--wizard per design-system spec ([#543](https://github.com/moag1000/Little-ISMS-Helper/issues/543)) ([9c64ab5](https://github.com/moag1000/Little-ISMS-Helper/commit/9c64ab506429e2bfa608a1e46269d45ed65e6190))
* **department:** foundation entity + ProcessingActivity FK (S18 B3) ([#685](https://github.com/moag1000/Little-ISMS-Helper/issues/685)) ([370fac4](https://github.com/moag1000/Little-ISMS-Helper/commit/370fac41d5c7e723c4ede27d57b0b08f014a4d12))
* **dora:** close RT_03 data-flow ESA-taxonomy element (Bucket 6) ([#725](https://github.com/moag1000/Little-ISMS-Helper/issues/725)) ([fbd06f6](https://github.com/moag1000/Little-ISMS-Helper/commit/fbd06f63068d52b11276d04fa3f99df4df518775))
* **dora:** close RT_04 subcontractor-chain — DoraSubcontractor entity + recursive XBRL chain-walker ([#726](https://github.com/moag1000/Little-ISMS-Helper/issues/726)) ([22d751e](https://github.com/moag1000/Little-ISMS-Helper/commit/22d751eb7a0d5c0de1921327020f33d82d9aa2b7))
* **dora:** close RT_05 asset-dependency-graph in RoI XBRL exporter (Bucket 6) ([#724](https://github.com/moag1000/Little-ISMS-Helper/issues/724)) ([0fc6e16](https://github.com/moag1000/Little-ISMS-Helper/commit/0fc6e165db45b9e1df610d40f6d17131d535b56b))
* **dora:** close RT_06 decommission-plan deferred-marker (Bucket 6) ([#727](https://github.com/moag1000/Little-ISMS-Helper/issues/727)) ([788467d](https://github.com/moag1000/Little-ISMS-Helper/commit/788467dda9eaa766edfa0662fdffdbe0ad099448))
* **dora:** RoI XBRL Sprint 9 — LEI fields + ESA-taxonomy B_01/B_02/B_03 wiring ([#701](https://github.com/moag1000/Little-ISMS-Helper/issues/701)) ([c15f0f9](https://github.com/moag1000/Little-ISMS-Helper/commit/c15f0f98553bf0bab88bc38b0122b061c446cacf))
* **dora:** wire B_02.02.0140-0180 supplier SLA + audit fields in RoI XBRL ([ea8a78a](https://github.com/moag1000/Little-ISMS-Helper/commit/ea8a78aaad23718ed7c332f4d6a05c623ebdb6e3))
* **e2e:** Playwright test suite foundation (15 tests) ([#645](https://github.com/moag1000/Little-ISMS-Helper/issues/645)) ([f8a2521](https://github.com/moag1000/Little-ISMS-Helper/commit/f8a252110aa312809576968730a8aba97aa448a6))
* **form-layout:** _auto_form integration with fa-form-layout (P-2) ([#538](https://github.com/moag1000/Little-ISMS-Helper/issues/538)) ([260a50f](https://github.com/moag1000/Little-ISMS-Helper/commit/260a50f60f34eb35b77deeebc38e0bc3f1fccff7))
* **form-layout:** actually wire templates to outline-rail (post-review H-3 followup) ([#549](https://github.com/moag1000/Little-ISMS-Helper/issues/549)) ([a568284](https://github.com/moag1000/Little-ISMS-Helper/commit/a5682840b98d87f97a19517679db038fb581b78a))
* **form-layout:** Aurora outline-rail + section-cards foundation (P-1) ([#536](https://github.com/moag1000/Little-ISMS-Helper/issues/536)) ([082f6aa](https://github.com/moag1000/Little-ISMS-Helper/commit/082f6aa6cf21dbd6de065457df1dbc73c202b7fe))
* **form-layout:** migrate Settings FormTypes to fa-tabs (Tenant/User) ([#554](https://github.com/moag1000/Little-ISMS-Helper/issues/554)) ([f3083bd](https://github.com/moag1000/Little-ISMS-Helper/commit/f3083bd81db81daca7097180039398ac46bc8dee))
* **form-layout:** Sprint-A — migrate 5 fa-form-layout targets (DPIA/Risk/Audit/Policy/Control) ([#540](https://github.com/moag1000/Little-ISMS-Helper/issues/540)) ([20c8598](https://github.com/moag1000/Little-ISMS-Helper/commit/20c8598ba13f0899c2ed649ddc6781d59eedf392))
* **form-layout:** Sprint-B — migrate Supplier/ProcessingActivity/ManagementReview to outline-rail ([#541](https://github.com/moag1000/Little-ISMS-Helper/issues/541)) ([3a16b23](https://github.com/moag1000/Little-ISMS-Helper/commit/3a16b23d4048016fcba8f35ddfe61fcfb91902b1))
* **form-layout:** Sprint-C — re-enable Supplier + migrate 5 medium FormTypes (CrisisTeam/ChangeRequest/Asset/ThreatIntel/Patch) ([#544](https://github.com/moag1000/Little-ISMS-Helper/issues/544)) ([d2b166d](https://github.com/moag1000/Little-ISMS-Helper/commit/d2b166d7933755d4f553bd81a4fc84d3a142cc7c))
* **form-layout:** Sprint-D — migrate 10 medium FormTypes to outline-rail ([#553](https://github.com/moag1000/Little-ISMS-Helper/issues/553)) ([b4218de](https://github.com/moag1000/Little-ISMS-Helper/commit/b4218de0fa73181e89bc16a8e50af7fdd04b2cf9))
* **form:** close Bucket-5 deferred — SuccessCriteria + FrameworkReferences proper FormTypes ([#723](https://github.com/moag1000/Little-ISMS-Helper/issues/723)) ([3495015](https://github.com/moag1000/Little-ISMS-Helper/commit/3495015348b161a8a2a277a3f46b9fb0d85a2b02))
* **forms:** fa-form-layout coverage sweep — 6 FormTypes ([#642](https://github.com/moag1000/Little-ISMS-Helper/issues/642)) ([21fb595](https://github.com/moag1000/Little-ISMS-Helper/commit/21fb595242b9d05937b908f198baf02d90007ab1))
* **forms:** JsonStructuredType → CollectionType + EntryType (S5 Bucket 5) ([#699](https://github.com/moag1000/Little-ISMS-Helper/issues/699)) ([70afe44](https://github.com/moag1000/Little-ISMS-Helper/commit/70afe4434ce4c048bb0dd0272ea7b3d95c711d09))
* **form:** Sprint-E — SectionMap on 9 medium FormTypes (10-14 fields, flat layout retained) ([#557](https://github.com/moag1000/Little-ISMS-Helper/issues/557)) ([b31990d](https://github.com/moag1000/Little-ISMS-Helper/commit/b31990d307f19eb6f61b3a3dd885b38f2cf27b53))
* **forms:** Quick-Create foundation + 5 picker-fixes (S14 Cluster A) ([#605](https://github.com/moag1000/Little-ISMS-Helper/issues/605)) ([c99f065](https://github.com/moag1000/Little-ISMS-Helper/commit/c99f065842d7d1a0fce3e43d13c08d0986a73e67))
* **glossary:** inline ISMS-acronym tooltips for junior-ISB onboarding ([#785](https://github.com/moag1000/Little-ISMS-Helper/issues/785)) ([f88c1e3](https://github.com/moag1000/Little-ISMS-Helper/commit/f88c1e39b161d10babb020603b61493779064b1e))
* **i18n:** A4 Supplier-Norm-Bridge + A5 Lifecycle-Status-Disable-Hint (S15 bundle) ([#671](https://github.com/moag1000/Little-ISMS-Helper/issues/671)) ([1431f2c](https://github.com/moag1000/Little-ISMS-Helper/commit/1431f2ccc5c0fab563872fdb40930330a1e1e153))
* **i18n:** backfill 75 missing translation keys (Closes K-03) ([#592](https://github.com/moag1000/Little-ISMS-Helper/issues/592)) ([df1ffbb](https://github.com/moag1000/Little-ISMS-Helper/commit/df1ffbbc2773b6d4d58ff6a26eaf0d3e49b672fc))
* **i18n:** backfill 75+ missing translation keys (S12 §4-8 DoD) ([#655](https://github.com/moag1000/Little-ISMS-Helper/issues/655)) ([b429f02](https://github.com/moag1000/Little-ISMS-Helper/commit/b429f02ea5553338ea6e3b6e07b5c23331f9ffbb))
* **i18n:** backfill user/tenant/training validator keys + fix ai_agent flash domain ([#639](https://github.com/moag1000/Little-ISMS-Helper/issues/639)) ([4a4d081](https://github.com/moag1000/Little-ISMS-Helper/commit/4a4d081cd9b7ce12210863e7a44d280a3538c497))
* **i18n:** convert hardcoded EN validator messages to translation keys ([#601](https://github.com/moag1000/Little-ISMS-Helper/issues/601)) ([66ec6e3](https://github.com/moag1000/Little-ISMS-Helper/commit/66ec6e3ad935787e983ed9813d7ca219cde88234))
* **i18n:** EN-in-DE labels, placeholders, setup-9, locale help-link (S12) ([#599](https://github.com/moag1000/Little-ISMS-Helper/issues/599)) ([df0f658](https://github.com/moag1000/Little-ISMS-Helper/commit/df0f6584c5eeb5f71e02b286d4b2a83d05b956db))
* **i18n:** rename Mandant→Organisation in UI (S17 B1 Welle 1) ([#681](https://github.com/moag1000/Little-ISMS-Helper/issues/681)) ([0912cdf](https://github.com/moag1000/Little-ISMS-Helper/commit/0912cdf4dc084f7ea6788d73f8f851f945e2abde))
* **i18n:** translate audit_log/statistics + compliance/framework_dashboard headings ([#622](https://github.com/moag1000/Little-ISMS-Helper/issues/622)) ([dda715a](https://github.com/moag1000/Little-ISMS-Helper/commit/dda715ad6fe9ae12e0c71573c928b9ad23ace9ab))
* **i18n:** unify UI term to "Organisation"/"Organization" — System keeps "Tenant" ([ca5fc74](https://github.com/moag1000/Little-ISMS-Helper/commit/ca5fc74130a94f0ae90fb0ea1ddbfaaaa636e9ea))
* **i18n:** wave 12 — Training entity validators + risk-forecast anomaly labels ([#641](https://github.com/moag1000/Little-ISMS-Helper/issues/641)) ([81692ed](https://github.com/moag1000/Little-ISMS-Helper/commit/81692ed1988d6d8b7d74c53a3047fec26ee372a3))
* **i18n:** wave 13 — AI-Agent show + Compliance/BCM table headers (18 keys) ([#643](https://github.com/moag1000/Little-ISMS-Helper/issues/643)) ([7d85145](https://github.com/moag1000/Little-ISMS-Helper/commit/7d8514514f20f544a2d3644c2884c9aa647ae179))
* **i18n:** wave 9 — data_breach dashboard full i18n + remaining hero sections ([#633](https://github.com/moag1000/Little-ISMS-Helper/issues/633)) ([135fdb6](https://github.com/moag1000/Little-ISMS-Helper/commit/135fdb6f2f8e6c04f2d7c37afaa43ee40cde2147))
* **job:** P-16 AsyncJobDispatcher facade (S11/S13) ([#618](https://github.com/moag1000/Little-ISMS-Helper/issues/618)) ([199aad6](https://github.com/moag1000/Little-ISMS-Helper/commit/199aad693e90fef02b8e1e454737775095e78f3c))
* **library:** extract VDA-ISA 6 cross-framework mappings from ENX workbook ([c2b41c3](https://github.com/moag1000/Little-ISMS-Helper/commit/c2b41c3d62a6c77bb5f96920886797b977930563))
* **library:** extract VDA-ISA 6 cross-framework mappings from ENX workbook ([c994898](https://github.com/moag1000/Little-ISMS-Helper/commit/c994898cf8ee586a7ac83b43d592830cf06bf566))
* **library:** extract VDA-ISA 6 cross-framework mappings from ENX workbook ([eda736b](https://github.com/moag1000/Little-ISMS-Helper/commit/eda736bd5d6bf3dd084353692eb73487685ffaf0))
* **lifecycle:** InterestedParty (S-01) + CorrectiveAction forced-verify transition ([#603](https://github.com/moag1000/Little-ISMS-Helper/issues/603)) ([0e4c37c](https://github.com/moag1000/Little-ISMS-Helper/commit/0e4c37cb28ac195766db5c51ead2838cd7d00040))
* **lifecycle:** Phase 2 — NotificationDelivery (6 stages); Tenant deferred ([#635](https://github.com/moag1000/Little-ISMS-Helper/issues/635)) ([f5a2d3a](https://github.com/moag1000/Little-ISMS-Helper/commit/f5a2d3a920fc56ff27f041c7e98eb42424652275))
* **lifecycle:** Phase 2 — Tenant (5 stages, 4-eyes guards, derived isActive) ([#640](https://github.com/moag1000/Little-ISMS-Helper/issues/640)) ([1274c72](https://github.com/moag1000/Little-ISMS-Helper/commit/1274c721593f2bce9feaa066edd2eacc4be00d27))
* **lifecycle:** Phase 2 RBAC — Permission + Role entities ([#634](https://github.com/moag1000/Little-ISMS-Helper/issues/634)) ([2300026](https://github.com/moag1000/Little-ISMS-Helper/commit/23000262a54b4b935dcbe4a3f447146c448c6c79))
* **menu:** Wave 5 sidebar mega-menu + flyout panels + density toggle ([#738](https://github.com/moag1000/Little-ISMS-Helper/issues/738)) ([c3b5390](https://github.com/moag1000/Little-ISMS-Helper/commit/c3b53905248f66487f46a9232a10b4f21cf6177b))
* **modal-wizard:** Aurora high-stakes linear-flow modal foundation ([#537](https://github.com/moag1000/Little-ISMS-Helper/issues/537)) ([d4bb5e7](https://github.com/moag1000/Little-ISMS-Helper/commit/d4bb5e7d61804b0d3271fcebec81e6f4099f0124))
* **nis2:** add Art. 21(2) discoverability — dashboard link + mega-menu entry ([c31f92f](https://github.com/moag1000/Little-ISMS-Helper/commit/c31f92fb930930093d4710df56b88ff31e4e9f7a))
* **nis2:** add Art. 21(2) YAML fixture + idempotent loader command ([6c84b2f](https://github.com/moag1000/Little-ISMS-Helper/commit/6c84b2f7718b89307cc70e97e81d7ab2148c105e))
* **nis2:** Art. 21(2) requirements catalogue template ([9b6a297](https://github.com/moag1000/Little-ISMS-Helper/commit/9b6a297e7b3b69bcaf75b5822590205c2f6751d9))
* **nis2:** i18n keys for Art. 21(2) catalogue (DE + EN) ([9e892d9](https://github.com/moag1000/Little-ISMS-Helper/commit/9e892d9b47f2b788358045b1901c38c112bde83e))
* **nis2:** NIS2 Art. 21(2) first-class ComplianceRequirement catalogue ([b588b7d](https://github.com/moag1000/Little-ISMS-Helper/commit/b588b7d733f10016b4b7cd519a45e865e9af7dd6))
* **nis2:** Nis2Art21CoverageService + art21Requirements route ([58ee165](https://github.com/moag1000/Little-ISMS-Helper/commit/58ee1659bc2a6ae7e207e6ac5dea797a1e35ffa8))
* **norm-bridge:** P-12 rollout to BP/Document/BCM forms — ISO 9001 + 22301 references ([#614](https://github.com/moag1000/Little-ISMS-Helper/issues/614)) ([e3da7ac](https://github.com/moag1000/Little-ISMS-Helper/commit/e3da7ac3a6fae027f483c5d335872e30c8f00e4f))
* **norm-bridge:** show-page rollout for BP/Document/BCM (P-12 follow-up to [#614](https://github.com/moag1000/Little-ISMS-Helper/issues/614)) ([#617](https://github.com/moag1000/Little-ISMS-Helper/issues/617)) ([1770da9](https://github.com/moag1000/Little-ISMS-Helper/commit/1770da94bec995a0e8464a5eace15ac1487a8608))
* **norm-help:** ISO 27005 reference in Risk-Form + A.5.30 ICT-Continuity in BP-Form ([#630](https://github.com/moag1000/Little-ISMS-Helper/issues/630)) ([422d47e](https://github.com/moag1000/Little-ISMS-Helper/commit/422d47e34c7e83555e9c2f15c257da021988aa38))
* **onboarding:** ISB-tour risk step + info-tier risk-score explainer hint ([#786](https://github.com/moag1000/Little-ISMS-Helper/issues/786)) ([f4ba35b](https://github.com/moag1000/Little-ISMS-Helper/commit/f4ba35bc9432960a9d4e25fc49e05d079f1a6619))
* **owner-picker:** default to current user on new (4.11) ([#631](https://github.com/moag1000/Little-ISMS-Helper/issues/631)) ([14018fd](https://github.com/moag1000/Little-ISMS-Helper/commit/14018fd4519d8b771527a83a92e822685f49d1e1))
* **policy-gen:** append binding-parameters annex to generated policies ([#772](https://github.com/moag1000/Little-ISMS-Helper/issues/772)) ([3f53ec4](https://github.com/moag1000/Little-ISMS-Helper/commit/3f53ec473738b8809fc7b71bf8675c45cfe3ed11))
* **policy-gen:** wire policy-parameter values into document generation ([#767](https://github.com/moag1000/Little-ISMS-Helper/issues/767)) ([da19c7c](https://github.com/moag1000/Little-ISMS-Helper/commit/da19c7c39624101169ee57f04800953f50b58f02))
* **policy-params:** ParameterRegisterBuilder (cross-framework audit register) ([#765](https://github.com/moag1000/Little-ISMS-Helper/issues/765)) ([be651e9](https://github.com/moag1000/Little-ISMS-Helper/commit/be651e9ddd006cdc034bd64fb387e8dfc31d7cd4))
* **policy-params:** plan 1/6 — parameter catalog foundation ([#757](https://github.com/moag1000/Little-ISMS-Helper/issues/757)) ([b1ce8e1](https://github.com/moag1000/Little-ISMS-Helper/commit/b1ce8e12f2054036b1b0222f7b878dcdd2222bae))
* **policy-params:** plan 2/6 — industry baselines (4 sectors) ([#759](https://github.com/moag1000/Little-ISMS-Helper/issues/759)) ([7ec0dee](https://github.com/moag1000/Little-ISMS-Helper/commit/7ec0dee4b77de5a6cf0c7af21106a7424cc547b7))
* **policy-params:** plan 3/6 — framework constraints + coverage engine ([#762](https://github.com/moag1000/Little-ISMS-Helper/issues/762)) ([c38cbda](https://github.com/moag1000/Little-ISMS-Helper/commit/c38cbda8934d3ed0d2089ff989e2a310a9ceb63c))
* **policy-params:** plan 4/6 — PolicyProfileManager orchestration facade ([#763](https://github.com/moag1000/Little-ISMS-Helper/issues/763)) ([38ccd92](https://github.com/moag1000/Little-ISMS-Helper/commit/38ccd9255580e0ab598dfdad8a6eee3289407d55))
* **policy-params:** plan 5/6 — generation logic (interpolation + sections) ([#764](https://github.com/moag1000/Little-ISMS-Helper/issues/764)) ([c0cee6f](https://github.com/moag1000/Little-ISMS-Helper/commit/c0cee6f16a648c2ad0d61e1d07465646e3957f8f))
* **policy-profile:** user-facing UI — sector → params + coverage + register ([#766](https://github.com/moag1000/Little-ISMS-Helper/issues/766)) ([16a4cdd](https://github.com/moag1000/Little-ISMS-Helper/commit/16a4cdd76c9a12ac29bc2e48f264866c86496295))
* **quality:** Gate 53 — forbid renamed entity getters in templates (+ fixes) ([#779](https://github.com/moag1000/Little-ISMS-Helper/issues/779)) ([980ad4a](https://github.com/moag1000/Little-ISMS-Helper/commit/980ad4a8dda9a113c19bc46fba04a5e2c086aa8d))
* **quick-fix:** always-visible phantom-migration-skip option on pending screen ([#735](https://github.com/moag1000/Little-ISMS-Helper/issues/735)) ([c61de22](https://github.com/moag1000/Little-ISMS-Helper/commit/c61de223bae9f4bb27f5f5f04c76f2738b697a4e))
* **risk+soa:** treatmentStrategy pflicht (S-02) + applicability-toggle visual required (S-07) ([#604](https://github.com/moag1000/Little-ISMS-Helper/issues/604)) ([e6c1dea](https://github.com/moag1000/Little-ISMS-Helper/commit/e6c1deab1c37d43554e78b2e97038916cf50b701))
* **risk:** Aurora-rework Risk-Heatmap — tokens + fa-drawer + fa-empty-state ([#621](https://github.com/moag1000/Little-ISMS-Helper/issues/621)) ([7e79764](https://github.com/moag1000/Little-ISMS-Helper/commit/7e79764943d79173fa91b866b17cab2ac3db2ef6))
* **risk:** inline 5×5 mini-heatmap in Risk-Form with live-update ([#619](https://github.com/moag1000/Little-ISMS-Helper/issues/619)) ([325cd66](https://github.com/moag1000/Little-ISMS-Helper/commit/325cd66e4f3b57cb3c538952fdf28c75dba713a0))
* **risk:** live mini-heatmap in form — T6.8 UX-P1 ([#610](https://github.com/moag1000/Little-ISMS-Helper/issues/610)) ([fcc4bf3](https://github.com/moag1000/Little-ISMS-Helper/commit/fcc4bf3cd2e6b29247578c8e508342e7db725130))
* **risk:** S10 K-05/K-06 + S11 M-02/M-03 Risk-Form audit-trail + validators + threshold SSoT ([#588](https://github.com/moag1000/Little-ISMS-Helper/issues/588)) ([1633aa9](https://github.com/moag1000/Little-ISMS-Helper/commit/1633aa9104111dcf630d643f93c49fdf5f13fe49))
* **search:** add navigation aliases + expand admin/config route coverage ([ac1d1d9](https://github.com/moag1000/Little-ISMS-Helper/commit/ac1d1d968b71a3b3d64b45b652989a3c6a9b8366))
* **search:** gate entity + navigation results by active modules ([491f5f8](https://github.com/moag1000/Little-ISMS-Helper/commit/491f5f8b6c440e3547c9c274bd88b69f03d264ed))
* **search:** make global-search truly global — 25 new entity categories + navigation targets ([5cda004](https://github.com/moag1000/Little-ISMS-Helper/commit/5cda00442e759b4bfe1551d2688d485cb136ab05))
* **security:** re-auth challenge as fa-modal (multi-provider — password/SSO/SAML) ([#574](https://github.com/moag1000/Little-ISMS-Helper/issues/574)) ([b15a140](https://github.com/moag1000/Little-ISMS-Helper/commit/b15a140728cac3b785cd1ebd92ee9990a041f958))
* **soa:** gate MRIS filter behind module + tenant setting (S14+ §14) ([#657](https://github.com/moag1000/Little-ISMS-Helper/issues/657)) ([b971dc0](https://github.com/moag1000/Little-ISMS-Helper/commit/b971dc0052642cb0e5e92844e733de73f0edfa9b))
* **supplier:** LEI/NACE inline format-hint via HTML5 pattern + title (S14+ §19) ([#659](https://github.com/moag1000/Little-ISMS-Helper/issues/659)) ([60a3be8](https://github.com/moag1000/Little-ISMS-Helper/commit/60a3be87f1ff23a23d9c9b582d121db8017c356e))
* **supplier:** Wave Y.6 Symfony Workflow lifecycle ([#585](https://github.com/moag1000/Little-ISMS-Helper/issues/585)) ([f996c7c](https://github.com/moag1000/Little-ISMS-Helper/commit/f996c7c11d1348f34b2a25ccf84dac69f0d7fd01))
* **table+bce:** row-click → show as default + BCE facilitator validator ([#722](https://github.com/moag1000/Little-ISMS-Helper/issues/722)) ([26c364e](https://github.com/moag1000/Little-ISMS-Helper/commit/26c364eafc51d2d071d0f689aef325e0694d61d0))
* **table:** row-click → show as default also in legacy `_table.html.twig` ([#728](https://github.com/moag1000/Little-ISMS-Helper/issues/728)) ([b6da3f5](https://github.com/moag1000/Little-ISMS-Helper/commit/b6da3f5afcfe997b17a5e856c0a77c2fb3d802f7))
* **tabs:** Aurora settings-form tab-group foundation ([#539](https://github.com/moag1000/Little-ISMS-Helper/issues/539)) ([f00d090](https://github.com/moag1000/Little-ISMS-Helper/commit/f00d090a7535ebba05354cedd8fde02aa3658aba))
* **testing:** L1 persona-driven browser-coverage smoke ([#678](https://github.com/moag1000/Little-ISMS-Helper/issues/678)) ([332ee22](https://github.com/moag1000/Little-ISMS-Helper/commit/332ee2251cfea4003a5722f35d85614b6540a291))
* **tisax:** extract BSI IT-Grundschutz + NIST SP800-53r5 from VDA-ISA col Q ([004bd42](https://github.com/moag1000/Little-ISMS-Helper/commit/004bd423f92f4b5b77676cc938cb39583aa5a66a))
* **tisax:** extract pre-filled Reifegrad from VDA-ISA workbook into ComplianceRequirement.maturityCurrent ([#746](https://github.com/moag1000/Little-ISMS-Helper/issues/746)) ([e9abbb0](https://github.com/moag1000/Little-ISMS-Helper/commit/e9abbb0404fd3abe90f990c5df809baced546d6a))
* **tisax:** organisation-mismatch warning + Reifegrad diff/selective-overwrite, fix pre-filled Reifegrad import ([#749](https://github.com/moag1000/Little-ISMS-Helper/issues/749)) ([fb9cb25](https://github.com/moag1000/Little-ISMS-Helper/commit/fb9cb25336512a7c0850460854fc1cf7f52440ce))
* **tisax:** per-tier assessment models — IS=Reifegrad, PP=binary, DP=GDPR ([e2cbdc3](https://github.com/moag1000/Little-ISMS-Helper/commit/e2cbdc37b7ef9fc28b5f5dfa89a09a93bde00aa9))
* **tisax:** propagate per-tier assessment stats to all persona dashboards + KPI snapshots ([e32e665](https://github.com/moag1000/Little-ISMS-Helper/commit/e32e665a1442b266f7524f95d6044b95b56c3cf0))
* **tisax:** TISAX BYO VDA-ISA import wizard with Reifegrad 0-5 assessment ([7c0ab60](https://github.com/moag1000/Little-ISMS-Helper/commit/7c0ab608a8067c46d38d4234b6353c778724863f))
* **tisax:** upgrade VDA-ISA→ISO 27001:2022 mapping to authoritative 83-control coverage ([f32ab9f](https://github.com/moag1000/Little-ISMS-Helper/commit/f32ab9fc1993d9d5ae309a1409b5176f8254e878))
* **tisax:** upgrade VDA-ISA→ISO 27001:2022 mapping to authoritative 83-control coverage ([b837161](https://github.com/moag1000/Little-ISMS-Helper/commit/b837161e930605f185d2a5a5ade0ab5722da13d9))
* **tisax:** VDA-ISA 6 requirement-level metadata extraction + Schutzbedarf cascade ([819184f](https://github.com/moag1000/Little-ISMS-Helper/commit/819184f8d2c3cd063a77fa739873edd2c40e3a67))
* **training:** materials File-Upload + attendeeCount auto-sync ([#632](https://github.com/moag1000/Little-ISMS-Helper/issues/632)) ([b64f760](https://github.com/moag1000/Little-ISMS-Helper/commit/b64f76004f0d37649a95a5d776b9e77105d8e35c))
* **ui:** Aurora-refactor global-search — cyber-frame, fa-kbd, accent highlights, brand-bar ([4981b1d](https://github.com/moag1000/Little-ISMS-Helper/commit/4981b1dd417de885b8b06a9d64c1ebca9af52af6))
* **ux:** P-13 SeverityDefinitions — read-only severity-stage legend below form fields ([#609](https://github.com/moag1000/Little-ISMS-Helper/issues/609)) ([423b871](https://github.com/moag1000/Little-ISMS-Helper/commit/423b871f4b19e32ddd81700f0bd9712684ba0db5))
* **ux:** Phase 3.1 + 3.4 — menu-density persist + persona quick-switcher ([5c41e85](https://github.com/moag1000/Little-ISMS-Helper/commit/5c41e8562f175476c5e0a41a749fa98b079faaf6))
* **ux:** T6.3 — section-intro alerts for Brutto/Netto on Risk-form ([#612](https://github.com/moag1000/Little-ISMS-Helper/issues/612)) ([5b877db](https://github.com/moag1000/Little-ISMS-Helper/commit/5b877db8ab5cf35ae337055f98b851a3ca3763b8))
* **ux:** UX_SCORING sweep — T4.11/T9.10/T10.10/T2.13 quick polish ([#611](https://github.com/moag1000/Little-ISMS-Helper/issues/611)) ([77de12d](https://github.com/moag1000/Little-ISMS-Helper/commit/77de12d64a84b4a536d72e844a0b4adee0e641d5))
* **ux:** wave 10 bundle — BCP RTO/RPO context + Tenant wording + Holding-Gate ([#636](https://github.com/moag1000/Little-ISMS-Helper/issues/636)) ([e1572c2](https://github.com/moag1000/Little-ISMS-Helper/commit/e1572c226b489b41e34499380d5db978deea6513))
* **ux:** wave 11 bundle — T7.5 + monetaryValue deprecation + T17 tooltip compress ([#638](https://github.com/moag1000/Little-ISMS-Helper/issues/638)) ([aaf1a0c](https://github.com/moag1000/Little-ISMS-Helper/commit/aaf1a0c7498cfc943893796b2e54ebb73a745186))
* **ux:** wave 14 — Norm-Bridge P-12 rollout to Incident/AuditFinding/CAPA/Training/Asset ([#646](https://github.com/moag1000/Little-ISMS-Helper/issues/646)) ([429dc7d](https://github.com/moag1000/Little-ISMS-Helper/commit/429dc7df5e80165714b25b54f735b0f73b1be941))
* **ux:** wave 15 — solve remaining open UX_SCORING items (T4.2 first, more incoming) ([#647](https://github.com/moag1000/Little-ISMS-Helper/issues/647)) ([12e1ac8](https://github.com/moag1000/Little-ISMS-Helper/commit/12e1ac8c8e98c4182d9f6275e55b92e5e0eeb952))
* **ux:** wave 7 — section-intros, dashboards i18n, Junior-ISB-Audit polish (10+ items) ([#627](https://github.com/moag1000/Little-ISMS-Helper/issues/627)) ([2df2750](https://github.com/moag1000/Little-ISMS-Helper/commit/2df2750017534dc52731834aecc2c6a4ce15fa1a))
* **ux:** wave 8 — responsible_person clarity + asset-type dropdown hints ([#628](https://github.com/moag1000/Little-ISMS-Helper/issues/628)) ([4c2011e](https://github.com/moag1000/Little-ISMS-Helper/commit/4c2011ea4bdcc2ae84861063871cdea782f6635f))
* **vvt:** expose processorSuppliers in ProcessingActivityType (Closes K-02) ([#584](https://github.com/moag1000/Little-ISMS-Helper/issues/584)) ([05a538a](https://github.com/moag1000/Little-ISMS-Helper/commit/05a538ae888ce3e39b70b89289a4e6ae83a1cd1c))
* **vvt:** Joint Controller selection conditional on isJointController (Closes M-08) ([#590](https://github.com/moag1000/Little-ISMS-Helper/issues/590)) ([b4829de](https://github.com/moag1000/Little-ISMS-Helper/commit/b4829de1719d637361da26883d0733b4abd74f4a))
* **wcag+live-badges:** Phase 4.1 WCAG 2.2 AA keyboard-nav + Phase 4.4 live-badge polling ([d4c7021](https://github.com/moag1000/Little-ISMS-Helper/commit/d4c702101b7f15a216fbcd40eed54c3a0e80d65f))
* **wizard:** seed M-04/M-05/M-06 templates + wire selector on new forms ([#593](https://github.com/moag1000/Little-ISMS-Helper/issues/593)) ([2b112b9](https://github.com/moag1000/Little-ISMS-Helper/commit/2b112b9d7a4cea07441df1635688d7013085f120))


### Fixed

* **admin-hub:** 3 dead links (500/404/404) on hover-prefetch ([#692](https://github.com/moag1000/Little-ISMS-Helper/issues/692)) ([87a230f](https://github.com/moag1000/Little-ISMS-Helper/commit/87a230fb521b91d18b9f5ea4a095989e39b14130))
* **admin+email:** 4 server-500s from pre-release browser sweep + mapfile tooling ([#750](https://github.com/moag1000/Little-ISMS-Helper/issues/750)) ([44fa3ae](https://github.com/moag1000/Little-ISMS-Helper/commit/44fa3aefbe5d41068b679fe5c1e58a8d760a13a9))
* **admin:** resolveAdminScope falls back to current tenant for SUPER_ADMIN ([#719](https://github.com/moag1000/Little-ISMS-Helper/issues/719)) ([75ff462](https://github.com/moag1000/Little-ISMS-Helper/commit/75ff462b1859bfc7565f029f451d29d729159c19))
* **admin:** return 422 not 200 when re-rendering a submitted form ([#752](https://github.com/moag1000/Little-ISMS-Helper/issues/752)) ([317cc60](https://github.com/moag1000/Little-ISMS-Helper/commit/317cc60c3aaefc2be1140897136d12117d2b4793))
* **alva:** align LifecycleStuckInStatusRule keys to alva_hint.* (was global.*) ([eb3a099](https://github.com/moag1000/Little-ISMS-Helper/commit/eb3a0990ee2b6ac83529342f730405335f8b6af2))
* **alva:** pass bodyTranslationParams to titleTranslationKey ([abdc1da](https://github.com/moag1000/Little-ISMS-Helper/commit/abdc1da5f2486ec9c830590b4bf8e7e72a47a3e1))
* **analytics:** readable KPI numbers in compliance roadmap tiles ([#796](https://github.com/moag1000/Little-ISMS-Helper/issues/796)) ([b6fdc4d](https://github.com/moag1000/Little-ISMS-Helper/commit/b6fdc4dcd26839ee7e272969eecf49bfe16b044b))
* **asset:** validateOwnerSlot parity in AssetQuickType (S13) ([#623](https://github.com/moag1000/Little-ISMS-Helper/issues/623)) ([3bc2fe8](https://github.com/moag1000/Little-ISMS-Helper/commit/3bc2fe87170cc2235eee2368a249765a3ced7ef4))
* **audit-program:** remove duplicate _locale prefix from class-Route ([8d90277](https://github.com/moag1000/Little-ISMS-Helper/commit/8d9027700e14d3979089a2b706714eec3270700e))
* **audit,dora:** close 3 gaps surfaced by post-merge review ([0faeb17](https://github.com/moag1000/Little-ISMS-Helper/commit/0faeb17e0cad179b185190e8059b82c4d4589d82))
* **audit:** bulk follow-up — PII RBAC + Stimulus icons + tabs template (H-1/H-2/H-4) ([#545](https://github.com/moag1000/Little-ISMS-Helper/issues/545)) ([81cecca](https://github.com/moag1000/Little-ISMS-Helper/commit/81cecca66cae5717ca9a94ae491b831ebb68dd82))
* **audit:** render scopedAssets in audit new/edit basic_info ([#691](https://github.com/moag1000/Little-ISMS-Helper/issues/691)) ([050766b](https://github.com/moag1000/Little-ISMS-Helper/commit/050766b7d120d4def4b091167bcf50ab1c3b7675))
* **aurora:** align CSS with canonical design-system spec ([#535](https://github.com/moag1000/Little-ISMS-Helper/issues/535)) ([06b7fab](https://github.com/moag1000/Little-ISMS-Helper/commit/06b7fab69424ad11dfa7842b63aeb7444423a4dc))
* **aurora:** fa-modal.confirm() — no &lt;form&gt; when formAction omitted ([#562](https://github.com/moag1000/Little-ISMS-Helper/issues/562)) ([a5f9a85](https://github.com/moag1000/Little-ISMS-Helper/commit/a5f9a8544d47a4153fc214c5769f1014728d97dc))
* **browser:** dashboard 404 + bulkDeleteModal a11y + console-noise cleanup ([#563](https://github.com/moag1000/Little-ISMS-Helper/issues/563)) ([32e46e8](https://github.com/moag1000/Little-ISMS-Helper/commit/32e46e81764d2faa59d1015374a84696e5f97df5))
* **bulk-actions:** bar clicks broken after teleport — switch to CSS-based stacking-context escape ([#564](https://github.com/moag1000/Little-ISMS-Helper/issues/564)) ([d31f3c1](https://github.com/moag1000/Little-ISMS-Helper/commit/d31f3c13148aa3a8bbe4eb2cd0e9792dd96f7e07))
* **business-process:** make process_owner nullable to support Pattern A owners ([#704](https://github.com/moag1000/Little-ISMS-Helper/issues/704)) ([01c99e2](https://github.com/moag1000/Little-ISMS-Helper/commit/01c99e26ab2c4813b1877a644e882240248a460e))
* **charts:** drop public Chart.js CDN, use the registered app.js global ([#792](https://github.com/moag1000/Little-ISMS-Helper/issues/792)) ([b587b82](https://github.com/moag1000/Little-ISMS-Helper/commit/b587b824573cb3db96bfc24f252f55df3db2e321))
* **ci:** auto-dispatch ci.yml on release-please tag creation ([#679](https://github.com/moag1000/Little-ISMS-Helper/issues/679)) ([178d15f](https://github.com/moag1000/Little-ISMS-Helper/commit/178d15feb2fbb291219c53a8e23f09779eed553b))
* **ci:** bump Gate-23 dynamic-key prefix registry (parallel-agent drift) ([99e7520](https://github.com/moag1000/Little-ISMS-Helper/commit/99e75209ab183f79d0dcdb12294cf0b9af272477))
* **ci:** clear Gate-16 bi-* regressions + PHPUnit failures from parallel agents ([5912a0c](https://github.com/moag1000/Little-ISMS-Helper/commit/5912a0cd24cdd52dc93989c6f03c22ace2482020))
* **ci:** Gate 43 backup coverage + LifecycleStuckInStatusRule test keys ([8ae3502](https://github.com/moag1000/Little-ISMS-Helper/commit/8ae350214de804e70c9aa9e0fb6b1f43f54fcfb0))
* **ci:** grant actions:write to release-please-auto-merge ([#675](https://github.com/moag1000/Little-ISMS-Helper/issues/675)) ([8669a85](https://github.com/moag1000/Little-ISMS-Helper/commit/8669a85a107143028e8eb892182ce69ae3c77e80))
* **ci:** persist APP_SECRET to .env.dev.local for E2E ([#677](https://github.com/moag1000/Little-ISMS-Helper/issues/677)) ([074ddcf](https://github.com/moag1000/Little-ISMS-Helper/commit/074ddcfc6ba670612dfe4dd4f893f233289716d1))
* **ci:** refresh god-class baseline + voter test factory for new requestStack arg ([8e0657a](https://github.com/moag1000/Little-ISMS-Helper/commit/8e0657ab6bb155a88ac20f16432717d43cd47d38))
* **ci:** update god-class baseline after DataIntegrityService split ([5aaf8c4](https://github.com/moag1000/Little-ISMS-Helper/commit/5aaf8c417dc6f515f09dc583ea1999c80c43c88f))
* **clone:** preserve NOT NULL fields on Document + Training clones; add L2 scenarios ([e7aa29d](https://github.com/moag1000/Little-ISMS-Helper/commit/e7aa29de4e48d05e15cc86415ea3bf4cd4648e16))
* **cloners:** [@phpstan-ignore](https://github.com/phpstan-ignore) lifecycle.directSetStatus on 8 clone setStatus calls ([#731](https://github.com/moag1000/Little-ISMS-Helper/issues/731)) ([85e4fcf](https://github.com/moag1000/Little-ISMS-Helper/commit/85e4fcf9fb121d05a516ff7a3b9ee306caa4c9d8))
* **compliance-wizard:** gap-report 500 — pass effort_table + summary vars ([#754](https://github.com/moag1000/Little-ISMS-Helper/issues/754)) ([38a9f82](https://github.com/moag1000/Little-ISMS-Helper/commit/38a9f820cbac737fc3d423dcd431282b6a479e5e))
* **compliance-wizard:** proper fix for consent-coverage test (unskip) ([#548](https://github.com/moag1000/Little-ISMS-Helper/issues/548)) ([f36f64d](https://github.com/moag1000/Little-ISMS-Helper/commit/f36f64d2262676627c38d05e09be99f4d48eb789))
* **compliance:** correct NIS2 Art.21(2) sub-letters + NIS2UmsuCG §-citations ([#794](https://github.com/moag1000/Little-ISMS-Helper/issues/794)) ([be44bec](https://github.com/moag1000/Little-ISMS-Helper/commit/be44bec13fbabb6c930d7140e93e15272fbf5c60))
* **compliance:** EU-mapping Wave 2 — re-target GDPR data-subject-rights to correct ISO 27701 controls ([#768](https://github.com/moag1000/Little-ISMS-Helper/issues/768)) ([9e2f872](https://github.com/moag1000/Little-ISMS-Helper/commit/9e2f872ee22e6a3d0706408f475063e43b9946ca))
* **compliance:** EU-mapping Wave 3 — de-overstate + re-pair NIS2/DORA ([#769](https://github.com/moag1000/Little-ISMS-Helper/issues/769)) ([e119e83](https://github.com/moag1000/Little-ISMS-Helper/commit/e119e830a9112084b1eb41c99bf89d67dec24ee3))
* **compliance:** make ISO 27001 Clauses 4-10 reachable + fix C5:2026 GC criteria loss ([#798](https://github.com/moag1000/Little-ISMS-Helper/issues/798)) ([965f076](https://github.com/moag1000/Little-ISMS-Helper/commit/965f07645368892cf3eb80923bb62052c31719a5))
* **compliance:** privacy-audit corrections + resolve UNVERIFIED sub-clauses ([#781](https://github.com/moag1000/Little-ISMS-Helper/issues/781)) ([88a176f](https://github.com/moag1000/Little-ISMS-Helper/commit/88a176f888ca9e3143dfd9272ebd4e782d18d0a8))
* **compliance:** reconcile GDPR mapping IDs to catalogue scheme (de-orphan) ([#805](https://github.com/moag1000/Little-ISMS-Helper/issues/805)) ([9b29489](https://github.com/moag1000/Little-ISMS-Helper/commit/9b29489dac94682f5eebb2a4b7797fad5adb0501))
* **cs-p0:** salvage 3 fixes from quick-wins worktree ([#674](https://github.com/moag1000/Little-ISMS-Helper/issues/674)) ([8c062fa](https://github.com/moag1000/Little-ISMS-Helper/commit/8c062fafc400819e2b1b147b388337c4482f5290))
* **dashboard:** customizer modal toggles didn't persist widget on/off ([#565](https://github.com/moag1000/Little-ISMS-Helper/issues/565)) ([cd983c5](https://github.com/moag1000/Little-ISMS-Helper/commit/cd983c564dc2ef85b5f545487375246e97de0481))
* **document:** resolve /document/new GET 500 — L1+L2 sweep ([#716](https://github.com/moag1000/Little-ISMS-Helper/issues/716)) ([80dc227](https://github.com/moag1000/Little-ISMS-Helper/commit/80dc227145933786abda281518be711cb3338bb1))
* **e2e:** repair stale L2 scenarios — align field selectors with current forms ([bd66c89](https://github.com/moag1000/Little-ISMS-Helper/commit/bd66c899d23a63d888838ef62219bdc5c4135998))
* **entity:** add __toString() to Tenant to fix DoctrineType form errors ([bde1c53](https://github.com/moag1000/Little-ISMS-Helper/commit/bde1c53c1d255c48482b746339e6bcd38b6461a7))
* **entity:** add inversedBy on TrainingParticipation.training ([4525bdf](https://github.com/moag1000/Little-ISMS-Helper/commit/4525bdf6e6972f6f492302c7ab3a9b8a815195ad))
* **entity:** relax 55 DateTime setters to accept null (match nullable property) ([#706](https://github.com/moag1000/Little-ISMS-Helper/issues/706)) ([439f0e5](https://github.com/moag1000/Little-ISMS-Helper/commit/439f0e5ccb53165fe777cd72b38f351d220f4130))
* **form-layout:** always render section bodies — collapse is visual-only ([#542](https://github.com/moag1000/Little-ISMS-Helper/issues/542)) ([def9867](https://github.com/moag1000/Little-ISMS-Helper/commit/def98672c5a9a2d86a394a655025001b2b8ca880))
* **form-layout:** emit sectionsState as object, not array of single-key objects ([#561](https://github.com/moag1000/Little-ISMS-Helper/issues/561)) ([58df506](https://github.com/moag1000/Little-ISMS-Helper/commit/58df506a524d7b2edcbc94f0354f3c4e93787f14))
* **form-validation:** reveal collapsed sections + inactive tabs before focus ([#718](https://github.com/moag1000/Little-ISMS-Helper/issues/718)) ([4567431](https://github.com/moag1000/Little-ISMS-Helper/commit/4567431617e98c76661630d4074a9a56794b8235))
* **forms+console:** translations + regex + wizard targets + doc 500 + notifications ([#693](https://github.com/moag1000/Little-ISMS-Helper/issues/693)) ([3645796](https://github.com/moag1000/Little-ISMS-Helper/commit/364579661d7ffded758be4c74c0f38674a5f9dd7))
* **forms+ux:** disabled-status validation 422, purposes focusable, activity-feed empty ([#707](https://github.com/moag1000/Little-ISMS-Helper/issues/707)) ([075c934](https://github.com/moag1000/Little-ISMS-Helper/commit/075c93473dcad4ee5f391f7c319afd84ea1fe17c))
* **forms:** label-raw, risk-heatmap-extras, bc-targets/owner/sdm i18n ([#690](https://github.com/moag1000/Little-ISMS-Helper/issues/690)) ([8d4892c](https://github.com/moag1000/Little-ISMS-Helper/commit/8d4892ca65ce4d4bf7b568c758dc2c58bd479f38))
* **forms:** skip phantom sections with all module-gated-away fields ([#689](https://github.com/moag1000/Little-ISMS-Helper/issues/689)) ([ccf504b](https://github.com/moag1000/Little-ISMS-Helper/commit/ccf504b2b408b630959e0cb1168590fad1506db2))
* **forms:** unmapped fields no longer escape after submit/cancel ([#683](https://github.com/moag1000/Little-ISMS-Helper/issues/683)) ([048201c](https://github.com/moag1000/Little-ISMS-Helper/commit/048201c9092c545cfd1751ed74f656ac52fcc3ff))
* **fte-chart:** use global window.Chart instead of unmapped chart.js/auto ([#770](https://github.com/moag1000/Little-ISMS-Helper/issues/770)) ([bbace4d](https://github.com/moag1000/Little-ISMS-Helper/commit/bbace4d2e0b09607527e41311c329542faa17e25))
* **i18n+tisax:** add library.import_super_admin_only + ENX-notice keys ([dafa123](https://github.com/moag1000/Little-ISMS-Helper/commit/dafa123d72242ba766c4ea7bc8221605e5840125))
* **i18n:** add 30 missing validator translations (6 entity clusters) ([b8b452e](https://github.com/moag1000/Little-ISMS-Helper/commit/b8b452e411ca7c9f6b1b7ac93cfe573c0983ba1b))
* **i18n:** add missing web-profiler keys + remove double-translation in CoverageCheckService ([dc88209](https://github.com/moag1000/Little-ISMS-Helper/commit/dc882092c8fcd2ac172cbfcddd7549bd6a9ede0c))
* **i18n:** align validator tone to terse imperative + use English enum labels ([fd3e26b](https://github.com/moag1000/Little-ISMS-Helper/commit/fd3e26b461f56056c9411f86cb72ab91140d92e1))
* **i18n:** align validator tone to terse imperative + use English enum labels ([a371f2f](https://github.com/moag1000/Little-ISMS-Helper/commit/a371f2f5f11f50f903f2195fa00b0a3fa8c60c3a))
* **i18n:** apply DE tone + enum-label fix (companion to a371f2f5f) ([1a0cb27](https://github.com/moag1000/Little-ISMS-Helper/commit/1a0cb27f3b6235268edee0100f97951bf2dcd3a8))
* **i18n:** apply DE tone + enum-label fix (companion to a371f2f5f) ([32eb7fb](https://github.com/moag1000/Little-ISMS-Helper/commit/32eb7fb4d6d81c5cb63a7eff58ff76647d847978))
* **i18n:** asset.help.status_readonly translation ([#703](https://github.com/moag1000/Little-ISMS-Helper/issues/703)) ([b957dcd](https://github.com/moag1000/Little-ISMS-Helper/commit/b957dcd188537dee79df6acb5212494a2592d674))
* **i18n:** audit + fix awkward German translations — ISO-27001-DE wording ([5f2cbb1](https://github.com/moag1000/Little-ISMS-Helper/commit/5f2cbb1aa071389e310b06e10faee6e95d292923))
* **i18n:** audit + fix awkward German translations — ISO-27001-DE wording ([e2a025c](https://github.com/moag1000/Little-ISMS-Helper/commit/e2a025cd3bb9b3b6dbbf747d39b13e04c11f48b1))
* **i18n:** comprehensive wizard translation audit + backfill ([#702](https://github.com/moag1000/Little-ISMS-Helper/issues/702)) ([9cf641e](https://github.com/moag1000/Little-ISMS-Helper/commit/9cf641ef40e8945cb8563e55b7c88a82e13e3f2b))
* **i18n:** inline 6 GDPR Art. 6(1) legal_basis values in help text ([#662](https://github.com/moag1000/Little-ISMS-Helper/issues/662)) ([0ae96c9](https://github.com/moag1000/Little-ISMS-Helper/commit/0ae96c9603eb35149654f4569af3356088adea8b))
* **i18n:** IP communication + feedback fields — 'what if empty' help ([#663](https://github.com/moag1000/Little-ISMS-Helper/issues/663)) ([b73aa4d](https://github.com/moag1000/Little-ISMS-Helper/commit/b73aa4d79aaa026eaaf3feee565a33a8bd13a4a4))
* **i18n:** make Stimulus controllers locale-aware (audit C-4 + H-1) ([#530](https://github.com/moag1000/Little-ISMS-Helper/issues/530)) ([bd24653](https://github.com/moag1000/Little-ISMS-Helper/commit/bd246534e4d70aadade30b603a49476470c32aba))
* **i18n:** merge duplicate 'col:' blocks in tisax_isa.de.yaml assess: section ([aed5537](https://github.com/moag1000/Little-ISMS-Helper/commit/aed5537f3760241a1784b505f578e2906a14aba8))
* **i18n:** point global-search aria-label to leaf key ui.keyboard.shortcuts.title ([2914368](https://github.com/moag1000/Little-ISMS-Helper/commit/2914368eadb75140f776e63ae4ffc6e750919f75))
* **i18n:** residual_probability/impact help — empty-vs-KPI behavior ([#666](https://github.com/moag1000/Little-ISMS-Helper/issues/666)) ([aa2e05d](https://github.com/moag1000/Little-ISMS-Helper/commit/aa2e05d910ed15bbb7f6dd1cd5e2b5aa20c0bc40))
* **i18n:** route flash messages through dedicated translation domains (H-06) ([#801](https://github.com/moag1000/Little-ISMS-Helper/issues/801)) ([ff13c6b](https://github.com/moag1000/Little-ISMS-Helper/commit/ff13c6b6e693d945bc0b1d08e69beec58b653639))
* **i18n:** translate top-level 'Shareholder' EN-in-DE leftover ([#661](https://github.com/moag1000/Little-ISMS-Helper/issues/661)) ([c2993e1](https://github.com/moag1000/Little-ISMS-Helper/commit/c2993e1c936a670d281d499f75ff10538608a16f))
* **i18n:** UX Wave A — add 54 missing translation keys + fix wrong-domain refs ([#578](https://github.com/moag1000/Little-ISMS-Helper/issues/578)) ([3db57bd](https://github.com/moag1000/Little-ISMS-Helper/commit/3db57bd0b01f7cd1ec73cbaa985c7ae491a1ee94))
* **incident:** default detectedAt to now() (Closes K-01) ([#587](https://github.com/moag1000/Little-ISMS-Helper/issues/587)) ([f6da29e](https://github.com/moag1000/Little-ISMS-Helper/commit/f6da29e11678a7a4ecb97de09ff4b43c00352d8e))
* **incident:** restore &lt;form&gt; wrapper in incident/new — L2-sweep found 55 orphan inputs ([#715](https://github.com/moag1000/Little-ISMS-Helper/issues/715)) ([0aaba7d](https://github.com/moag1000/Little-ISMS-Helper/commit/0aaba7d9740b4c5d794eecdce593e8e8f6b1bc60))
* **jobs:** 7 async exports stuck at 'pending' — route through JobDispatcher (+ Gate 57) ([#806](https://github.com/moag1000/Little-ISMS-Helper/issues/806)) ([2d31a94](https://github.com/moag1000/Little-ISMS-Helper/commit/2d31a9460e76810194ceaedb3ca2d01110705e41))
* **kpi:** add missing kpi_threshold.flash.no_tenant translation + confirm tenant-gate intent ([#717](https://github.com/moag1000/Little-ISMS-Helper/issues/717)) ([12ad665](https://github.com/moag1000/Little-ISMS-Helper/commit/12ad6659b81707b6c688bc41dc275168c49911c5))
* **library-ui:** TISAX uses BYO-Wizard instead of YAML-Library-Import ([3ee115c](https://github.com/moag1000/Little-ISMS-Helper/commit/3ee115c37c54e208240dc5ada500a2c18a6ec51b))
* **library:** BYO-Wizard CTA route — was self-loop, now → app_tisax_import_disclaimer ([d999caf](https://github.com/moag1000/Little-ISMS-Helper/commit/d999caf7996a80c66c6da9c0ef88454951b26d62))
* **lifecycle:** YAML auto-progression field-name corrections (audit C-1) ([#532](https://github.com/moag1000/Little-ISMS-Helper/issues/532)) ([f660d12](https://github.com/moag1000/Little-ISMS-Helper/commit/f660d12d3e42d5125733e4dee279df8665444c72))
* **logs:** clear dev.log critical + deprecation warnings ([f1a00a5](https://github.com/moag1000/Little-ISMS-Helper/commit/f1a00a579e90e5575b750044a53039b30e8eda6a))
* **menu:** add isPartOfCorporateStructure guard to Portfolio L1 + Konzern-Reports links ([#734](https://github.com/moag1000/Little-ISMS-Helper/issues/734)) ([0e6ca7a](https://github.com/moag1000/Little-ISMS-Helper/commit/0e6ca7a7c45f9167cf9b70442dc876b6dfdf9a8c))
* **menu:** add Wave 5 missing translation keys (Bug 1) ([7ab7987](https://github.com/moag1000/Little-ISMS-Helper/commit/7ab7987e94d17b9ff30a66893101703e229a6f39))
* **menu:** flyout z-index + responsive sidebar-offset ([5458523](https://github.com/moag1000/Little-ISMS-Helper/commit/54585233289f53a3678680ee68a993dce996e216))
* **menu:** persona-followup — tree-holding-gate + tour-module-aware + MRIS-gate + CM-board ([#736](https://github.com/moag1000/Little-ISMS-Helper/issues/736)) ([1ed8dce](https://github.com/moag1000/Little-ISMS-Helper/commit/1ed8dced620aa0ac3059e7a93d434ae49528d107))
* **menu:** sb-link dark-mode background + flyout position alignment (Bug 2+3) ([acb51b8](https://github.com/moag1000/Little-ISMS-Helper/commit/acb51b8276ff23afb70d4a87206cd1138363f245))
* **modules:** mark objectives as required + remove phantom module gates ([#665](https://github.com/moag1000/Little-ISMS-Helper/issues/665)) ([6162f3c](https://github.com/moag1000/Little-ISMS-Helper/commit/6162f3c3c2de4c3d394d2bb4aa3d3a67483a1d62))
* **nav:** correct main-area highlight via single ordered nav-active resolver ([#788](https://github.com/moag1000/Little-ISMS-Helper/issues/788)) ([a5c8572](https://github.com/moag1000/Little-ISMS-Helper/commit/a5c8572460d811fb6ae652b12382db1755ed3748))
* **nav:** repair 54 breadcrumbs — dead-link keys + wrong main-area roots ([#789](https://github.com/moag1000/Little-ISMS-Helper/issues/789)) ([f44c05a](https://github.com/moag1000/Little-ISMS-Helper/commit/f44c05a4807c5e809339894402a96d6e254a3121))
* **nis2:** add ModuleGatedControllerTrait + checkModuleActive('nis2_dora') guard ([c6e8af9](https://github.com/moag1000/Little-ISMS-Helper/commit/c6e8af913a0bdc3c0195a366bb34652eb39abc4a))
* **nis2:** align Art. 21(2)(a)-(j) clauseRefs + ISO anchors to directive ([5c1b14c](https://github.com/moag1000/Little-ISMS-Helper/commit/5c1b14c30aee15c8348624d8002ec9b483e3075b))
* **nis2:** directive-correct Art. 21(2)(a)-(j) 10-letter alignment ([ae72526](https://github.com/moag1000/Little-ISMS-Helper/commit/ae7252663e3909de910d0ac85ad51c18c27b4c8b))
* **nis2:** re-align nis2-art21_to_iso27001-2022 YAML to directive-correct letters (v2) ([ee8666f](https://github.com/moag1000/Little-ISMS-Helper/commit/ee8666f99ec3e13a1f71e1451bc1df9050f134f7))
* **nis2:** re-align nis2-art21_to_iso27001-2022 YAML to directive-correct letters (v2) ([0f393c0](https://github.com/moag1000/Little-ISMS-Helper/commit/0f393c077449a0a0a2db6cc08c60a60ad7d11f55))
* **nis2:** re-align Nis2Art21CoverageService LETTER_KEY_MAP to directive-correct 10-letter grid ([411a381](https://github.com/moag1000/Little-ISMS-Helper/commit/411a3814c7f9ade99509a3f6b5ab1ad8ff3145d8))
* **nis2:** re-align Nis2Art21CoverageService LETTER_KEY_MAP to directive-correct 10-letter grid ([f5000e7](https://github.com/moag1000/Little-ISMS-Helper/commit/f5000e75454479ea374b7f04e2bc803c3bae9b26))
* **nis2:** re-key getDashboardPayload to directive-correct Art. 21(2)(a)-(j) ([64da8a1](https://github.com/moag1000/Little-ISMS-Helper/commit/64da8a175cc5760fd2125b28d37e89a3e58fb4d6))
* **nis2:** re-key getDashboardPayload to directive-correct Art. 21(2)(a)-(j) ([516deeb](https://github.com/moag1000/Little-ISMS-Helper/commit/516deeb6d56d52185a1f5bce34c8248090a2d4f6))
* **nis2:** re-key getDashboardPayload to directive-correct Art. 21(2)(a)-(j) ([6922683](https://github.com/moag1000/Little-ISMS-Helper/commit/692268342c653014e2bce04df9672b50dbb1743a))
* **nis2:** update entity Art. 21.2 comments to directive-correct letters ([d08a961](https://github.com/moag1000/Little-ISMS-Helper/commit/d08a9619077f816aac031e8fce58d9e114416379))
* **nis2:** update entity Art. 21.2 comments to directive-correct letters ([9cc8ea4](https://github.com/moag1000/Little-ISMS-Helper/commit/9cc8ea4b944adb5e9df641c69099db8e3a26cf23))
* **patch:** NotBlank validation on 5 NOT-NULL columns (prevent flush-time 500) ([#709](https://github.com/moag1000/Little-ISMS-Helper/issues/709)) ([2b7430b](https://github.com/moag1000/Little-ISMS-Helper/commit/2b7430b36759def441bbd3b02db1c85f0a52f76b))
* **patterns:** cross-codebase sweep of known bug patterns (A/B/C/H) ([#712](https://github.com/moag1000/Little-ISMS-Helper/issues/712)) ([4d3ed1b](https://github.com/moag1000/Little-ISMS-Helper/commit/4d3ed1b90ed917ca062e8594f29edbd8626f2bd2))
* **privacy:** Aurora-conform GDPR data-breach wizard modal ([#531](https://github.com/moag1000/Little-ISMS-Helper/issues/531)) ([175f2e5](https://github.com/moag1000/Little-ISMS-Helper/commit/175f2e5baaa4f9c9b5f98c612a4e96b2a87fc7a2))
* **quality:** annotate 3 generic throws with [@intentional-assertion](https://github.com/intentional-assertion) ([8d6068f](https://github.com/moag1000/Little-ISMS-Helper/commit/8d6068f8736ee79acdb5512169a1b4587f8bfcb5))
* **quality:** foundation tripped Gate 22 + Gate 43 (merged red via [#757](https://github.com/moag1000/Little-ISMS-Helper/issues/757)) ([#760](https://github.com/moag1000/Little-ISMS-Helper/issues/760)) ([84d5ac1](https://github.com/moag1000/Little-ISMS-Helper/commit/84d5ac1b74c4798db3d43c3b37e552449339f07c))
* **quick-fix:** CIDR support + validation for the Quick-Fix IP allowlist ([#777](https://github.com/moag1000/Little-ISMS-Helper/issues/777)) ([57fbed3](https://github.com/moag1000/Little-ISMS-Helper/commit/57fbed35e6a920c0bc82ef1135f5453e2dfd3045))
* **quick-fix:** make markAllPhantomDiff actually mark phantom migrations ([#776](https://github.com/moag1000/Little-ISMS-Helper/issues/776)) ([2e18666](https://github.com/moag1000/Little-ISMS-Helper/commit/2e186664caccf414c3bda2748451f3aaf1dc7ca0))
* **quick-fix:** restore phantom-diff recovery after async-job split ([#729](https://github.com/moag1000/Little-ISMS-Helper/issues/729)) ([a77e565](https://github.com/moag1000/Little-ISMS-Helper/commit/a77e56581d2ab282c8a213dc404ef4bbebf92100))
* **quick-fix:** vanilla-JS progress page appends ?failed_job_id to back-link ([#733](https://github.com/moag1000/Little-ISMS-Helper/issues/733)) ([174f5d6](https://github.com/moag1000/Little-ISMS-Helper/commit/174f5d6a860f2c033143bbe3968fe0faad44b203))
* **report+asset:** widget 500s + default asset sub-types per tenant ([#705](https://github.com/moag1000/Little-ISMS-Helper/issues/705)) ([3228410](https://github.com/moag1000/Little-ISMS-Helper/commit/3228410948b4d93a732bca7308c6ac787308d114))
* **reports:** correct stale entity field names in PDF report templates ([#774](https://github.com/moag1000/Little-ISMS-Helper/issues/774)) ([62167a6](https://github.com/moag1000/Little-ISMS-Helper/commit/62167a60b335d4d341366580e4a5e76b358d9aeb))
* **risk:** drop blanket NotNull on riskOwner — either-or with riskOwnerPerson (K-05) ([#589](https://github.com/moag1000/Little-ISMS-Helper/issues/589)) ([8b88ef4](https://github.com/moag1000/Little-ISMS-Helper/commit/8b88ef452a7c2cf8d07bcc09207a702c4db5bbf0))
* **routes:** 2 dead path() refs in production templates ([#710](https://github.com/moag1000/Little-ISMS-Helper/issues/710)) ([c7d6bb3](https://github.com/moag1000/Little-ISMS-Helper/commit/c7d6bb314d47c1dda2f98c7e821716c740c11805))
* **routes:** repair 8 dead route references found by repo-wide sweep ([6e23cca](https://github.com/moag1000/Little-ISMS-Helper/commit/6e23cca02de683c2f68aaa2278529dd70e047107))
* **routing:** explicit methods + drop trailing-slash (50+ routes) ([#516](https://github.com/moag1000/Little-ISMS-Helper/issues/516)) ([d25eed5](https://github.com/moag1000/Little-ISMS-Helper/commit/d25eed5b3b71d8625a8236a48a79f5eb4b521d31))
* **search:** render all 30 result categories (was: 5 hardcoded) ([f57e37f](https://github.com/moag1000/Little-ISMS-Helper/commit/f57e37f9e34ac4e7bb45b70fda7ed50dc8515248))
* **search:** replace invalid Aurora icon classes (32 in nav-map, 9 in JS) ([9d79d3e](https://github.com/moag1000/Little-ISMS-Helper/commit/9d79d3e8fe8706a155b5f12181ae5f815894b7ab))
* **search:** stronger ISMS icon semantics for control items ([30f37e4](https://github.com/moag1000/Little-ISMS-Helper/commit/30f37e49b04fdf4261eab7d0795fcd2ea5ef045f))
* **security:** audit pre-cert critical+high — 1C/5H/3M ([#529](https://github.com/moag1000/Little-ISMS-Helper/issues/529)) ([fbb4320](https://github.com/moag1000/Little-ISMS-Helper/commit/fbb43200acdd9cd95e76b34142aa1bf5c33f2c8c))
* **security:** CSRF + role-gating on POST forms ([403ef2c](https://github.com/moag1000/Little-ISMS-Helper/commit/403ef2cb7ef73157d2a6233daee5a82050fc1a95))
* **security:** CSRF protection for all bulk-action endpoints (audit C-1) ([#546](https://github.com/moag1000/Little-ISMS-Helper/issues/546)) ([26e946d](https://github.com/moag1000/Little-ISMS-Helper/commit/26e946d63ae7c6d77e0a58c217c583f625f5125f))
* **security:** M-4 module-gating Show-Template audit ([#523](https://github.com/moag1000/Little-ISMS-Helper/issues/523)) ([01897f8](https://github.com/moag1000/Little-ISMS-Helper/commit/01897f819914f883ff1febc4fcff69d2d1d26328))
* **security:** re-auth via entry_point — actually intercept RememberMe redirects ([#577](https://github.com/moag1000/Little-ISMS-Helper/issues/577)) ([635a627](https://github.com/moag1000/Little-ISMS-Helper/commit/635a627cbb572771c583c849aa120cbbf8a77e36))
* **security:** restore isActingAs() method body in TenantScopedAdminVoter ([4364511](https://github.com/moag1000/Little-ISMS-Helper/commit/4364511ebb3998493704cd37b2cbdf3f80dd789f))
* **security:** RiskAcceptanceVoter honors role_hierarchy ([#582](https://github.com/moag1000/Little-ISMS-Helper/issues/582) follow-up) ([#613](https://github.com/moag1000/Little-ISMS-Helper/issues/613)) ([2581c73](https://github.com/moag1000/Little-ISMS-Helper/commit/2581c731f6d791744b086c68de5931d88f62716c))
* **smoke:** controls/pdf 500 + L1 download-catch + sub-resource fail detection ([#688](https://github.com/moag1000/Little-ISMS-Helper/issues/688)) ([3ba3f36](https://github.com/moag1000/Little-ISMS-Helper/commit/3ba3f361497f6a96e76d220a314a4c5368ad5490))
* **soa:** index hang + extreme memory (root-cause + fix) ([#566](https://github.com/moag1000/Little-ISMS-Helper/issues/566)) ([1e732dd](https://github.com/moag1000/Little-ISMS-Helper/commit/1e732dd0465c23fb2310259a8d304cd93b19c50a))
* **soa:** sticky-header — migrate table to fa-table Aurora component ([#576](https://github.com/moag1000/Little-ISMS-Helper/issues/576)) ([a84d332](https://github.com/moag1000/Little-ISMS-Helper/commit/a84d3321b9a3a8cc279b054ca9981585bfdb4e98))
* **stimulus:** restore 4 false-positive deleted controllers from [#567](https://github.com/moag1000/Little-ISMS-Helper/issues/567) ([#572](https://github.com/moag1000/Little-ISMS-Helper/issues/572)) ([6baca9f](https://github.com/moag1000/Little-ISMS-Helper/commit/6baca9f6105ae6b48952dac64de9f6ad4694ebfa))
* **stimulus:** surface 4xx/5xx errors via toast (9 fetch-sites) ([#568](https://github.com/moag1000/Little-ISMS-Helper/issues/568)) ([10d119e](https://github.com/moag1000/Little-ISMS-Helper/commit/10d119e5279ad827dfb6513393374d15b1d362a3))
* **templates:** correct stale entity-getter references in email + PDF templates ([#778](https://github.com/moag1000/Little-ISMS-Helper/issues/778)) ([dc4e019](https://github.com/moag1000/Little-ISMS-Helper/commit/dc4e019b3892fc27f9a7c0d8f2e717ca2d9e539d))
* **tests:** AuditProgram 33/33 — worktree bootstrap, module-gate, YAML errors ([0a490a5](https://github.com/moag1000/Little-ISMS-Helper/commit/0a490a5e01aa2c95ab0adc5ba800e7693afa8903))
* **tests:** case-correct fixture path 'tests/fixtures' → 'tests/Fixtures' ([ce06b20](https://github.com/moag1000/Little-ISMS-Helper/commit/ce06b203dea714ad68e02ea0103c4d6c1f057be9))
* **tests:** satisfy setup_complete.lock gate in ComplianceWizardController tests ([#552](https://github.com/moag1000/Little-ISMS-Helper/issues/552)) ([d55989e](https://github.com/moag1000/Little-ISMS-Helper/commit/d55989edcd4d11490897f35de35e8693ef6b0700))
* **test:** TenantScopedAdminVoter constructor now takes 4 args (RequestStack) ([a90d98b](https://github.com/moag1000/Little-ISMS-Helper/commit/a90d98b8439a9aa39688ca997f291f5190b915c3))
* **threat-intelligence:** rename references column (reserved MySQL keyword) ([#708](https://github.com/moag1000/Little-ISMS-Helper/issues/708)) ([96b4c11](https://github.com/moag1000/Little-ISMS-Helper/commit/96b4c1137be590649ed807ed8120a99c1c63cc4c))
* **tisax+test:** fa-table headers as objects + sla-route test alignment ([25e9428](https://github.com/moag1000/Little-ISMS-Helper/commit/25e94286987d77a73b464a7e7f85aff1f0c16163))
* **tisax:** add 'Bitte wählen' placeholder + skip empty saves in Reifegrad-select ([#744](https://github.com/moag1000/Little-ISMS-Helper/issues/744)) ([dd57970](https://github.com/moag1000/Little-ISMS-Helper/commit/dd57970100836705de0284d7089fea0387204c9a))
* **tisax:** add nav entry + fix 65 missing translation keys ([2b22935](https://github.com/moag1000/Little-ISMS-Helper/commit/2b22935de0e5cfc8d81c669933b815fa2b9862c2))
* **tisax:** align extraction script + YAML to real ENX ISA 6 workbook (80 controls) ([f8d0600](https://github.com/moag1000/Little-ISMS-Helper/commit/f8d060077aca0da30d41a989cb848ea94657d4c4))
* **tisax:** correct PP tier to Reifegrad 0-5 — drop binary model remnants ([97c1b9c](https://github.com/moag1000/Little-ISMS-Helper/commit/97c1b9ceb7979f532294ee33073ab7a0a87044d0))
* **tisax:** correct VDA-ISA 6.x tier mapping, add CSRF to wizard steps, add security tests ([a6db238](https://github.com/moag1000/Little-ISMS-Helper/commit/a6db2385696d6f2b479bbab5b03577bd966e5834))
* **tisax:** correct VDA-ISA 6.x tier mapping, add CSRF to wizard steps, add security tests ([422d35b](https://github.com/moag1000/Little-ISMS-Helper/commit/422d35b3673c6e856a74986bc9b1000a8d4fe45d))
* **tisax:** correct VDA-ISA DP tier to tristate compliance (NA/OK/Nicht OK) ([da31e1a](https://github.com/moag1000/Little-ISMS-Helper/commit/da31e1aeadb3d325e6409d9eaf086f66c6557d85))
* **tisax:** critical security + important review followups ([a0be557](https://github.com/moag1000/Little-ISMS-Helper/commit/a0be5570040efa4353e6ba1ac08c8e71bffdd7e9))
* **tisax:** E2E wizard test, mapper unit tests, GDPR IP-retention purge command ([75d2fcf](https://github.com/moag1000/Little-ISMS-Helper/commit/75d2fcff1f30e779e593a128cf80b8c04cf6b512))
* **tisax:** inject CsrfTokenManagerInterface (replace nonexistent generateCsrfToken) ([b019ea6](https://github.com/moag1000/Little-ISMS-Helper/commit/b019ea6c2463dc170e427e9526bc63385cac6a63))
* **tisax:** inject CsrfTokenManagerInterface (replace nonexistent generateCsrfToken) ([21d07ce](https://github.com/moag1000/Little-ISMS-Helper/commit/21d07ce459a225c3d8615b98ae0d66fb13ad8e3e))
* **tisax:** inject CsrfTokenManagerInterface (replace nonexistent generateCsrfToken) ([451adb8](https://github.com/moag1000/Little-ISMS-Helper/commit/451adb85dcff368db98725681203e8ce26b54681))
* **tisax:** make VDA-ISA parser handle real ENX ISA 6 workbooks ([7927ad2](https://github.com/moag1000/Little-ISMS-Helper/commit/7927ad295209f3ae97dca8b732b311a449c817c0))
* **tisax:** make VDA-ISA parser handle real ENX ISA 6 workbooks ([300151e](https://github.com/moag1000/Little-ISMS-Helper/commit/300151e521a2d1cc4d60df71d50a324c9b8dc21a))
* **tisax:** promote TTL to container parameter app.tisax.license_ttl_hours ([15840d5](https://github.com/moag1000/Little-ISMS-Helper/commit/15840d55747d8c9b57546ec110f599a825f2d6e5))
* **tisax:** strip ENX-licensed content from VDA-ISA skeleton fixture (OQ-1) ([463e42d](https://github.com/moag1000/Little-ISMS-Helper/commit/463e42dcc7a75ba6c7a0ebe696bdf03af3c29fac))
* **tisax:** strip ENX-licensed content from VDA-ISA skeleton fixture (OQ-1) ([43ccd5e](https://github.com/moag1000/Little-ISMS-Helper/commit/43ccd5eba4abbbaa5b8b6fbfab65fc0f4d3b9b14))
* **tisax:** strip ENX-licensed content from VDA-ISA skeleton fixture (OQ-1) ([3b2c7a5](https://github.com/moag1000/Little-ISMS-Helper/commit/3b2c7a5b2e7ae4d543f83c4043481565e8d150bb))
* **tisax:** use valid Aurora icon names in commit diff/mismatch UI ([72ceba5](https://github.com/moag1000/Little-ISMS-Helper/commit/72ceba516d5913e6bf59ea1ea119388b43a7ce2e))
* **tour:** auto-scroll target into view before popover positioning ([#737](https://github.com/moag1000/Little-ISMS-Helper/issues/737)) ([93d0084](https://github.com/moag1000/Little-ISMS-Helper/commit/93d00847f5f285a0eab60ea79340730dccc684e5))
* **translations+security:** add 14 missing DE+EN keys + VDA-ISA DDE sanitization ([ab67435](https://github.com/moag1000/Little-ISMS-Helper/commit/ab674350f29358d067996173c95ced5f39295185))
* **turbo:** HTTP 422 on form-validation error — Admin controllers batch 1 (14 files) ([3174446](https://github.com/moag1000/Little-ISMS-Helper/commit/31744467e26dc36516a9c03b8ecf8453dea6dd5b))
* **turbo:** HTTP 422 on form-validation error — Admin controllers batch 1 (14 files) ([924846b](https://github.com/moag1000/Little-ISMS-Helper/commit/924846bc2ed9b8acc9bf2eb56f551aa74eae56e1))
* **turbo:** HTTP 422 on form-validation error — Admin controllers batch 1 (14 files) ([d2c608c](https://github.com/moag1000/Little-ISMS-Helper/commit/d2c608cc430c523a47aaca55ce7970a85c21570b))
* **turbo:** HTTP 422 on form-validation error — Admin+Core controllers batch 2 (14 files) ([1f2ae1b](https://github.com/moag1000/Little-ISMS-Helper/commit/1f2ae1bce7747a8256543c07588ae94abb06d29b))
* **turbo:** HTTP 422 on form-validation error — Admin+Core controllers batch 2 (14 files) ([c1a80da](https://github.com/moag1000/Little-ISMS-Helper/commit/c1a80da6535684892f9f8e1df59697b50dc38792))
* **turbo:** HTTP 422 on form-validation error — Admin+Core controllers batch 2 (14 files) ([efce4ee](https://github.com/moag1000/Little-ISMS-Helper/commit/efce4ee588cfa5a6aa77d98be011ff941414d7c2))
* **turbo:** HTTP 422 on form-validation error — AuditProgramController (new + edit) ([9231b47](https://github.com/moag1000/Little-ISMS-Helper/commit/9231b47e2ad1841e481d2f5e90e74b6cbeab3501))
* **turbo:** HTTP 422 on form-validation error — Compliance+Core controllers batch 3 (14 files) ([64b010c](https://github.com/moag1000/Little-ISMS-Helper/commit/64b010c80fd007428a885a2bbd246a08ef8000e4))
* **turbo:** HTTP 422 on form-validation error — Compliance+Core controllers batch 3 (14 files) ([a6922f9](https://github.com/moag1000/Little-ISMS-Helper/commit/a6922f9f13b0fddcce8ba20118457712b11931d2))
* **turbo:** HTTP 422 on form-validation error — Core+Import controllers batch 4 (14 files) ([6c43fbb](https://github.com/moag1000/Little-ISMS-Helper/commit/6c43fbbee9fb81ed2f18b152e090ab54d353d06f))
* **turbo:** HTTP 422 on form-validation error — Core+Import controllers batch 4 (14 files) ([987fdf6](https://github.com/moag1000/Little-ISMS-Helper/commit/987fdf6bcd02bd1f847a93087194e35e61054cbd))
* **turbo:** HTTP 422 on form-validation error — Risk/Settings/Misc controllers batch 5 (13 files) ([15105cd](https://github.com/moag1000/Little-ISMS-Helper/commit/15105cdcaa4fd2344670b57461106fffabe699db))
* **turbo:** HTTP 422 on form-validation error — Risk/Settings/Misc controllers batch 5 (13 files) ([53e3954](https://github.com/moag1000/Little-ISMS-Helper/commit/53e3954a29c5c456acc7afeb103185a050b5dd09))
* **turbo:** HTTP 422 on form-validation error — SsoProviderController private handleForm + JSON-error path ([6b507b7](https://github.com/moag1000/Little-ISMS-Helper/commit/6b507b73a528a4afba3a96184f0ee4d9cda13080))
* **turbo:** HTTP 422 on form-validation error — SsoProviderController private handleForm + JSON-error path ([11eb7e7](https://github.com/moag1000/Little-ISMS-Helper/commit/11eb7e7ea4d5608b35738a5a1730026c35210ec1))
* **turbo:** HTTP 422 on form-validation error — UserManagementController (new + edit) ([2cc378c](https://github.com/moag1000/Little-ISMS-Helper/commit/2cc378c0e7f84d3708efc3e95d037c5038ce1fa3))
* **twig:** correct 'not starts with' syntax in mega_menu_panel_only ([6995ce3](https://github.com/moag1000/Little-ISMS-Helper/commit/6995ce352df250762aac13a62dc54318237e4507))
* **twig:** escape nested {# #} in _auto_form docstring (leaked to UI) ([#560](https://github.com/moag1000/Little-ISMS-Helper/issues/560)) ([561c11f](https://github.com/moag1000/Little-ISMS-Helper/commit/561c11f5204a6958c5a6560ce4864598abac2b68))
* **twig:** render mfa_required_roles as joined string, not array ([5a4b58b](https://github.com/moag1000/Little-ISMS-Helper/commit/5a4b58bcfa08368b45c286c675ef77117d1f0c02))
* **ui+tisax:** flash trans, preview rows, second-import reset, register dropdown/preferences ([3e8f903](https://github.com/moag1000/Little-ISMS-Helper/commit/3e8f903a5e250c05fe727ef33e689800a9cd5e8b))
* **ui+upload:** register MegaMenuController + accept zip-MIME for xlsx ([c0e99b8](https://github.com/moag1000/Little-ISMS-Helper/commit/c0e99b88c7cdb05f30230c3a5f374fae7be7b903))
* **ui:** browser-console error cleanup (3 errors) ([#687](https://github.com/moag1000/Little-ISMS-Helper/issues/687)) ([6d9896f](https://github.com/moag1000/Little-ISMS-Helper/commit/6d9896f5fe7efa99eb1101536668db12de96c4ac))
* **ui:** eliminate FOUC by loading Aurora CSS as parallel links ([#795](https://github.com/moag1000/Little-ISMS-Helper/issues/795)) ([61afa94](https://github.com/moag1000/Little-ISMS-Helper/commit/61afa947ff572445714ac6dc67e487ed998fb77c))
* **ui:** IIFE-harden 13 in-body inline scripts against Turbo re-declare crash ([#791](https://github.com/moag1000/Little-ISMS-Helper/issues/791)) ([3f5dd5c](https://github.com/moag1000/Little-ISMS-Helper/commit/3f5dd5ca7f27790716ae589e75c24689a310f369))
* **ui:** M-6 training/show Aurora conformance (8 .card-header utility mixes) ([#519](https://github.com/moag1000/Little-ISMS-Helper/issues/519)) ([1618ecf](https://github.com/moag1000/Little-ISMS-Helper/commit/1618ecf981672c6530e28a2baa6d42d8affd1421))
* **ui:** persona quick-switcher buttons did nothing (missing controller) ([#784](https://github.com/moag1000/Little-ISMS-Helper/issues/784)) ([b23a5da](https://github.com/moag1000/Little-ISMS-Helper/commit/b23a5dad1cd48dbaba3fe22d1095461efa67a63f))
* **ui:** preferences modal opened invisibly (legacy modal-manager conflict) ([#782](https://github.com/moag1000/Little-ISMS-Helper/issues/782)) ([106afe9](https://github.com/moag1000/Little-ISMS-Helper/commit/106afe983090edda86dd99eb6aed1b3058545dfb))
* **ui:** Turbo redeclare crash + Chart.js tick explosion on mapping-quality hub ([#787](https://github.com/moag1000/Little-ISMS-Helper/issues/787)) ([4ee7a4a](https://github.com/moag1000/Little-ISMS-Helper/commit/4ee7a4adc43d3a1e76a461da61288e140fbfe8a3))
* use for-loop accumulator with object-merge to build a single ([58df506](https://github.com/moag1000/Little-ISMS-Helper/commit/58df506a524d7b2edcbc94f0354f3c4e93787f14))
* **ux-p0:** BIA-draft survival + i18n locale-mix cleanup ([#581](https://github.com/moag1000/Little-ISMS-Helper/issues/581)) ([ea7cbaf](https://github.com/moag1000/Little-ISMS-Helper/commit/ea7cbaf1dadcfe54ab7f8a7082e0ca1a1c51bd01))
* **ux-p0:** MTPD&gt;RTO validator, Risk-Decision audit-fields, score-band vocab align ([#579](https://github.com/moag1000/Little-ISMS-Helper/issues/579)) ([ed6c16e](https://github.com/moag1000/Little-ISMS-Helper/commit/ed6c16e65293700427fcb6be62d9c5f54a039545))
* **ux:** T5.2 risk-category tooltip alignment + T13.3 audit clause i18n ([#616](https://github.com/moag1000/Little-ISMS-Helper/issues/616)) ([492e088](https://github.com/moag1000/Little-ISMS-Helper/commit/492e088122b4f1be8f516c7f7d6af18338354660))
* **workflow:** remove broken create-definition CTA (route + policy violation) ([#711](https://github.com/moag1000/Little-ISMS-Helper/issues/711)) ([4727b75](https://github.com/moag1000/Little-ISMS-Helper/commit/4727b756ef5538e6accf6c5b31dc3d0f46a6a87f))


### Performance

* **base:** preload Aurora bundle to eliminate FOUC ([788ce6b](https://github.com/moag1000/Little-ISMS-Helper/commit/788ce6ba980050e5049a175e92d738c652af1c2f))
* **data-integrity:** cache findAll() in ReferenceIntegrityChecker ([b68cd74](https://github.com/moag1000/Little-ISMS-Helper/commit/b68cd74d120aecd65f3397a3a2733f0e37430466))


### Changed

* **arch:** extract SetupBaselineApplier + EntityCountAggregator, cache ReferenceIntegrityChecker findAll() ([1254813](https://github.com/moag1000/Little-ISMS-Helper/commit/12548138ea82d8ca5f43b6624607a51c9ea89dcf))
* **arch:** extract SetupBaselineApplier + EntityCountAggregator, cache ReferenceIntegrityChecker findAll() ([fb1ee11](https://github.com/moag1000/Little-ISMS-Helper/commit/fb1ee115a090e69150cd07cb834a22c106adf9f4))
* **compliance-wizard:** decompose god-class (6046 → 489 LOC, extract 5 sub-services) ([#556](https://github.com/moag1000/Little-ISMS-Helper/issues/556)) ([09bb643](https://github.com/moag1000/Little-ISMS-Helper/commit/09bb6435d631b0eee39edd9b7f4dd85c9b7c0d2a))
* **compliance:** address review followups from ComplianceController split ([023e300](https://github.com/moag1000/Little-ISMS-Helper/commit/023e3009d86e458d1cd57e091705eb46e00fc1a3))
* **compliance:** split ComplianceController god-class (2629 LOC → 3 controllers) ([c0f3668](https://github.com/moag1000/Little-ISMS-Helper/commit/c0f36682810131f925b8ee796e8320419d1ad4ec))
* **data-integrity:** address review followups ([a8adbee](https://github.com/moag1000/Little-ISMS-Helper/commit/a8adbee682dcc2525f6218462a4a7042f7b51be5))
* **data-integrity:** address review followups ([95fa65d](https://github.com/moag1000/Little-ISMS-Helper/commit/95fa65dabd1ba2184ca3f4b80d36dce5a9679f94))
* **data-integrity:** extract DuplicateFinder collaborator from DataIntegrityService ([d6866b4](https://github.com/moag1000/Little-ISMS-Helper/commit/d6866b4140e2b6c1b9b859f35d20860644dc3997))
* **data-integrity:** extract DuplicateFinder collaborator from DataIntegrityService ([3634a6e](https://github.com/moag1000/Little-ISMS-Helper/commit/3634a6e4838e3ca546244add66870e1248a0067b))
* **data-integrity:** extract EntityCountAggregator from facade ([d2fae0a](https://github.com/moag1000/Little-ISMS-Helper/commit/d2fae0a12b81f82b30a4fd19dc8c773992b51da2))
* **data-integrity:** extract HealthIssueAggregator collaborator ([b718ae3](https://github.com/moag1000/Little-ISMS-Helper/commit/b718ae330f944a1024211eb755e8ed7358e38127))
* **data-integrity:** extract OrphanFinder collaborator from DataIntegrityService ([38d7554](https://github.com/moag1000/Little-ISMS-Helper/commit/38d755425df8c8995a761e24f9ed53fdc5c80201))
* **data-integrity:** extract ReferenceIntegrityChecker collaborator ([b3af268](https://github.com/moag1000/Little-ISMS-Helper/commit/b3af26842a0e26a46b84bb86daf87aee1ec0b25b))
* **data-integrity:** extract SchemaDriftChecker collaborator ([949fd38](https://github.com/moag1000/Little-ISMS-Helper/commit/949fd38a09adc656dab674fc27b6bca734df1fb5))
* **data-integrity:** facade cleanup — replace inline fallbacks with pure delegates ([f155312](https://github.com/moag1000/Little-ISMS-Helper/commit/f155312cab3711acd5684de945ae6f01ecf6f5e8))
* **data-integrity:** split DataIntegrityService god-class into 5 collaborators ([90f8f49](https://github.com/moag1000/Little-ISMS-Helper/commit/90f8f4925c8ec3f5cfe724031dd39d228c1de6be))
* **data-integrity:** wire OrphanFinder + DuplicateFinder into DataIntegrityService facade ([f6094ca](https://github.com/moag1000/Little-ISMS-Helper/commit/f6094ca6556e1acced313c1e1522f93277980954))
* **entity:** drop 3 deprecated-since-v4 columns ([#696](https://github.com/moag1000/Little-ISMS-Helper/issues/696)) ([17be1f0](https://github.com/moag1000/Little-ISMS-Helper/commit/17be1f0c48b80fefb95d9f88c69d8748afdea4f8))
* **forms:** comprehensive sweep — sections-map parity + console-error cleanup ([#686](https://github.com/moag1000/Little-ISMS-Helper/issues/686)) ([f7c3053](https://github.com/moag1000/Little-ISMS-Helper/commit/f7c3053ca8212f5e09e592bc618ac7b07b8d74a3))
* **m-07:** god-class small-decomposition + baseline reduce (1 file) ([#527](https://github.com/moag1000/Little-ISMS-Helper/issues/527)) ([9b5ea50](https://github.com/moag1000/Little-ISMS-Helper/commit/9b5ea504882ad08aad5ed28f8a10edeae6ed06a0))
* **restore:** address review followups ([0b2a107](https://github.com/moag1000/Little-ISMS-Helper/commit/0b2a1078c8c2caaeb810b58c7a21a3fd51c8b26f))
* **restore:** address review followups ([9ab929d](https://github.com/moag1000/Little-ISMS-Helper/commit/9ab929db45c59e334f432993a1ec21260a518c98))
* **restore:** extract EntityWriter + SecretsHandler; slim facade to ~649 LOC ([e2bf6bb](https://github.com/moag1000/Little-ISMS-Helper/commit/e2bf6bbf09274cea5c4c0a1ade580572e6bcf02e))
* **restore:** extract EntityWriter + SecretsHandler; slim facade to ~649 LOC ([fcab7f0](https://github.com/moag1000/Little-ISMS-Helper/commit/fcab7f0811f1d6290681165d31818c467ff31ad7))
* **restore:** extract RestoreDataPurger + wire both collaborators (~390 LOC) ([47c2c92](https://github.com/moag1000/Little-ISMS-Helper/commit/47c2c9214fc212e8c185d5c065a2928b3afa59f5))
* **restore:** extract RestoreDataPurger + wire both collaborators (~390 LOC) ([0175a1b](https://github.com/moag1000/Little-ISMS-Helper/commit/0175a1b3a80fbbdaab4205947f6ccc28ea57b338))
* **restore:** extract RestoreValidator collaborator (~250 LOC) ([947787c](https://github.com/moag1000/Little-ISMS-Helper/commit/947787cfb07dfa870e43bbca3581cc8370cf9a25))
* **restore:** extract RestoreValidator collaborator (~250 LOC) ([52f0ee1](https://github.com/moag1000/Little-ISMS-Helper/commit/52f0ee10350f96162fda98b75933c7649f01e181))
* **restore:** split RestoreEntityWriter + dedup resetManager + export test coverage ([b98517d](https://github.com/moag1000/Little-ISMS-Helper/commit/b98517dbef30ed346228c41f56e7a97881f18125))
* **restore:** split RestoreService god-class into 5 collaborators ([abe9aee](https://github.com/moag1000/Little-ISMS-Helper/commit/abe9aee3dc87d0fa86af0045f81fc0c300538acb))
* **setup:** address DeploymentWizard split review followups ([3eb76cd](https://github.com/moag1000/Little-ISMS-Helper/commit/3eb76cd23a04db01d3f8f9d9978329e22ba35a73))
* **setup:** address DeploymentWizard split review followups ([edfb693](https://github.com/moag1000/Little-ISMS-Helper/commit/edfb6936714723dcb563edc78f7e2e9d2705ee38))
* **setup:** extract SetupBaselineApplier from SetupRecommendationEngine ([5839ad1](https://github.com/moag1000/Little-ISMS-Helper/commit/5839ad12e57603e8d963cba77ba2027105e070c7))
* **setup:** make single-industry helper methods private in SetupRecommendationEngine ([e432b34](https://github.com/moag1000/Little-ISMS-Helper/commit/e432b3439631ef363f8501d1a09c58dacb764e82))
* **setup:** split DeploymentWizardController god-class (2694→1745 LOC) ([ea07922](https://github.com/moag1000/Little-ISMS-Helper/commit/ea07922fccdffcb492adeaa2db61ab569777ba09))
* **tisax:** extract import-wizard support logic into services (god-class gate) ([c8ef39c](https://github.com/moag1000/Little-ISMS-Helper/commit/c8ef39c3b8aa492a0009fa1d8c177806842e20a0))
* **tisax:** extract TisaxConfirmationService — move em-writes out of controller ([133feeb](https://github.com/moag1000/Little-ISMS-Helper/commit/133feebd5cfb6103176382b6cc2c4f5fb309b468))


### Documentation

* add 12 ADRs, first-PR walkthrough, maintainer-handoff, and Day-1/7/30 progression guide ([851c5d1](https://github.com/moag1000/Little-ISMS-Helper/commit/851c5d16cd2950b2bafa11ff99db765513ea54c9))
* add ONBOARDING.md pointers to README and CONTRIBUTING ([9468164](https://github.com/moag1000/Little-ISMS-Helper/commit/9468164653ca73355c845239a4f90a151a435ecc))
* **adr:** accept hybrid Nonconformity-modeling — no separate entity needed ([1eb68fb](https://github.com/moag1000/Little-ISMS-Helper/commit/1eb68fbbd75baa7abbc7ab6b5897bfa82b1d91d6))
* **adr:** CAPA-Canonical-Process — consolidation plan (M-07) ([#591](https://github.com/moag1000/Little-ISMS-Helper/issues/591)) ([97918d2](https://github.com/moag1000/Little-ISMS-Helper/commit/97918d22e53a4be4591a6c7ad85973244db8af1c))
* design — policy parameter catalog + industry baselines ([#755](https://github.com/moag1000/Little-ISMS-Helper/issues/755)) ([19f88e5](https://github.com/moag1000/Little-ISMS-Helper/commit/19f88e5d8524b725017e65216d4fd3f633f59c20))
* **forms:** close H-03 — section-policy dev-warning is a standing aid, not a todo ([#804](https://github.com/moag1000/Little-ISMS-Helper/issues/804)) ([b9f3b4e](https://github.com/moag1000/Little-ISMS-Helper/commit/b9f3b4e931e678d03e169593701fe4ddc05849fe))
* multi-tenant user assignment spec (sister-org CISO case) ([#720](https://github.com/moag1000/Little-ISMS-Helper/issues/720)) ([6443f07](https://github.com/moag1000/Little-ISMS-Helper/commit/6443f079b9ea3cb4d18c8c06641e16c0a98373d1))
* **onboarding:** add co-maintainer onboarding guide set (11 files) ([a08e992](https://github.com/moag1000/Little-ISMS-Helper/commit/a08e9927d8febb3b66611c83ed27f297c8fcecc2))
* **plans:** purge completed plans, strip person-rollout + feature-roadmap ([#672](https://github.com/moag1000/Little-ISMS-Helper/issues/672)) ([d31991d](https://github.com/moag1000/Little-ISMS-Helper/commit/d31991d7db577531191b2493ed185a1c3fc37a7a))
* **release:** prep 3.9.0 — README Docker pull + version badges + 3.8 withdrawn note ([#807](https://github.com/moag1000/Little-ISMS-Helper/issues/807)) ([c34e210](https://github.com/moag1000/Little-ISMS-Helper/commit/c34e2109665312a8fba6cd06e8d635776f31c388))
* **tisax:** add ENX VDA-ISA licensing analysis + onboarding pointers ([5ae693f](https://github.com/moag1000/Little-ISMS-Helper/commit/5ae693fc489c62648d3c7b5d9e245ef9becd44d2))
* TODO-remediation-plan (42 TODOs in 7 buckets / 3 sprints) ([#694](https://github.com/moag1000/Little-ISMS-Helper/issues/694)) ([c805ecd](https://github.com/moag1000/Little-ISMS-Helper/commit/c805ecdf48dc92baed4cbdaf43caf870385c217a))

## [3.8.0](https://github.com/moag1000/Little-ISMS-Helper/compare/v3.7.0...v3.8.0) (2026-05-25)

> **Zurueckgezogen.** 3.8.0 wurde als defekt zurueckgezogen (kein `v3.8.0`-Tag).
> Die hier gelisteten Aenderungen sind kumulativ in 3.9.0 enthalten -- bitte
> 3.9.0 oder neuer verwenden.


### Added

* **admin-hub:** Role-Scope Phase 2 — requiredAttribute filter + module annotations ([#462](https://github.com/moag1000/Little-ISMS-Helper/issues/462)) ([371f3da](https://github.com/moag1000/Little-ISMS-Helper/commit/371f3dab72da53eb3f7bb377f69abd03ad49db12))
* **admin:** async admin jobs via Symfony Messenger (pilot: fix-all-orphans) ([#499](https://github.com/moag1000/Little-ISMS-Helper/issues/499)) ([92ed47a](https://github.com/moag1000/Little-ISMS-Helper/commit/92ed47a156aa57bca98f4e40bf9337e90c4052f9))
* **admin:** data-repair async index + repair-POSTs + PRG-fix consolidated ([#512](https://github.com/moag1000/Little-ISMS-Helper/issues/512)) ([7f5b478](https://github.com/moag1000/Little-ISMS-Helper/commit/7f5b47825863db6e9b229dd216f6b935fd7766d3))
* **admin:** Phase 2 async admin jobs rollout ([#501](https://github.com/moag1000/Little-ISMS-Helper/issues/501)) ([0b88fad](https://github.com/moag1000/Little-ISMS-Helper/commit/0b88fad5948ca6f0bfd882ae2aedddf27b1e0997))
* **admin:** Phase 2.5 async-rollout for integrity-check + CSV exports ([#503](https://github.com/moag1000/Little-ISMS-Helper/issues/503)) ([9528f5c](https://github.com/moag1000/Little-ISMS-Helper/commit/9528f5c9678efc171fb5dfbcb619736ebb639e8e))
* **admin:** Phase 3 async-rollout for remaining export operations ([#504](https://github.com/moag1000/Little-ISMS-Helper/issues/504)) ([6be48e7](https://github.com/moag1000/Little-ISMS-Helper/commit/6be48e7415157de80e9f48744da14c19daa9f49b))
* **admin:** Symfony-native worker for shared hosting (cron + manual + health-UI) ([#505](https://github.com/moag1000/Little-ISMS-Helper/issues/505)) ([ed974a5](https://github.com/moag1000/Little-ISMS-Helper/commit/ed974a5d9705db2fddf0469593501809eae91ccf))
* **alva-hint:** coverage expansion to 30 rules ([#521](https://github.com/moag1000/Little-ISMS-Helper/issues/521)) ([b04d7be](https://github.com/moag1000/Little-ISMS-Helper/commit/b04d7be9c792a26c896e1d4edea0502848b11d8a))
* **alva:** bc_exercise.target_missed AlvaHint rule (Closes K-07) ([#583](https://github.com/moag1000/Little-ISMS-Helper/issues/583)) ([84aa793](https://github.com/moag1000/Little-ISMS-Helper/commit/84aa793958c2c01e8c7c004f46acb9c1d201ea67))
* **alva:** expand Form-Step-Inline-Hint coverage to 15+ rules across 9 FormTypes ([#644](https://github.com/moag1000/Little-ISMS-Helper/issues/644)) ([57eff17](https://github.com/moag1000/Little-ISMS-Helper/commit/57eff172ce05a0dba4b89a64bc32802282d1a90f))
* **alva:** Form-Step-Inline-Hint Pattern (P-19 expansion) ([#620](https://github.com/moag1000/Little-ISMS-Helper/issues/620)) ([34d1194](https://github.com/moag1000/Little-ISMS-Helper/commit/34d1194d1acf556490f0a17707dece1a29f1864a))
* **asset:** remove dead monetaryValue field (S14+ §15) ([#656](https://github.com/moag1000/Little-ISMS-Helper/issues/656)) ([b9d58a3](https://github.com/moag1000/Little-ISMS-Helper/commit/b9d58a37d6d6245b023f60e609830c6e0ab8b1c5))
* **audit:** C5 cluster — correlation-help + scoped-asset toggle + audit templates (S14) ([#606](https://github.com/moag1000/Little-ISMS-Helper/issues/606)) ([9a1a950](https://github.com/moag1000/Little-ISMS-Helper/commit/9a1a950a7c3402bf02bd5739787fac97aee512cf))
* **audit:** persona-driven browser-console audit (Stufe 1, capture-only) ([#570](https://github.com/moag1000/Little-ISMS-Helper/issues/570)) ([7563de6](https://github.com/moag1000/Little-ISMS-Helper/commit/7563de60a9cef08da8b906d21547a1d402b3315c))
* **aurora:** fa-modal foundation — unified modal library (confirm/settings/wizard) ([#547](https://github.com/moag1000/Little-ISMS-Helper/issues/547)) ([c9c36e8](https://github.com/moag1000/Little-ISMS-Helper/commit/c9c36e88a03372aca96a90851a8bb0e790d08de4))
* **aurora:** modal sweep wave 1 — shared partials + workflow macro (sweep 2.1 + 2.2) ([#551](https://github.com/moag1000/Little-ISMS-Helper/issues/551)) ([7a438fd](https://github.com/moag1000/Little-ISMS-Helper/commit/7a438fd8bc854c224403f4d2f6314f31eb6d002a))
* **aurora:** modal sweep wave 2 — regulatory + workflow + delete page modals (batch A/B/C) ([#555](https://github.com/moag1000/Little-ISMS-Helper/issues/555)) ([f175975](https://github.com/moag1000/Little-ISMS-Helper/commit/f175975c1311012ddb73f9ce2f1022bcba1047d9))
* **aurora:** modal sweep wave 3 — admin/export/info modals + Bootstrap.Modal() cleanup ([#558](https://github.com/moag1000/Little-ISMS-Helper/issues/558)) ([823551a](https://github.com/moag1000/Little-ISMS-Helper/commit/823551adde8284c1cf10a23e597d2a04d59a88bc))
* **aurora:** sweep wave 4 — migrate backup.html.twig custom shells to fa-modal ([#559](https://github.com/moag1000/Little-ISMS-Helper/issues/559)) ([ba0a3c1](https://github.com/moag1000/Little-ISMS-Helper/commit/ba0a3c1fc7a00af6fab1f2fc65459cae1ada8156))
* **awareness:** Cluster C — Training-Workflow + Document-Review (S14) ([#608](https://github.com/moag1000/Little-ISMS-Helper/issues/608)) ([f24566a](https://github.com/moag1000/Little-ISMS-Helper/commit/f24566aa90f4fe6160b6b567aa4edcef6cce1334))
* **bcm:** typed M2M for BusinessProcess dependencies (S14+ §17) ([#664](https://github.com/moag1000/Little-ISMS-Helper/issues/664)) ([3bb21f3](https://github.com/moag1000/Little-ISMS-Helper/commit/3bb21f3b8cc11514373773570124956e27ac2a78))
* **bcm:** validate RPO ≤ RTO ≤ MTPD recovery chain (S11 M-01) ([#658](https://github.com/moag1000/Little-ISMS-Helper/issues/658)) ([a0a3082](https://github.com/moag1000/Little-ISMS-Helper/commit/a0a3082cae387ae121931ed9891791322ef01cdb))
* **bcm:** wire regulatory + operational impact tooltips into BP help drawer (S14+ §17) ([#660](https://github.com/moag1000/Little-ISMS-Helper/issues/660)) ([0b00834](https://github.com/moag1000/Little-ISMS-Helper/commit/0b008349a2468f175b7723916769f879f67ebccd))
* **bp:** server-validate recovery chain RPO ≤ RTO ≤ MTPD ([#586](https://github.com/moag1000/Little-ISMS-Helper/issues/586)) ([4e1da05](https://github.com/moag1000/Little-ISMS-Helper/commit/4e1da05e3408928816ad31e18887e74d4b323a49))
* **bp:** tooltip drawer pattern (S14 [#17](https://github.com/moag1000/Little-ISMS-Helper/issues/17) - PR [#607](https://github.com/moag1000/Little-ISMS-Helper/issues/607) follow-up) ([#624](https://github.com/moag1000/Little-ISMS-Helper/issues/624)) ([3d04cde](https://github.com/moag1000/Little-ISMS-Helper/commit/3d04cdeb0d6308abc37e390d16a6d6e7e261eebd))
* **bulk-bar:** expand coverage to 12 additional entity lists ([#491](https://github.com/moag1000/Little-ISMS-Helper/issues/491)) ([c0a6584](https://github.com/moag1000/Little-ISMS-Helper/commit/c0a658450a836175c0589bd2779e40b4f9a9374d))
* **bulk:** bulk-delete-check for all 20 entities (real deps for top-5 + stubs for rest) ([#575](https://github.com/moag1000/Little-ISMS-Helper/issues/575)) ([433f54a](https://github.com/moag1000/Little-ISMS-Helper/commit/433f54aea7e837d3d20324c1a7f36f6ea07bcd56))
* **bulk:** full bulk-action endpoint rollout (audit C-2/C-3/CC-5) ([#534](https://github.com/moag1000/Little-ISMS-Helper/issues/534)) ([32577c5](https://github.com/moag1000/Little-ISMS-Helper/commit/32577c56f999fa99a91d7ed77761437bea722dd7))
* **capa:** auto-create CorrectiveAction from high-severity Incident with root-cause ([#597](https://github.com/moag1000/Little-ISMS-Helper/issues/597)) ([b0a0e05](https://github.com/moag1000/Little-ISMS-Helper/commit/b0a0e059fcc09ac58b856a5236aced358659e46d))
* **capa:** C4 cluster — re-loop, multi-control, lineage, due-hint (S13) ([#602](https://github.com/moag1000/Little-ISMS-Helper/issues/602)) ([0fbb8b6](https://github.com/moag1000/Little-ISMS-Helper/commit/0fbb8b6f2431560077844524050e83627bee1720))
* **capa:** M-07 Phase 1 — schema + backfill + UI filter ([#637](https://github.com/moag1000/Little-ISMS-Helper/issues/637)) ([f7ebb73](https://github.com/moag1000/Little-ISMS-Helper/commit/f7ebb73390166dafb8d35e7d930d4ef0fdf17007))
* **ci:** Gate 41 — admin controllers declare consistent role-scope guard ([#465](https://github.com/moag1000/Little-ISMS-Helper/issues/465)) ([0bd78b3](https://github.com/moag1000/Little-ISMS-Helper/commit/0bd78b3fab5be181fac6dce1be7e77f242ed81df))
* **databreach:** migrate to fa-modal--wizard per design-system spec ([#543](https://github.com/moag1000/Little-ISMS-Helper/issues/543)) ([9c64ab5](https://github.com/moag1000/Little-ISMS-Helper/commit/9c64ab506429e2bfa608a1e46269d45ed65e6190))
* **e2e:** Playwright test suite foundation (15 tests) ([#645](https://github.com/moag1000/Little-ISMS-Helper/issues/645)) ([f8a2521](https://github.com/moag1000/Little-ISMS-Helper/commit/f8a252110aa312809576968730a8aba97aa448a6))
* **form-layout:** _auto_form integration with fa-form-layout (P-2) ([#538](https://github.com/moag1000/Little-ISMS-Helper/issues/538)) ([260a50f](https://github.com/moag1000/Little-ISMS-Helper/commit/260a50f60f34eb35b77deeebc38e0bc3f1fccff7))
* **form-layout:** actually wire templates to outline-rail (post-review H-3 followup) ([#549](https://github.com/moag1000/Little-ISMS-Helper/issues/549)) ([a568284](https://github.com/moag1000/Little-ISMS-Helper/commit/a5682840b98d87f97a19517679db038fb581b78a))
* **form-layout:** Aurora outline-rail + section-cards foundation (P-1) ([#536](https://github.com/moag1000/Little-ISMS-Helper/issues/536)) ([082f6aa](https://github.com/moag1000/Little-ISMS-Helper/commit/082f6aa6cf21dbd6de065457df1dbc73c202b7fe))
* **form-layout:** migrate Settings FormTypes to fa-tabs (Tenant/User) ([#554](https://github.com/moag1000/Little-ISMS-Helper/issues/554)) ([f3083bd](https://github.com/moag1000/Little-ISMS-Helper/commit/f3083bd81db81daca7097180039398ac46bc8dee))
* **form-layout:** Sprint-A — migrate 5 fa-form-layout targets (DPIA/Risk/Audit/Policy/Control) ([#540](https://github.com/moag1000/Little-ISMS-Helper/issues/540)) ([20c8598](https://github.com/moag1000/Little-ISMS-Helper/commit/20c8598ba13f0899c2ed649ddc6781d59eedf392))
* **form-layout:** Sprint-B — migrate Supplier/ProcessingActivity/ManagementReview to outline-rail ([#541](https://github.com/moag1000/Little-ISMS-Helper/issues/541)) ([3a16b23](https://github.com/moag1000/Little-ISMS-Helper/commit/3a16b23d4048016fcba8f35ddfe61fcfb91902b1))
* **form-layout:** Sprint-C — re-enable Supplier + migrate 5 medium FormTypes (CrisisTeam/ChangeRequest/Asset/ThreatIntel/Patch) ([#544](https://github.com/moag1000/Little-ISMS-Helper/issues/544)) ([d2b166d](https://github.com/moag1000/Little-ISMS-Helper/commit/d2b166d7933755d4f553bd81a4fc84d3a142cc7c))
* **form-layout:** Sprint-D — migrate 10 medium FormTypes to outline-rail ([#553](https://github.com/moag1000/Little-ISMS-Helper/issues/553)) ([b4218de](https://github.com/moag1000/Little-ISMS-Helper/commit/b4218de0fa73181e89bc16a8e50af7fdd04b2cf9))
* **forms:** fa-form-layout coverage sweep — 6 FormTypes ([#642](https://github.com/moag1000/Little-ISMS-Helper/issues/642)) ([21fb595](https://github.com/moag1000/Little-ISMS-Helper/commit/21fb595242b9d05937b908f198baf02d90007ab1))
* **form:** Sprint-E — SectionMap on 9 medium FormTypes (10-14 fields, flat layout retained) ([#557](https://github.com/moag1000/Little-ISMS-Helper/issues/557)) ([b31990d](https://github.com/moag1000/Little-ISMS-Helper/commit/b31990d307f19eb6f61b3a3dd885b38f2cf27b53))
* **forms:** Quick-Create foundation + 5 picker-fixes (S14 Cluster A) ([#605](https://github.com/moag1000/Little-ISMS-Helper/issues/605)) ([c99f065](https://github.com/moag1000/Little-ISMS-Helper/commit/c99f065842d7d1a0fce3e43d13c08d0986a73e67))
* **i18n:** backfill 75 missing translation keys (Closes K-03) ([#592](https://github.com/moag1000/Little-ISMS-Helper/issues/592)) ([df1ffbb](https://github.com/moag1000/Little-ISMS-Helper/commit/df1ffbbc2773b6d4d58ff6a26eaf0d3e49b672fc))
* **i18n:** backfill 75+ missing translation keys (S12 §4-8 DoD) ([#655](https://github.com/moag1000/Little-ISMS-Helper/issues/655)) ([b429f02](https://github.com/moag1000/Little-ISMS-Helper/commit/b429f02ea5553338ea6e3b6e07b5c23331f9ffbb))
* **i18n:** backfill user/tenant/training validator keys + fix ai_agent flash domain ([#639](https://github.com/moag1000/Little-ISMS-Helper/issues/639)) ([4a4d081](https://github.com/moag1000/Little-ISMS-Helper/commit/4a4d081cd9b7ce12210863e7a44d280a3538c497))
* **i18n:** convert hardcoded EN validator messages to translation keys ([#601](https://github.com/moag1000/Little-ISMS-Helper/issues/601)) ([66ec6e3](https://github.com/moag1000/Little-ISMS-Helper/commit/66ec6e3ad935787e983ed9813d7ca219cde88234))
* **i18n:** EN-in-DE labels, placeholders, setup-9, locale help-link (S12) ([#599](https://github.com/moag1000/Little-ISMS-Helper/issues/599)) ([df0f658](https://github.com/moag1000/Little-ISMS-Helper/commit/df0f6584c5eeb5f71e02b286d4b2a83d05b956db))
* **i18n:** translate audit_log/statistics + compliance/framework_dashboard headings ([#622](https://github.com/moag1000/Little-ISMS-Helper/issues/622)) ([dda715a](https://github.com/moag1000/Little-ISMS-Helper/commit/dda715ad6fe9ae12e0c71573c928b9ad23ace9ab))
* **i18n:** wave 12 — Training entity validators + risk-forecast anomaly labels ([#641](https://github.com/moag1000/Little-ISMS-Helper/issues/641)) ([81692ed](https://github.com/moag1000/Little-ISMS-Helper/commit/81692ed1988d6d8b7d74c53a3047fec26ee372a3))
* **i18n:** wave 13 — AI-Agent show + Compliance/BCM table headers (18 keys) ([#643](https://github.com/moag1000/Little-ISMS-Helper/issues/643)) ([7d85145](https://github.com/moag1000/Little-ISMS-Helper/commit/7d8514514f20f544a2d3644c2884c9aa647ae179))
* **i18n:** wave 9 — data_breach dashboard full i18n + remaining hero sections ([#633](https://github.com/moag1000/Little-ISMS-Helper/issues/633)) ([135fdb6](https://github.com/moag1000/Little-ISMS-Helper/commit/135fdb6f2f8e6c04f2d7c37afaa43ee40cde2147))
* **job:** P-16 AsyncJobDispatcher facade (S11/S13) ([#618](https://github.com/moag1000/Little-ISMS-Helper/issues/618)) ([199aad6](https://github.com/moag1000/Little-ISMS-Helper/commit/199aad693e90fef02b8e1e454737775095e78f3c))
* **lifecycle-overrides:** add description columns to admin tables ([#450](https://github.com/moag1000/Little-ISMS-Helper/issues/450)) ([840f0c4](https://github.com/moag1000/Little-ISMS-Helper/commit/840f0c461f05612ca8600401de7946dac9490001))
* **lifecycle:** extend Symfony-Workflow to 10 more entities (Sprint Y.5, closes Status-Hijack surface) ([#487](https://github.com/moag1000/Little-ISMS-Helper/issues/487)) ([2b667b2](https://github.com/moag1000/Little-ISMS-Helper/commit/2b667b2b59f9c7a31517f61119dc305087bcd8e6))
* **lifecycle:** InterestedParty (S-01) + CorrectiveAction forced-verify transition ([#603](https://github.com/moag1000/Little-ISMS-Helper/issues/603)) ([0e4c37c](https://github.com/moag1000/Little-ISMS-Helper/commit/0e4c37cb28ac195766db5c51ead2838cd7d00040))
* **lifecycle:** Phase 2 — NotificationDelivery (6 stages); Tenant deferred ([#635](https://github.com/moag1000/Little-ISMS-Helper/issues/635)) ([f5a2d3a](https://github.com/moag1000/Little-ISMS-Helper/commit/f5a2d3a920fc56ff27f041c7e98eb42424652275))
* **lifecycle:** Phase 2 — Tenant (5 stages, 4-eyes guards, derived isActive) ([#640](https://github.com/moag1000/Little-ISMS-Helper/issues/640)) ([1274c72](https://github.com/moag1000/Little-ISMS-Helper/commit/1274c721593f2bce9feaa066edd2eacc4be00d27))
* **lifecycle:** Phase 2 RBAC — Permission + Role entities ([#634](https://github.com/moag1000/Little-ISMS-Helper/issues/634)) ([2300026](https://github.com/moag1000/Little-ISMS-Helper/commit/23000262a54b4b935dcbe4a3f447146c448c6c79))
* **lifecycle:** X.6 complete — migrate 3 remaining DataBreachService setStatus calls ([#451](https://github.com/moag1000/Little-ISMS-Helper/issues/451)) ([06d2503](https://github.com/moag1000/Little-ISMS-Helper/commit/06d25034ff1031447612b3248d76b2ef375f3fbe))
* **lifecycle:** Y.1 extract FieldCompletionAutoTransitionInterface ([#452](https://github.com/moag1000/Little-ISMS-Helper/issues/452)) ([d3ff664](https://github.com/moag1000/Little-ISMS-Helper/commit/d3ff664e4310e90e22df5905c7fb4794dcd8a2e2))
* **modal-wizard:** Aurora high-stakes linear-flow modal foundation ([#537](https://github.com/moag1000/Little-ISMS-Helper/issues/537)) ([d4bb5e7](https://github.com/moag1000/Little-ISMS-Helper/commit/d4bb5e7d61804b0d3271fcebec81e6f4099f0124))
* **norm-bridge:** P-12 rollout to BP/Document/BCM forms — ISO 9001 + 22301 references ([#614](https://github.com/moag1000/Little-ISMS-Helper/issues/614)) ([e3da7ac](https://github.com/moag1000/Little-ISMS-Helper/commit/e3da7ac3a6fae027f483c5d335872e30c8f00e4f))
* **norm-bridge:** show-page rollout for BP/Document/BCM (P-12 follow-up to [#614](https://github.com/moag1000/Little-ISMS-Helper/issues/614)) ([#617](https://github.com/moag1000/Little-ISMS-Helper/issues/617)) ([1770da9](https://github.com/moag1000/Little-ISMS-Helper/commit/1770da94bec995a0e8464a5eace15ac1487a8608))
* **norm-help:** ISO 27005 reference in Risk-Form + A.5.30 ICT-Continuity in BP-Form ([#630](https://github.com/moag1000/Little-ISMS-Helper/issues/630)) ([422d47e](https://github.com/moag1000/Little-ISMS-Helper/commit/422d47e34c7e83555e9c2f15c257da021988aa38))
* **owner-picker:** default to current user on new (4.11) ([#631](https://github.com/moag1000/Little-ISMS-Helper/issues/631)) ([14018fd](https://github.com/moag1000/Little-ISMS-Helper/commit/14018fd4519d8b771527a83a92e822685f49d1e1))
* **risk+soa:** treatmentStrategy pflicht (S-02) + applicability-toggle visual required (S-07) ([#604](https://github.com/moag1000/Little-ISMS-Helper/issues/604)) ([e6c1dea](https://github.com/moag1000/Little-ISMS-Helper/commit/e6c1deab1c37d43554e78b2e97038916cf50b701))
* **risk:** Aurora-rework Risk-Heatmap — tokens + fa-drawer + fa-empty-state ([#621](https://github.com/moag1000/Little-ISMS-Helper/issues/621)) ([7e79764](https://github.com/moag1000/Little-ISMS-Helper/commit/7e79764943d79173fa91b866b17cab2ac3db2ef6))
* **risk:** inline 5×5 mini-heatmap in Risk-Form with live-update ([#619](https://github.com/moag1000/Little-ISMS-Helper/issues/619)) ([325cd66](https://github.com/moag1000/Little-ISMS-Helper/commit/325cd66e4f3b57cb3c538952fdf28c75dba713a0))
* **risk:** live mini-heatmap in form — T6.8 UX-P1 ([#610](https://github.com/moag1000/Little-ISMS-Helper/issues/610)) ([fcc4bf3](https://github.com/moag1000/Little-ISMS-Helper/commit/fcc4bf3cd2e6b29247578c8e508342e7db725130))
* **risk:** S10 K-05/K-06 + S11 M-02/M-03 Risk-Form audit-trail + validators + threshold SSoT ([#588](https://github.com/moag1000/Little-ISMS-Helper/issues/588)) ([1633aa9](https://github.com/moag1000/Little-ISMS-Helper/commit/1633aa9104111dcf630d643f93c49fdf5f13fe49))
* **security:** Phase 5 — service-layer tenant filter for backups ([#472](https://github.com/moag1000/Little-ISMS-Helper/issues/472)) ([0dacfbd](https://github.com/moag1000/Little-ISMS-Helper/commit/0dacfbd1cc2cae6569cefe62846c9532fe152351))
* **security:** Phase 6 — persona-role module visibility ([#470](https://github.com/moag1000/Little-ISMS-Helper/issues/470)) ([34af07b](https://github.com/moag1000/Little-ISMS-Helper/commit/34af07be5bcf522e3c0a7cb5641ed1aff1d8e99d))
* **security:** re-auth challenge as fa-modal (multi-provider — password/SSO/SAML) ([#574](https://github.com/moag1000/Little-ISMS-Helper/issues/574)) ([b15a140](https://github.com/moag1000/Little-ISMS-Helper/commit/b15a140728cac3b785cd1ebd92ee9990a041f958))
* **security:** Role-Scope Phase 1 - TenantScopedAdminVoter foundation ([#458](https://github.com/moag1000/Little-ISMS-Helper/issues/458)) ([33ac4e3](https://github.com/moag1000/Little-ISMS-Helper/commit/33ac4e3b0c8ccc8d86f549bf0f831127e819626d))
* **security:** Role-Scope Phase 3 — AdminBackupController uses TenantScopedAdminVoter ([#463](https://github.com/moag1000/Little-ISMS-Helper/issues/463)) ([87913eb](https://github.com/moag1000/Little-ISMS-Helper/commit/87913eb80088379cd6e4cab1d549d2ec08870bbb))
* **soa:** gate MRIS filter behind module + tenant setting (S14+ §14) ([#657](https://github.com/moag1000/Little-ISMS-Helper/issues/657)) ([b971dc0](https://github.com/moag1000/Little-ISMS-Helper/commit/b971dc0052642cb0e5e92844e733de73f0edfa9b))
* **supplier:** LEI/NACE inline format-hint via HTML5 pattern + title (S14+ §19) ([#659](https://github.com/moag1000/Little-ISMS-Helper/issues/659)) ([60a3be8](https://github.com/moag1000/Little-ISMS-Helper/commit/60a3be87f1ff23a23d9c9b582d121db8017c356e))
* **supplier:** Wave Y.6 Symfony Workflow lifecycle ([#585](https://github.com/moag1000/Little-ISMS-Helper/issues/585)) ([f996c7c](https://github.com/moag1000/Little-ISMS-Helper/commit/f996c7c11d1348f34b2a25ccf84dac69f0d7fd01))
* **tabs:** Aurora settings-form tab-group foundation ([#539](https://github.com/moag1000/Little-ISMS-Helper/issues/539)) ([f00d090](https://github.com/moag1000/Little-ISMS-Helper/commit/f00d090a7535ebba05354cedd8fde02aa3658aba))
* **training:** materials File-Upload + attendeeCount auto-sync ([#632](https://github.com/moag1000/Little-ISMS-Helper/issues/632)) ([b64f760](https://github.com/moag1000/Little-ISMS-Helper/commit/b64f76004f0d37649a95a5d776b9e77105d8e35c))
* **ux:** P-13 SeverityDefinitions — read-only severity-stage legend below form fields ([#609](https://github.com/moag1000/Little-ISMS-Helper/issues/609)) ([423b871](https://github.com/moag1000/Little-ISMS-Helper/commit/423b871f4b19e32ddd81700f0bd9712684ba0db5))
* **ux:** T6.3 — section-intro alerts for Brutto/Netto on Risk-form ([#612](https://github.com/moag1000/Little-ISMS-Helper/issues/612)) ([5b877db](https://github.com/moag1000/Little-ISMS-Helper/commit/5b877db8ab5cf35ae337055f98b851a3ca3763b8))
* **ux:** UX_SCORING sweep — T4.11/T9.10/T10.10/T2.13 quick polish ([#611](https://github.com/moag1000/Little-ISMS-Helper/issues/611)) ([77de12d](https://github.com/moag1000/Little-ISMS-Helper/commit/77de12d64a84b4a536d72e844a0b4adee0e641d5))
* **ux:** wave 10 bundle — BCP RTO/RPO context + Tenant wording + Holding-Gate ([#636](https://github.com/moag1000/Little-ISMS-Helper/issues/636)) ([e1572c2](https://github.com/moag1000/Little-ISMS-Helper/commit/e1572c226b489b41e34499380d5db978deea6513))
* **ux:** wave 11 bundle — T7.5 + monetaryValue deprecation + T17 tooltip compress ([#638](https://github.com/moag1000/Little-ISMS-Helper/issues/638)) ([aaf1a0c](https://github.com/moag1000/Little-ISMS-Helper/commit/aaf1a0c7498cfc943893796b2e54ebb73a745186))
* **ux:** wave 14 — Norm-Bridge P-12 rollout to Incident/AuditFinding/CAPA/Training/Asset ([#646](https://github.com/moag1000/Little-ISMS-Helper/issues/646)) ([429dc7d](https://github.com/moag1000/Little-ISMS-Helper/commit/429dc7df5e80165714b25b54f735b0f73b1be941))
* **ux:** wave 15 — solve remaining open UX_SCORING items (T4.2 first, more incoming) ([#647](https://github.com/moag1000/Little-ISMS-Helper/issues/647)) ([12e1ac8](https://github.com/moag1000/Little-ISMS-Helper/commit/12e1ac8c8e98c4182d9f6275e55b92e5e0eeb952))
* **ux:** wave 7 — section-intros, dashboards i18n, Junior-ISB-Audit polish (10+ items) ([#627](https://github.com/moag1000/Little-ISMS-Helper/issues/627)) ([2df2750](https://github.com/moag1000/Little-ISMS-Helper/commit/2df2750017534dc52731834aecc2c6a4ce15fa1a))
* **ux:** wave 8 — responsible_person clarity + asset-type dropdown hints ([#628](https://github.com/moag1000/Little-ISMS-Helper/issues/628)) ([4c2011e](https://github.com/moag1000/Little-ISMS-Helper/commit/4c2011ea4bdcc2ae84861063871cdea782f6635f))
* **vvt:** expose processorSuppliers in ProcessingActivityType (Closes K-02) ([#584](https://github.com/moag1000/Little-ISMS-Helper/issues/584)) ([05a538a](https://github.com/moag1000/Little-ISMS-Helper/commit/05a538ae888ce3e39b70b89289a4e6ae83a1cd1c))
* **vvt:** Joint Controller selection conditional on isJointController (Closes M-08) ([#590](https://github.com/moag1000/Little-ISMS-Helper/issues/590)) ([b4829de](https://github.com/moag1000/Little-ISMS-Helper/commit/b4829de1719d637361da26883d0733b4abd74f4a))
* **wizard:** seed M-04/M-05/M-06 templates + wire selector on new forms ([#593](https://github.com/moag1000/Little-ISMS-Helper/issues/593)) ([2b112b9](https://github.com/moag1000/Little-ISMS-Helper/commit/2b112b9d7a4cea07441df1635688d7013085f120))


### Fixed

* **admin:** backup index visible to ROLE_ADMIN (was SUPER_ADMIN) ([#446](https://github.com/moag1000/Little-ISMS-Helper/issues/446)) ([e8cc296](https://github.com/moag1000/Little-ISMS-Helper/commit/e8cc296bc251b17ab98e89df0b1eee3cc569a56e))
* **asset:** validateOwnerSlot parity in AssetQuickType (S13) ([#623](https://github.com/moag1000/Little-ISMS-Helper/issues/623)) ([3bc2fe8](https://github.com/moag1000/Little-ISMS-Helper/commit/3bc2fe87170cc2235eee2368a249765a3ced7ef4))
* **audit:** bulk follow-up — PII RBAC + Stimulus icons + tabs template (H-1/H-2/H-4) ([#545](https://github.com/moag1000/Little-ISMS-Helper/issues/545)) ([81cecca](https://github.com/moag1000/Little-ISMS-Helper/commit/81cecca66cae5717ca9a94ae491b831ebb68dd82))
* **aurora-icons:** 23 alias rules for broken icons (5 lifecycle + 18 dynamic) ([#483](https://github.com/moag1000/Little-ISMS-Helper/issues/483)) ([c98a204](https://github.com/moag1000/Little-ISMS-Helper/commit/c98a204ca2ca913e2c6008eb7cbe443ff003bb1e))
* **aurora:** align CSS with canonical design-system spec ([#535](https://github.com/moag1000/Little-ISMS-Helper/issues/535)) ([06b7fab](https://github.com/moag1000/Little-ISMS-Helper/commit/06b7fab69424ad11dfa7842b63aeb7444423a4dc))
* **aurora:** fa-modal.confirm() — no &lt;form&gt; when formAction omitted ([#562](https://github.com/moag1000/Little-ISMS-Helper/issues/562)) ([a5f9a85](https://github.com/moag1000/Little-ISMS-Helper/commit/a5f9a8544d47a4153fc214c5769f1014728d97dc))
* **backup-ui:** JS syntax error blocked submit listener ([#502](https://github.com/moag1000/Little-ISMS-Helper/issues/502)) ([c6f5f3b](https://github.com/moag1000/Little-ISMS-Helper/commit/c6f5f3b625533730490ad3fdece17dac96f5be37))
* **backup:** entity-coverage completeness + Gate 43 CI guardrail ([#494](https://github.com/moag1000/Little-ISMS-Helper/issues/494)) ([d8000d1](https://github.com/moag1000/Little-ISMS-Helper/commit/d8000d1729dc3422ed60966c2e5044f3a477303c))
* **browser:** dashboard 404 + bulkDeleteModal a11y + console-noise cleanup ([#563](https://github.com/moag1000/Little-ISMS-Helper/issues/563)) ([32e46e8](https://github.com/moag1000/Little-ISMS-Helper/commit/32e46e81764d2faa59d1015374a84696e5f97df5))
* **bulk-actions:** bar clicks broken after teleport — switch to CSS-based stacking-context escape ([#564](https://github.com/moag1000/Little-ISMS-Helper/issues/564)) ([d31f3c1](https://github.com/moag1000/Little-ISMS-Helper/commit/d31f3c13148aa3a8bbe4eb2cd0e9792dd96f7e07))
* **bulk-bar:** cache bar element ref before body-teleport (Stimulus scope bug) ([#492](https://github.com/moag1000/Little-ISMS-Helper/issues/492)) ([afea5a2](https://github.com/moag1000/Little-ISMS-Helper/commit/afea5a23fa3fe1bf4b8f5ec53827141515ecfa46))
* **bulk-bar:** teleport to &lt;body&gt; on connect to escape ancestor stacking-context ([#485](https://github.com/moag1000/Little-ISMS-Helper/issues/485)) ([0b68009](https://github.com/moag1000/Little-ISMS-Helper/commit/0b68009168f51380c41182b488d90ad86fbeb232))
* **compliance-wizard:** proper fix for consent-coverage test (unskip) ([#548](https://github.com/moag1000/Little-ISMS-Helper/issues/548)) ([f36f64d](https://github.com/moag1000/Little-ISMS-Helper/commit/f36f64d2262676627c38d05e09be99f4d48eb789))
* **criticals:** batch A — route guard + form unwrap + workflow boundary + schema NULL-backfill ([#508](https://github.com/moag1000/Little-ISMS-Helper/issues/508)) ([21183f1](https://github.com/moag1000/Little-ISMS-Helper/commit/21183f13a009996c48c052dfaaa19754e7fd7b74))
* **criticals:** batch C — JSON-form safety + lifecycle fallback removal ([#509](https://github.com/moag1000/Little-ISMS-Helper/issues/509)) ([bd17520](https://github.com/moag1000/Little-ISMS-Helper/commit/bd175204c09e144636934984f360e5340b3de02f))
* **dashboard:** customizer modal toggles didn't persist widget on/off ([#565](https://github.com/moag1000/Little-ISMS-Helper/issues/565)) ([cd983c5](https://github.com/moag1000/Little-ISMS-Helper/commit/cd983c564dc2ef85b5f545487375246e97de0481))
* **data-repair:** per-iteration flush + EM-reset (rebased) ([#498](https://github.com/moag1000/Little-ISMS-Helper/issues/498)) ([a4f635d](https://github.com/moag1000/Little-ISMS-Helper/commit/a4f635d140b2e6df9f0203538c11edee7f42d629))
* **data-repair:** use Aurora _fa_feature_card for health-score KPI tiles ([#497](https://github.com/moag1000/Little-ISMS-Helper/issues/497)) ([0f80ed6](https://github.com/moag1000/Little-ISMS-Helper/commit/0f80ed61359c64d902d1ffb583ecf025c7f4c1a1))
* **form-layout:** always render section bodies — collapse is visual-only ([#542](https://github.com/moag1000/Little-ISMS-Helper/issues/542)) ([def9867](https://github.com/moag1000/Little-ISMS-Helper/commit/def98672c5a9a2d86a394a655025001b2b8ca880))
* **form-layout:** emit sectionsState as object, not array of single-key objects ([#561](https://github.com/moag1000/Little-ISMS-Helper/issues/561)) ([58df506](https://github.com/moag1000/Little-ISMS-Helper/commit/58df506a524d7b2edcbc94f0354f3c4e93787f14))
* **i18n:** inline 6 GDPR Art. 6(1) legal_basis values in help text ([#662](https://github.com/moag1000/Little-ISMS-Helper/issues/662)) ([0ae96c9](https://github.com/moag1000/Little-ISMS-Helper/commit/0ae96c9603eb35149654f4569af3356088adea8b))
* **i18n:** IP communication + feedback fields — 'what if empty' help ([#663](https://github.com/moag1000/Little-ISMS-Helper/issues/663)) ([b73aa4d](https://github.com/moag1000/Little-ISMS-Helper/commit/b73aa4d79aaa026eaaf3feee565a33a8bd13a4a4))
* **i18n:** make Stimulus controllers locale-aware (audit C-4 + H-1) ([#530](https://github.com/moag1000/Little-ISMS-Helper/issues/530)) ([bd24653](https://github.com/moag1000/Little-ISMS-Helper/commit/bd246534e4d70aadade30b603a49476470c32aba))
* **i18n:** translate top-level 'Shareholder' EN-in-DE leftover ([#661](https://github.com/moag1000/Little-ISMS-Helper/issues/661)) ([c2993e1](https://github.com/moag1000/Little-ISMS-Helper/commit/c2993e1c936a670d281d499f75ff10538608a16f))
* **i18n:** UX Wave A — add 54 missing translation keys + fix wrong-domain refs ([#578](https://github.com/moag1000/Little-ISMS-Helper/issues/578)) ([3db57bd](https://github.com/moag1000/Little-ISMS-Helper/commit/3db57bd0b01f7cd1ec73cbaa985c7ae491a1ee94))
* **incident:** default detectedAt to now() (Closes K-01) ([#587](https://github.com/moag1000/Little-ISMS-Helper/issues/587)) ([f6da29e](https://github.com/moag1000/Little-ISMS-Helper/commit/f6da29e11678a7a4ecb97de09ff4b43c00352d8e))
* **lifecycle:** close FormType Status-Hijack + add FourEyesValidator (incl. KMU single-user escape) ([#481](https://github.com/moag1000/Little-ISMS-Helper/issues/481)) ([fecc3bd](https://github.com/moag1000/Little-ISMS-Helper/commit/fecc3bd07f5556936a9e6b65471271b91f976fd3))
* **lifecycle:** YAML auto-progression field-name corrections (audit C-1) ([#532](https://github.com/moag1000/Little-ISMS-Helper/issues/532)) ([f660d12](https://github.com/moag1000/Little-ISMS-Helper/commit/f660d12d3e42d5125733e4dee279df8665444c72))
* **modules:** mark objectives as required + remove phantom module gates ([#665](https://github.com/moag1000/Little-ISMS-Helper/issues/665)) ([6162f3c](https://github.com/moag1000/Little-ISMS-Helper/commit/6162f3c3c2de4c3d394d2bb4aa3d3a67483a1d62))
* **privacy:** Aurora-conform GDPR data-breach wizard modal ([#531](https://github.com/moag1000/Little-ISMS-Helper/issues/531)) ([175f2e5](https://github.com/moag1000/Little-ISMS-Helper/commit/175f2e5baaa4f9c9b5f98c612a4e96b2a87fc7a2))
* **privacy:** redact filesystem paths + PII in monitoring/health + screenshots ([#500](https://github.com/moag1000/Little-ISMS-Helper/issues/500)) ([ea2bcaa](https://github.com/moag1000/Little-ISMS-Helper/commit/ea2bcaa903b9003822a5887192add2ae7ab6b275))
* **risk:** drop blanket NotNull on riskOwner — either-or with riskOwnerPerson (K-05) ([#589](https://github.com/moag1000/Little-ISMS-Helper/issues/589)) ([8b88ef4](https://github.com/moag1000/Little-ISMS-Helper/commit/8b88ef452a7c2cf8d07bcc09207a702c4db5bbf0))
* **routing:** explicit methods + drop trailing-slash (50+ routes) ([#516](https://github.com/moag1000/Little-ISMS-Helper/issues/516)) ([d25eed5](https://github.com/moag1000/Little-ISMS-Helper/commit/d25eed5b3b71d8625a8236a48a79f5eb4b521d31))
* **schema-reconcile:** disable FK checks during batch (release blocker) ([#460](https://github.com/moag1000/Little-ISMS-Helper/issues/460)) ([f5121cc](https://github.com/moag1000/Little-ISMS-Helper/commit/f5121cc2111d10a2012c9d120b5cc2bfe26bee1f))
* **schema-reconcile:** errno 150/1822 swallow + multi-pass convergence ([#461](https://github.com/moag1000/Little-ISMS-Helper/issues/461)) ([60a9549](https://github.com/moag1000/Little-ISMS-Helper/commit/60a9549b9216e3a88630346c3e5716ccdd3ad5ae))
* **schema-reconcile:** phantom-drift detection + auto-marked visibility ([#464](https://github.com/moag1000/Little-ISMS-Helper/issues/464)) ([cc5e8ca](https://github.com/moag1000/Little-ISMS-Helper/commit/cc5e8cab1605921feebd25f0138e8836fe998bfd))
* **schema-reconcile:** swallow MySQL 1091/1176 'FK/INDEX already absent' errors ([#459](https://github.com/moag1000/Little-ISMS-Helper/issues/459)) ([24aebde](https://github.com/moag1000/Little-ISMS-Helper/commit/24aebde8ac0dcd9d427eebbb5a1fccac059ed8b8))
* **schema-reconcile:** widen DBAL exception catch + expose auto-mark failures ([#484](https://github.com/moag1000/Little-ISMS-Helper/issues/484)) ([3cdb0be](https://github.com/moag1000/Little-ISMS-Helper/commit/3cdb0be29a6a7ac5903dd77f1f554023f1cdc552))
* **security:** audit pre-cert critical+high — 1C/5H/3M ([#529](https://github.com/moag1000/Little-ISMS-Helper/issues/529)) ([fbb4320](https://github.com/moag1000/Little-ISMS-Helper/commit/fbb43200acdd9cd95e76b34142aa1bf5c33f2c8c))
* **security:** CSRF coverage on 18 mutation endpoints (C-04 batch B) ([#510](https://github.com/moag1000/Little-ISMS-Helper/issues/510)) ([d1642a8](https://github.com/moag1000/Little-ISMS-Helper/commit/d1642a8381a82a4c41eeae7b70283940a81c461f))
* **security:** CSRF protection for all bulk-action endpoints (audit C-1) ([#546](https://github.com/moag1000/Little-ISMS-Helper/issues/546)) ([26e946d](https://github.com/moag1000/Little-ISMS-Helper/commit/26e946d63ae7c6d77e0a58c217c583f625f5125f))
* **security:** downgrade IS_AUTHENTICATED_FULLY → REMEMBERED for token-auth users ([#493](https://github.com/moag1000/Little-ISMS-Helper/issues/493)) ([9110d93](https://github.com/moag1000/Little-ISMS-Helper/commit/9110d9363ee5e731255c55b28ec25fae8c22be26))
* **security:** H-03 auto-form whitelist (71 templates → 11 fixed, dev-warn rest) ([#515](https://github.com/moag1000/Little-ISMS-Helper/issues/515)) ([5a0aed0](https://github.com/moag1000/Little-ISMS-Helper/commit/5a0aed02944678f3153188962e3d15b0071b1913))
* **security:** M-4 module-gating Show-Template audit ([#523](https://github.com/moag1000/Little-ISMS-Helper/issues/523)) ([01897f8](https://github.com/moag1000/Little-ISMS-Helper/commit/01897f819914f883ff1febc4fcff69d2d1d26328))
* **security:** re-auth via entry_point — actually intercept RememberMe redirects ([#577](https://github.com/moag1000/Little-ISMS-Helper/issues/577)) ([635a627](https://github.com/moag1000/Little-ISMS-Helper/commit/635a627cbb572771c583c849aa120cbbf8a77e36))
* **security:** RiskAcceptanceVoter honors role_hierarchy ([#582](https://github.com/moag1000/Little-ISMS-Helper/issues/582) follow-up) ([#613](https://github.com/moag1000/Little-ISMS-Helper/issues/613)) ([2581c73](https://github.com/moag1000/Little-ISMS-Helper/commit/2581c731f6d791744b086c68de5931d88f62716c))
* **soa:** index hang + extreme memory (root-cause + fix) ([#566](https://github.com/moag1000/Little-ISMS-Helper/issues/566)) ([1e732dd](https://github.com/moag1000/Little-ISMS-Helper/commit/1e732dd0465c23fb2310259a8d304cd93b19c50a))
* **soa:** sticky-header — migrate table to fa-table Aurora component ([#576](https://github.com/moag1000/Little-ISMS-Helper/issues/576)) ([a84d332](https://github.com/moag1000/Little-ISMS-Helper/commit/a84d3321b9a3a8cc279b054ca9981585bfdb4e98))
* **status-enum:** YAML ↔ Form ↔ Enum drift reconciliation (7 entities) ([#466](https://github.com/moag1000/Little-ISMS-Helper/issues/466)) ([cefae03](https://github.com/moag1000/Little-ISMS-Helper/commit/cefae03488f91701a1e0483165c4b37b1e97add3))
* **stimulus:** restore 4 false-positive deleted controllers from [#567](https://github.com/moag1000/Little-ISMS-Helper/issues/567) ([#572](https://github.com/moag1000/Little-ISMS-Helper/issues/572)) ([6baca9f](https://github.com/moag1000/Little-ISMS-Helper/commit/6baca9f6105ae6b48952dac64de9f6ad4694ebfa))
* **stimulus:** surface 4xx/5xx errors via toast (9 fetch-sites) ([#568](https://github.com/moag1000/Little-ISMS-Helper/issues/568)) ([10d119e](https://github.com/moag1000/Little-ISMS-Helper/commit/10d119e5279ad827dfb6513393374d15b1d362a3))
* **tests:** satisfy setup_complete.lock gate in ComplianceWizardController tests ([#552](https://github.com/moag1000/Little-ISMS-Helper/issues/552)) ([d55989e](https://github.com/moag1000/Little-ISMS-Helper/commit/d55989edcd4d11490897f35de35e8693ef6b0700))
* **twig:** escape nested {# #} in _auto_form docstring (leaked to UI) ([#560](https://github.com/moag1000/Little-ISMS-Helper/issues/560)) ([561c11f](https://github.com/moag1000/Little-ISMS-Helper/commit/561c11f5204a6958c5a6560ce4864598abac2b68))
* **ui:** H-02 replace Aurora+BS utility-mixes with proper Aurora components (3 templates) ([#518](https://github.com/moag1000/Little-ISMS-Helper/issues/518)) ([9a363b6](https://github.com/moag1000/Little-ISMS-Helper/commit/9a363b62e2e090ce581551d0186fef625da3b5ab))
* **ui:** H-quickwins — Aurora icons + flash domains + Stimulus cleanup ([#513](https://github.com/moag1000/Little-ISMS-Helper/issues/513)) ([943b481](https://github.com/moag1000/Little-ISMS-Helper/commit/943b481c6deb43b556bbe9de7e58ee4f008760c1))
* **ui:** M-6 training/show Aurora conformance (8 .card-header utility mixes) ([#519](https://github.com/moag1000/Little-ISMS-Helper/issues/519)) ([1618ecf](https://github.com/moag1000/Little-ISMS-Helper/commit/1618ecf981672c6530e28a2baa6d42d8affd1421))
* use for-loop accumulator with object-merge to build a single ([58df506](https://github.com/moag1000/Little-ISMS-Helper/commit/58df506a524d7b2edcbc94f0354f3c4e93787f14))
* **ux-p0:** BIA-draft survival + i18n locale-mix cleanup ([#581](https://github.com/moag1000/Little-ISMS-Helper/issues/581)) ([ea7cbaf](https://github.com/moag1000/Little-ISMS-Helper/commit/ea7cbaf1dadcfe54ab7f8a7082e0ca1a1c51bd01))
* **ux-p0:** MTPD&gt;RTO validator, Risk-Decision audit-fields, score-band vocab align ([#579](https://github.com/moag1000/Little-ISMS-Helper/issues/579)) ([ed6c16e](https://github.com/moag1000/Little-ISMS-Helper/commit/ed6c16e65293700427fcb6be62d9c5f54a039545))
* **ux:** T5.2 risk-category tooltip alignment + T13.3 audit clause i18n ([#616](https://github.com/moag1000/Little-ISMS-Helper/issues/616)) ([492e088](https://github.com/moag1000/Little-ISMS-Helper/commit/492e088122b4f1be8f516c7f7d6af18338354660))


### Changed

* **audit-log:** migrate 3 audit_log/* feature pages to .fa-audit-feed canonical ([#482](https://github.com/moag1000/Little-ISMS-Helper/issues/482)) ([3d78bbc](https://github.com/moag1000/Little-ISMS-Helper/commit/3d78bbc3911b8e4c67f4905398a31b18f2c2a64d))
* **audit-log:** migrate templates to .fa-audit-feed/.fa-audit-row flat-grid canonical ([#480](https://github.com/moag1000/Little-ISMS-Helper/issues/480)) ([b8ed126](https://github.com/moag1000/Little-ISMS-Helper/commit/b8ed1261fd25410d9e2067d3ec1147616bd4e604))
* **compliance-wizard:** decompose god-class (6046 → 489 LOC, extract 5 sub-services) ([#556](https://github.com/moag1000/Little-ISMS-Helper/issues/556)) ([09bb643](https://github.com/moag1000/Little-ISMS-Helper/commit/09bb6435d631b0eee39edd9b7f4dd85c9b7c0d2a))
* **di:** M-5 migrate TaggedLocator → AutowireLocator (Symfony 8.0 prep) ([#517](https://github.com/moag1000/Little-ISMS-Helper/issues/517)) ([8468487](https://github.com/moag1000/Little-ISMS-Helper/commit/84684871d59cd1504dd3dae2a13768df6eaca4b9))
* **m-07:** god-class small-decomposition + baseline reduce (1 file) ([#527](https://github.com/moag1000/Little-ISMS-Helper/issues/527)) ([9b5ea50](https://github.com/moag1000/Little-ISMS-Helper/commit/9b5ea504882ad08aad5ed28f8a10edeae6ed06a0))
* **security:** Gate 41 zero-baseline (ImportHistoryController → ADMIN_OWN_TENANT) ([#474](https://github.com/moag1000/Little-ISMS-Helper/issues/474)) ([d96048f](https://github.com/moag1000/Little-ISMS-Helper/commit/d96048fb528dddb0972034ca6095d2b9a6e3106e))
* **security:** Phase 4a — SSO controllers role-scope migration ([#467](https://github.com/moag1000/Little-ISMS-Helper/issues/467)) ([f9c414d](https://github.com/moag1000/Little-ISMS-Helper/commit/f9c414db0168d34b6483d492a81e20fcfd4ec1bf))
* **security:** Phase 4b — import controllers role-scope migration ([#469](https://github.com/moag1000/Little-ISMS-Helper/issues/469)) ([ba938c8](https://github.com/moag1000/Little-ISMS-Helper/commit/ba938c8513aa5687084c5dceecefc4f7846ce18d))
* **security:** Phase 4c — per-tenant config controllers role-scope migration ([#471](https://github.com/moag1000/Little-ISMS-Helper/issues/471)) ([9b0c77d](https://github.com/moag1000/Little-ISMS-Helper/commit/9b0c77dc883a47ffe50de113a6d3bdcfeb8680d5))
* **security:** Phase 4d — notification controllers role-scope migration ([#468](https://github.com/moag1000/Little-ISMS-Helper/issues/468)) ([e62230d](https://github.com/moag1000/Little-ISMS-Helper/commit/e62230d719cefcb68ab40d5f838372441417fd3b))
* **security:** Phase 4e — system-settings controllers role-scope migration ([#473](https://github.com/moag1000/Little-ISMS-Helper/issues/473)) ([05f6750](https://github.com/moag1000/Little-ISMS-Helper/commit/05f6750e91786fdd4b26503eb8586bfe9f575423))
* **status-enum:** caller-sweep batch A — privacy/policy (7 entities) ([#454](https://github.com/moag1000/Little-ISMS-Helper/issues/454)) ([e41e390](https://github.com/moag1000/Little-ISMS-Helper/commit/e41e3901a57c2e82f29cb21553d2faab03119783))
* **status-enum:** caller-sweep batch B — ISMS-core + BCM (8 entities) ([#457](https://github.com/moag1000/Little-ISMS-Helper/issues/457)) ([aa790d1](https://github.com/moag1000/Little-ISMS-Helper/commit/aa790d1578bdc90f9f97f3585ce2d4ceda8823ce))
* **status-enum:** caller-sweep batch C — remediation/security/assets (10 entities) ([#456](https://github.com/moag1000/Little-ISMS-Helper/issues/456)) ([713a9ae](https://github.com/moag1000/Little-ISMS-Helper/commit/713a9ae0d898b289e803d07017a60d64772d3408))
* **status-enum:** caller-sweep batch D — workflow/wizard/training/rest (15 entities) ([#455](https://github.com/moag1000/Little-ISMS-Helper/issues/455)) ([7b40ab0](https://github.com/moag1000/Little-ISMS-Helper/commit/7b40ab0e61f8f02c38adec6f910aac26519a2426))
* **workflow:** drop deprecated AutoProgressionService — listener-only ([#514](https://github.com/moag1000/Little-ISMS-Helper/issues/514)) ([4a5e494](https://github.com/moag1000/Little-ISMS-Helper/commit/4a5e494be0bfea598f1363498a82f01eb5e76559))


### Documentation

* **adr:** CAPA-Canonical-Process — consolidation plan (M-07) ([#591](https://github.com/moag1000/Little-ISMS-Helper/issues/591)) ([97918d2](https://github.com/moag1000/Little-ISMS-Helper/commit/97918d22e53a4be4591a6c7ad85973244db8af1c))
* **readme:** refresh stack versions + feature list (v3.8 state) ([#478](https://github.com/moag1000/Little-ISMS-Helper/issues/478)) ([7d92cdf](https://github.com/moag1000/Little-ISMS-Helper/commit/7d92cdf5d1881c2bcd55cab65b0590be8e224608))
* **security:** mark Role-Scope Architecture rollout as complete ([#475](https://github.com/moag1000/Little-ISMS-Helper/issues/475)) ([75847bb](https://github.com/moag1000/Little-ISMS-Helper/commit/75847bbd7486a17769516287322d92dcf1d028a3))
* **sichtwechsel:** refresh 71 screenshots against current v3.7 state ([#496](https://github.com/moag1000/Little-ISMS-Helper/issues/496)) ([54e3c44](https://github.com/moag1000/Little-ISMS-Helper/commit/54e3c44b15f0ed86626ddee135c3dd1fd74217d7))
* **spec:** role-scope architecture + persona-role plan (7 phases, 11 PRs) ([#448](https://github.com/moag1000/Little-ISMS-Helper/issues/448)) ([651ee08](https://github.com/moag1000/Little-ISMS-Helper/commit/651ee0883a9c6a3c0ef0da94c162f4481ed7c7d5))

## [3.7.0](https://github.com/moag1000/Little-ISMS-Helper/compare/v3.6.0...v3.7.0) (2026-05-18)


### Added

* **admin-settings:** implement 6 deferred settings pages (Notifications/API/Backups/FiscalYear/DataRetention/WorkflowSLAs) ([#350](https://github.com/moag1000/Little-ISMS-Helper/issues/350)) ([f7c5b87](https://github.com/moag1000/Little-ISMS-Helper/commit/f7c5b876cba25291df215160624d6459314508c6))
* **admin-settings:** link missing setting pages from main settings index ([#349](https://github.com/moag1000/Little-ISMS-Helper/issues/349)) ([68dfea5](https://github.com/moag1000/Little-ISMS-Helper/commit/68dfea5044378ecc1ae08429a00ac8f181bb6f10))
* **admin:** LifecycleOverridesController + repository extension + form ([6b617d4](https://github.com/moag1000/Little-ISMS-Helper/commit/6b617d40d4faccaf4f6a2e8884c52f1712096b21))
* **admin:** templates + AdminHub entry + translations DE/EN ([4ac0f95](https://github.com/moag1000/Little-ISMS-Helper/commit/4ac0f95cd3913c85aa12484b15a963c52256349a))
* **audit-finding:** multi-control relatedControls (M:N) ([588dc9a](https://github.com/moag1000/Little-ISMS-Helper/commit/588dc9a82150f36839847c523171ce3655b18cca))
* **audit-log:** add tenant_id FK + drop brittle string-JOIN scoping ([6d8a208](https://github.com/moag1000/Little-ISMS-Helper/commit/6d8a208fccdc712bc6b96552b134f56fd346c8a9))
* **audit-log:** add tenant_id FK + drop brittle string-JOIN scoping ([8f4b1ad](https://github.com/moag1000/Little-ISMS-Helper/commit/8f4b1ad70c577b4fb4cfb5cd20e7afb5f83bfb2f))
* **audit-s5:** _fa_escalation_chain Aurora macro + Stimulus + BCPlan adoption (P-9) ([4fe7a76](https://github.com/moag1000/Little-ISMS-Helper/commit/4fe7a7616bdd2f17a150789b94d175ed8d937189))
* **audit-s5:** _fa_resource_list Aurora macro + Stimulus + BCPlan adoption (P-9) ([86626e6](https://github.com/moag1000/Little-ISMS-Helper/commit/86626e6f89184667269975af2d729e3763b521b3))
* **audit-s5:** _fa_subcontractor_chain Aurora macro + Stimulus + Supplier adoption (P-9) ([bd9a995](https://github.com/moag1000/Little-ISMS-Helper/commit/bd9a99582226dc82de52b805fb6d2a8fcdf04bc3))
* **audit-s5:** _fa_success_criteria Aurora macro + Stimulus + BCExercise adoption (P-9) ([dab407c](https://github.com/moag1000/Little-ISMS-Helper/commit/dab407c207bb5be990f31e0a1feb6de4aa61a740))
* **audit-s5:** _fa_team_role_builder Aurora macro + Stimulus + BCPlan adoption (P-9) ([b2aafb0](https://github.com/moag1000/Little-ISMS-Helper/commit/b2aafb0fc3e7aeff078402ca26284473495eb4ff))
* **aurora:** fa-matrix-table + fa-settings-table macros + skipped tables ([85791ad](https://github.com/moag1000/Little-ISMS-Helper/commit/85791ad3966c1ad6f2671d239fdc5acab0ac9e69))
* **aurora:** mass-sweep round 2 — 35 badges + 90 buttons migrated ([1df6e5c](https://github.com/moag1000/Little-ISMS-Helper/commit/1df6e5c24ae0d4fa3c2f9d9b8e4251365ab693f9))
* **aurora:** mass-sweep round 3 — 9 tables + 26 page-headers migrated ([7b043dc](https://github.com/moag1000/Little-ISMS-Helper/commit/7b043dc68ae6ce2c87b2577bc5b05dbf806548fd))
* **data-repair:** extend coverage — file orphans, cascade cleanup, JSON schema, audit-log integrity, status-enum drift ([#434](https://github.com/moag1000/Little-ISMS-Helper/issues/434)) ([810149b](https://github.com/moag1000/Little-ISMS-Helper/commit/810149b98ac3c8ee4ea6fdf5327e88a6ddcdbd00))
* **dora:** Phase-1 entity-flag scope for RoI + Phase-2 roadmap doc ([#372](https://github.com/moag1000/Little-ISMS-Helper/issues/372)) ([36443a9](https://github.com/moag1000/Little-ISMS-Helper/commit/36443a96041a4651bdf243904a49ea5f838352d9))
* **dora:** tenant-level doraEntityCategory + tool-wide UX threading ([#373](https://github.com/moag1000/Little-ISMS-Helper/issues/373)) ([4c74b00](https://github.com/moag1000/Little-ISMS-Helper/commit/4c74b006c458683b9feee88b4bdcc7127e4178c6))
* **exception:** introduce App\Exception hierarchy (Symfony BP item [#10](https://github.com/moag1000/Little-ISMS-Helper/issues/10)) ([8f47704](https://github.com/moag1000/Little-ISMS-Helper/commit/8f47704dd0c62ce3b76cec375566aafb26c8d2fc))
* **exception:** regulatory + status-transition follow-ups ([d2a85f5](https://github.com/moag1000/Little-ISMS-Helper/commit/d2a85f5215ddf8ee887a5022126429a15dd34f33))
* **exception:** replace tenant + status-transition throws with domain types ([1f04614](https://github.com/moag1000/Little-ISMS-Helper/commit/1f04614f41d3317181805c04dc4072c8d88efc65))
* **exception:** wire bulk-import throws to ImportFailedException ([f5869f2](https://github.com/moag1000/Little-ISMS-Helper/commit/f5869f24d9757b34030c2d9b778e66df95120ed8))
* **exception:** wire DPIA status guards to InvalidStatusTransitionException ([3ccb683](https://github.com/moag1000/Little-ISMS-Helper/commit/3ccb6831b4bb81b5090dc4c797a3035d7f57527c))
* **forms:** JsonTagsType chip-input + Gate 34 raw-JSON-textarea audit ([#422](https://github.com/moag1000/Little-ISMS-Helper/issues/422)) ([e822e34](https://github.com/moag1000/Little-ISMS-Helper/commit/e822e347863b1cfd6b1f29cca37fbb533ad2513d))
* **icons:** wire 39 new Aurora icons, 0 Gate 11 violations, drop Bootstrap-icons CSS ([#368](https://github.com/moag1000/Little-ISMS-Helper/issues/368)) ([5147ede](https://github.com/moag1000/Little-ISMS-Helper/commit/5147edecc96ff2d8b0def23c9e99d44e29cc07bf))
* implement /dashboards/dpo route referenced by E2E audit but returning 404 ([#354](https://github.com/moag1000/Little-ISMS-Helper/issues/354)) ([eca3323](https://github.com/moag1000/Little-ISMS-Helper/commit/eca33233e2d2a204a5e11842d40538d59ce27ffe))
* **lifecycle-ui:** _lifecycle_history_tab + GET history endpoint ([155d810](https://github.com/moag1000/Little-ISMS-Helper/commit/155d81008010adb5155f3caf198a42c4c8b155ef))
* **lifecycle-ui:** add lifecycle_can Twig function to LifecycleExtension ([34aeb6f](https://github.com/moag1000/Little-ISMS-Helper/commit/34aeb6fbd3e84883479f70e48817a7749717a920))
* **lifecycle-ui:** LifecycleChoiceType + Twig lifecycle_can helper ([868426b](https://github.com/moag1000/Little-ISMS-Helper/commit/868426be06a1699c332601791b9ccd90b6ad175f))
* **lifecycle:** add @Version column to Document for optimistic locking ([c21481d](https://github.com/moag1000/Little-ISMS-Helper/commit/c21481d2b89a972bed8888da6326c915ff3ebe70))
* **lifecycle:** apply state-machine to ISMSObjective ([ba64493](https://github.com/moag1000/Little-ISMS-Helper/commit/ba64493baa1b58c3f968f335e2a033021ac9c91e))
* **lifecycle:** apply state-machine to ProcessingActivity ([e55fdbe](https://github.com/moag1000/Little-ISMS-Helper/commit/e55fdbe37a13807c62e5e97f1b741a58a4949119))
* **lifecycle:** consolidated lock_version migration for 2 entities ([a439041](https://github.com/moag1000/Little-ISMS-Helper/commit/a439041afb4ffc52a0b20cdc99195d5f9abcf16c))
* **lifecycle:** document state-machine config (YAML) ([dc34199](https://github.com/moag1000/Little-ISMS-Helper/commit/dc3419908ea29153bd81e708ef7b9e3d9205197a))
* **lifecycle:** Foundation Pilot — Symfony Workflow for Document ([#398](https://github.com/moag1000/Little-ISMS-Helper/issues/398)) ([5c45825](https://github.com/moag1000/Little-ISMS-Helper/commit/5c45825c08c6ebaa4a3ba0af5fbc4375d9be7f0f))
* **lifecycle:** LifecycleController + bulk + allowed-transitions endpoints ([713750a](https://github.com/moag1000/Little-ISMS-Helper/commit/713750ae926382ca6a6fb0a9ef286981717aa55a))
* **lifecycle:** register 2 new entity-slugs in EntityTypeRegistry ([8c69158](https://github.com/moag1000/Little-ISMS-Helper/commit/8c69158758918a7c9f261dfab1ea4b3492b4cf6f))
* **lifecycle:** unblock Asset — custom physical-lifecycle state-machine (7 places, 9 transitions) ([#409](https://github.com/moag1000/Little-ISMS-Helper/issues/409)) ([438d546](https://github.com/moag1000/Little-ISMS-Helper/commit/438d546aa8b4a2537dbc6b4ca6a547326b223077))
* **lifecycle:** X.2 — apply state-machine to 10 custom-stage entities ([#414](https://github.com/moag1000/Little-ISMS-Helper/issues/414)) ([18696c4](https://github.com/moag1000/Little-ISMS-Helper/commit/18696c4697c981b30c377675deb7cfb9a2ef6487))
* **lifecycle:** X.3 — UI layer (transition dropdown / status pill / history tab) ([#405](https://github.com/moag1000/Little-ISMS-Helper/issues/405)) ([f9cbe3b](https://github.com/moag1000/Little-ISMS-Helper/commit/f9cbe3bb08b474365d55a6bbeca5213d21ac9b2d))
* **lifecycle:** X.3b — sweep lifecycle UI macros into 11 entity show-pages ([#415](https://github.com/moag1000/Little-ISMS-Helper/issues/415)) ([168e04e](https://github.com/moag1000/Little-ISMS-Helper/commit/168e04eeef9ee2283b440a179da5ca7af8863463))
* **lifecycle:** X.4 minimal automation — field-completion + AlvaHint-stuck + workflow-bridge ([#413](https://github.com/moag1000/Little-ISMS-Helper/issues/413)) ([f823ae7](https://github.com/moag1000/Little-ISMS-Helper/commit/f823ae78576b9dc79d689c6f85a6d67fdfdc50ec))
* **lifecycle:** X.6 — migrate 10 deferred setStatus calls to LifecycleService ([#440](https://github.com/moag1000/Little-ISMS-Helper/issues/440)) ([930c0c7](https://github.com/moag1000/Little-ISMS-Helper/commit/930c0c7f58a8660898ce7cc0413b46a2488f797d))
* **lifecycle:** Y.0 — WorkflowInstance.status as Symfony state-machine ([#416](https://github.com/moag1000/Little-ISMS-Helper/issues/416)) ([8349a0f](https://github.com/moag1000/Little-ISMS-Helper/commit/8349a0f81be9e3a60554ac7e95cce254de600db9))
* **lifecycle:** Y.1 — merge WAPS into FieldCompletionAutoTransition (deprecate WAPS) ([#417](https://github.com/moag1000/Little-ISMS-Helper/issues/417)) ([2c36566](https://github.com/moag1000/Little-ISMS-Helper/commit/2c36566b3c3df23ebb2dfab9939e6a6e5066e338))
* **lifecycle:** Y.2 — regulatory workflows YAML migration ([#424](https://github.com/moag1000/Little-ISMS-Helper/issues/424)) ([95d7ccb](https://github.com/moag1000/Little-ISMS-Helper/commit/95d7ccbe72480dcba8850fff4f0f2b4def1d9899))
* **lifecycle:** Y.3 — admin workflow-overlay editor UI ([#425](https://github.com/moag1000/Little-ISMS-Helper/issues/425)) ([ac4f4b0](https://github.com/moag1000/Little-ISMS-Helper/commit/ac4f4b0577abf3375bcba5bd60a9ed1b9c75b8d1))
* **lifecycle:** Y.4 — Workflow entity deprecation + data preservation ([#432](https://github.com/moag1000/Little-ISMS-Helper/issues/432)) ([e4ac453](https://github.com/moag1000/Little-ISMS-Helper/commit/e4ac4531d5ea28e37b787d8e8fb64284a5c66aa8))
* **monitoring:** multi-dimensional filtering for errors page ([#444](https://github.com/moag1000/Little-ISMS-Helper/issues/444)) ([5af2dbb](https://github.com/moag1000/Little-ISMS-Helper/commit/5af2dbb59afc096f9326a33711c997ed590f795c))
* **p-12:** roll out _fa_norm_bridge + _fa_status_chip into 5 templates ([7e4e256](https://github.com/moag1000/Little-ISMS-Helper/commit/7e4e2564410509aa1b2a3e2421a4e0ff1cb3fe5a))
* **quality:** X.5b — PHPStan no-direct-setStatus rule + cleanup 23 FIXMEs ([#419](https://github.com/moag1000/Little-ISMS-Helper/issues/419)) ([bf816be](https://github.com/moag1000/Little-ISMS-Helper/commit/bf816be26f1e08f16169d70ccf6f63bc9bea217e))
* **quick-fix:** FK-aware reconcile + schema-update-force fallback button ([#393](https://github.com/moag1000/Little-ISMS-Helper/issues/393)) ([cd3d8b6](https://github.com/moag1000/Little-ISMS-Helper/commit/cd3d8b6f6450e3ed6a9b9a37229ba591697e19fb))
* **quick-fix:** mark-all phantom-diff recovery (no more N clicks) ([#387](https://github.com/moag1000/Little-ISMS-Helper/issues/387)) ([be9324c](https://github.com/moag1000/Little-ISMS-Helper/commit/be9324c8a762e76ffa0f6123f3e0140fde377d0d))
* **quick-fix:** self-serve recovery for phantom_diff migration errors ([#383](https://github.com/moag1000/Little-ISMS-Helper/issues/383)) ([893665a](https://github.com/moag1000/Little-ISMS-Helper/commit/893665afbe2428cb37fe1e376c423e8c00d7b053))
* **quick-fix:** universal recovery card for ANY apply failure (not just phantom-diff) ([#397](https://github.com/moag1000/Little-ISMS-Helper/issues/397)) ([5da184a](https://github.com/moag1000/Little-ISMS-Helper/commit/5da184a2164b9e20bbd5fb4506361a3ae825abe5))
* **rbac:** enrich role-creation permission table with module + framework context ([#341](https://github.com/moag1000/Little-ISMS-Helper/issues/341)) ([7b1afc7](https://github.com/moag1000/Little-ISMS-Helper/commit/7b1afc7e50cae50fd2100ece8064bd029a774c7e))
* **rbac:** seed 6 persona + holding system-roles ([#336](https://github.com/moag1000/Little-ISMS-Helper/issues/336)) ([e42a5db](https://github.com/moag1000/Little-ISMS-Helper/commit/e42a5db3f4cc4befc38e9207441c2be8faa4cec5))
* **risk:** P0-02 — risk subject required (asset/person/location/supplier) ([65303b8](https://github.com/moag1000/Little-ISMS-Helper/commit/65303b8fa90e61e3836878fee16696a894c85841))
* **screenshot-user:** add ROLE_DPO to seed roles ([c54888a](https://github.com/moag1000/Little-ISMS-Helper/commit/c54888a1c35def0d1f754a6ed6925778fa27fcae))
* **soa:** P0-01 — justification required when control is not applicable ([32728ba](https://github.com/moag1000/Little-ISMS-Helper/commit/32728bacf1e3fde9dd6f60da7bc0338797e418af))
* Sprint 10A — F5b Wave 1 BSI/TISAX Library + Roundtrip ([#381](https://github.com/moag1000/Little-ISMS-Helper/issues/381)) ([bf9a251](https://github.com/moag1000/Little-ISMS-Helper/commit/bf9a2511d68dbec958c853b106a03007f20ff805))
* Sprint 10B — F27 BSI 200-4 Übungs-Logbuch ([#380](https://github.com/moag1000/Little-ISMS-Helper/issues/380)) ([93cb263](https://github.com/moag1000/Little-ISMS-Helper/commit/93cb26352c079405b98f98d5bafa46df06a86528))
* Sprint 3 — EU-Behörden-Templates + Filter-Export + NC-Linking ([#331](https://github.com/moag1000/Little-ISMS-Helper/issues/331)) ([bec8740](https://github.com/moag1000/Little-ISMS-Helper/commit/bec8740aa13bb09ece69e97aa8d734db99288397))
* Sprint 4 — F1 SSO Wave 1 (3-Step-Wizard + JIT-Provisioning) ([#333](https://github.com/moag1000/Little-ISMS-Helper/issues/333)) ([91b1f30](https://github.com/moag1000/Little-ISMS-Helper/commit/91b1f3059e9db9aab28e2b689bed7ba63f5296ec))
* Sprint 5A — F4 Evidence-Versioning + Cross-Framework-Cascade ([#334](https://github.com/moag1000/Little-ISMS-Helper/issues/334)) ([f8ee6df](https://github.com/moag1000/Little-ISMS-Helper/commit/f8ee6df30032d642facc44b4aa51cc1079390328))
* Sprint 5B — F1 SSO Wave 2 (RoleMapping + 6 HMAC-Audit-Events + Voters) ([#335](https://github.com/moag1000/Little-ISMS-Helper/issues/335)) ([3f10f36](https://github.com/moag1000/Little-ISMS-Helper/commit/3f10f36e80182332ec012773ea8c1c1e6470e7cf))
* Sprint 6a — F3 Notifications backend (entities + services + migration) ([#342](https://github.com/moag1000/Little-ISMS-Helper/issues/342)) ([9895348](https://github.com/moag1000/Little-ISMS-Helper/commit/989534821ca8c71e70203bc1f3c2e827d1adfcc7))
* Sprint 6b — F3 Notifications UI (rules + channels + templates + bell + center) ([#351](https://github.com/moag1000/Little-ISMS-Helper/issues/351)) ([946a5a9](https://github.com/moag1000/Little-ISMS-Helper/commit/946a5a94d106c6bc4ffb50af69b7b35b0083d4ac))
* Sprint 7A — F3 Notifications Wave 2 (SLA Deadline Monitor + Watcher) ([#355](https://github.com/moag1000/Little-ISMS-Helper/issues/355)) ([a5d95e5](https://github.com/moag1000/Little-ISMS-Helper/commit/a5d95e5b854d12c971704a5bba4c1c8bb8bddc2c))
* Sprint 7B — F29 NIS-2-BSI-Portal-Datensatz (yearly re-registration) ([#356](https://github.com/moag1000/Little-ISMS-Helper/issues/356)) ([c34bcd1](https://github.com/moag1000/Little-ISMS-Helper/commit/c34bcd1b8bd727f9f7ab445c63ff718548141353))
* Sprint 8 — F36 EU-Authority-Hub + F30 DORA-RoI-XBRL-Export ([#358](https://github.com/moag1000/Little-ISMS-Helper/issues/358)) ([b2bbfdb](https://github.com/moag1000/Little-ISMS-Helper/commit/b2bbfdbe4a9cc2da4df5809a8b1caad7f193f0da))
* Sprint 9A — F11 FTE-Tracking-Dashboard ([#365](https://github.com/moag1000/Little-ISMS-Helper/issues/365)) ([c9d3f24](https://github.com/moag1000/Little-ISMS-Helper/commit/c9d3f24886c0c3cb54f5e489587172d597da809c))
* Sprint 9B — F16 Risk-Incident-Link (cross-link risks ↔ incidents) ([#364](https://github.com/moag1000/Little-ISMS-Helper/issues/364)) ([99731c1](https://github.com/moag1000/Little-ISMS-Helper/commit/99731c1c8fd4330d442dd8664bbad77709b84583))
* **tenant-admin:** orphan-user banner with CTA ([59fe7c6](https://github.com/moag1000/Little-ISMS-Helper/commit/59fe7c6676125c03a394347aa995be83c60681fb))
* **tests:** install DAMA DoctrineTestBundle (extension disabled by default) ([#403](https://github.com/moag1000/Little-ISMS-Helper/issues/403)) ([70b4687](https://github.com/moag1000/Little-ISMS-Helper/commit/70b4687d88a37c83aaca8ba9521e8a864250e87e))
* **transparency:** Z.0 — workflow transparency for personas ([#436](https://github.com/moag1000/Little-ISMS-Helper/issues/436)) ([74049fb](https://github.com/moag1000/Little-ISMS-Helper/commit/74049fb15bd56dbfcfde304e0b3b181332385442))
* **wizard:** AnnexAApplicabilityApplier service propagates map to Control.applicable ([e648593](https://github.com/moag1000/Little-ISMS-Helper/commit/e64859313c0abee3b034a7d3846298a1a91e75af))
* **wizard:** hook AnnexAApplicabilityApplier into RiskClassificationStep validate-success ([9a6b55c](https://github.com/moag1000/Little-ISMS-Helper/commit/9a6b55c20ac1e90ccb10ef565c7b5634c66cddd1))
* **wizard:** multi-select industry presets with later-wins merge ([#377](https://github.com/moag1000/Little-ISMS-Helper/issues/377)) ([05a2ab8](https://github.com/moag1000/Little-ISMS-Helper/commit/05a2ab859d5b6dc9f82f8a32c652b8c617b2d7ef))
* **workflow:** seamless new→builder flow + clearer step-management entry-points ([#346](https://github.com/moag1000/Little-ISMS-Helper/issues/346)) ([46dcf65](https://github.com/moag1000/Little-ISMS-Helper/commit/46dcf658cc36438d4d5cafc731b9938ad8de34e3))


### Fixed

* 4 BLOCKER 500-errors + embed-scope macro sweep from E2E audit ([#352](https://github.com/moag1000/Little-ISMS-Helper/issues/352)) ([009b4ba](https://github.com/moag1000/Little-ISMS-Helper/commit/009b4ba4acb063ba620266f1b33b201ff9b1ba5b))
* **admin-hub:** migrate icons to Aurora (Bootstrap-icons removed in PR [#368](https://github.com/moag1000/Little-ISMS-Helper/issues/368)) ([#374](https://github.com/moag1000/Little-ISMS-Helper/issues/374)) ([b43ac56](https://github.com/moag1000/Little-ISMS-Helper/commit/b43ac564aac23b25b41ee7fb1c8a647f7a83e17f))
* **admin-hub:** wire 3 missing pages + dynamic mega-menu count ([#433](https://github.com/moag1000/Little-ISMS-Helper/issues/433)) ([348964f](https://github.com/moag1000/Little-ISMS-Helper/commit/348964f1cf4a732c82326c923eb2af749e758bb4))
* **admin,soa:** EntityManagerClosed + no-tenant-assigned UX ([f8e7194](https://github.com/moag1000/Little-ISMS-Helper/commit/f8e71948a064204696dad729fc1ceda1d2426085))
* **admin:** header overlaps in stepper-based admin pages ([#339](https://github.com/moag1000/Little-ISMS-Helper/issues/339)) ([c5ca37b](https://github.com/moag1000/Little-ISMS-Helper/commit/c5ca37b853fbad22f641436b463ef306f757a73d))
* **admin:** user-form prefill + role-compare entry-points ([#337](https://github.com/moag1000/Little-ISMS-Helper/issues/337)) ([8297976](https://github.com/moag1000/Little-ISMS-Helper/commit/8297976beec9755b54d1e4f93d3b2cc0e977acee))
* **alva-hint:** MEDIUM-1 — move/add 10 translation keys to global: section ([f32d883](https://github.com/moag1000/Little-ISMS-Helper/commit/f32d883a3ef47ea98950fcafa6ec89943670683e))
* **audit-s5:** BCPlan controller tests submit required RTO/RPO fields ([005d761](https://github.com/moag1000/Little-ISMS-Helper/commit/005d76122141c28aa563b6b89efefb82db9c9849))
* **audit-s5:** re-import macros inside embed-blocks (CLAUDE.md Pitfall [#10](https://github.com/moag1000/Little-ISMS-Helper/issues/10)) ([cc3406c](https://github.com/moag1000/Little-ISMS-Helper/commit/cc3406c9dfd38f54fbac66c671ab4983c50c8cf2))
* **audit-s5:** re-import pill macro inside audit-workbook embed-block (CLAUDE.md Pitfall [#10](https://github.com/moag1000/Little-ISMS-Helper/issues/10)) ([0648935](https://github.com/moag1000/Little-ISMS-Helper/commit/0648935ef805e954c2897b294546f369f0e8c289))
* **aurora:** -subtle variant cleanup + chore(audit-log): tenant_id FK ([7482312](https://github.com/moag1000/Little-ISMS-Helper/commit/7482312d772c1e9bf7d00ac03d5d8fc2bacaab5b))
* **aurora:** bump dashboard count-pills to size:lg for visibility ([529f33b](https://github.com/moag1000/Little-ISMS-Helper/commit/529f33b156ddf6c0473ae0572d70d320fff180af))
* **aurora:** drop fs-* / size-override misuse on .fa-status-pill + .fa-alert ([f0c180c](https://github.com/moag1000/Little-ISMS-Helper/commit/f0c180c22a05af1153dbb16da104ec76db4a9d61))
* **aurora:** drop fs-* / size-override misuse on .fa-status-pill + .fa-alert ([54b45c6](https://github.com/moag1000/Little-ISMS-Helper/commit/54b45c6d52637a95625ee66abe6a49bd804ccfe6))
* **aurora:** drop fs-* size-overrides via _badge include class: param ([e0f5e70](https://github.com/moag1000/Little-ISMS-Helper/commit/e0f5e70c23eadd580bf5938c0302ea722ac21239))
* **aurora:** drop fs-*/size-override misuse on .fa-status-pill + .fa-alert ([2f2f142](https://github.com/moag1000/Little-ISMS-Helper/commit/2f2f14259a7aff63ae8966576fce86d9f4b288f5))
* **aurora:** pitfall-[#11](https://github.com/moag1000/Little-ISMS-Helper/issues/11) info-card bg-* override + admin-panel token cleanup ([3a99a40](https://github.com/moag1000/Little-ISMS-Helper/commit/3a99a4074a43578ec17cd756c57ca636bc289470))
* **aurora:** resolve CSS-vars at runtime for Chart.js backgroundColors ([1a7f560](https://github.com/moag1000/Little-ISMS-Helper/commit/1a7f56068c465842156a470b35f6eaff344318ba))
* **aurora:** resolve CSS-vars at runtime for Chart.js backgroundColors ([df47e2a](https://github.com/moag1000/Little-ISMS-Helper/commit/df47e2a2746bd7457879e3e7fea2c7314178a48b))
* **authority:** handle null NIS-2 contact in template ([528210b](https://github.com/moag1000/Little-ISMS-Helper/commit/528210b48e3194fc3a8d0e870a13096604ee3e86))
* **authority:** handle null NIS-2 contact in template (is not null instead of is defined) ([20cd209](https://github.com/moag1000/Little-ISMS-Helper/commit/20cd20933d8fce68843e1497f4aaceb05e58a246))
* **authority:** make NIS-2 registration contact columns nullable + validate at form-submit ([4619b2d](https://github.com/moag1000/Little-ISMS-Helper/commit/4619b2d997e8b23bd9102e8ccbce373d322bae1f))
* **autoload:** exclude .claude/worktrees/ from composer classmap ([815fef9](https://github.com/moag1000/Little-ISMS-Helper/commit/815fef9dd74e56844f68fef67c11d5e87a8fc9b4))
* **autoload:** exclude worktrees from composer classmap ([ed8152a](https://github.com/moag1000/Little-ISMS-Helper/commit/ed8152ab6b3aa5da09583e0fd1af061fc6897916))
* **badge,pills:** Aurora-render in _badge + sync .fa-status-pill padding to spec ([f1d1b99](https://github.com/moag1000/Little-ISMS-Helper/commit/f1d1b996ac7a42fece271969f0b6cde3d8623d07))
* **bcm:** replace nested &lt;form&gt; with &lt;div&gt; in _fa_resource_list ([5826efe](https://github.com/moag1000/Little-ISMS-Helper/commit/5826efe4724484a53081a2fac64151b6e90d9069))
* **ci:** refresh flash_domain baseline + align SoA-no-tenant test ([a52c188](https://github.com/moag1000/Little-ISMS-Helper/commit/a52c188f581a83fc204b01a070f31594e3727814))
* **ci:** refresh flash_domain baseline after #[CurrentUser] refactor ([5d23388](https://github.com/moag1000/Little-ISMS-Helper/commit/5d23388ea0c558973166eafbc792e8aa0a936eb0))
* **compliance-loader:** invocable load-commands break framework UI ([#340](https://github.com/moag1000/Little-ISMS-Helper/issues/340)) ([f76add9](https://github.com/moag1000/Little-ISMS-Helper/commit/f76add90c31a1ae8fa2fe426c800d467e8e43d76))
* **compliance:** re-import _fa_progress macro inside similarity-scores embed ([64e79cd](https://github.com/moag1000/Little-ISMS-Helper/commit/64e79cd27419f9f3860be7aeda99088ed1954747))
* **compliance:** re-import _fa_progress macro inside similarity-scores embed ([8602a84](https://github.com/moag1000/Little-ISMS-Helper/commit/8602a84dfda7610d8b66c497a538bc44c5413f74))
* **control:** accept ISO 27001/27701/27017/27018 control-ID formats ([7d832b3](https://github.com/moag1000/Little-ISMS-Helper/commit/7d832b3327f392533c0bfe7a03b1523cc3ea3716))
* **control:** accept ISO 27001/27701/27017/27018 control-ID formats + YAML validator fix ([54aa2e4](https://github.com/moag1000/Little-ISMS-Helper/commit/54aa2e488c55fdbd7c8a0655aef79e652a022ae9))
* **css:** restore .badge padding + font-size after pill regression ([ecef22f](https://github.com/moag1000/Little-ISMS-Helper/commit/ecef22f3fdb798d66aae1a30fc60b8e7b80f0a19))
* **css:** set --alva-z to 9500 — Alva must outrank Modal/Toast/Command ([df3dee2](https://github.com/moag1000/Little-ISMS-Helper/commit/df3dee297e5e5d984100816f7860f709de05c823))
* **dashboard:** prevent urgent-alert from overlapping page-header ([#338](https://github.com/moag1000/Little-ISMS-Helper/issues/338)) ([801c521](https://github.com/moag1000/Little-ISMS-Helper/commit/801c521720a58e46faf66ee0a81262684c0a240f))
* **data-breach:** use DateTimeImmutable for notifiedAt fields ([5d62346](https://github.com/moag1000/Little-ISMS-Helper/commit/5d62346b50b2daa54b006a07a4d4f75e2235c646))
* **data-breach:** use DateTimeImmutable for supervisoryAuthority + dataSubjects notifiedAt ([ef65d77](https://github.com/moag1000/Little-ISMS-Helper/commit/ef65d77dba1bcd03c9978e1d832b66f513d13229))
* **deps:** restore symfony/workflow in composer.json require section ([e619e52](https://github.com/moag1000/Little-ISMS-Helper/commit/e619e52ab434883f97f8efb5abadbe5c74807486))
* **dora:** replace unsupported YEAR() DQL with date-range comparison ([2171982](https://github.com/moag1000/Little-ISMS-Helper/commit/21719828d4b0e80649b5fa62cf1a6cb33d3d427c))
* **dora:** YEAR DQL → date-range ([46f3c3b](https://github.com/moag1000/Little-ISMS-Helper/commit/46f3c3be33bdb9ea86ff467a3700f11d3d111e35))
* E2E round-2 BLOCKERS — 5 missing TranslatorInterface + NIS-2 auto-init + notification-center route ([#362](https://github.com/moag1000/Little-ISMS-Helper/issues/362)) ([1968877](https://github.com/moag1000/Little-ISMS-Helper/commit/196887783ef120c0defdc3a378f55eb70156682b))
* E2E runtime-audit BLOCKERS + HIGH + icons — 3 atomic commits ([#382](https://github.com/moag1000/Little-ISMS-Helper/issues/382)) ([2cbe091](https://github.com/moag1000/Little-ISMS-Helper/commit/2cbe0913f88f16935e8bdf407d0fa4ed905f4c3a))
* **entities:** add explicit type/name to @Version columns ([37a3ad4](https://github.com/moag1000/Little-ISMS-Helper/commit/37a3ad45e1f04e1272ce4f44c80b910aa4cf558b))
* **evidence:** add explicit preview-button (eye icon) next to download ([5991997](https://github.com/moag1000/Little-ISMS-Helper/commit/5991997e780ebe8763f2b5f764c155d6ab8ba55e))
* **i18n:** add 4 missing DE labels for risk.modern.category.* ([e85e82c](https://github.com/moag1000/Little-ISMS-Helper/commit/e85e82c62e61784ac2eb86de0ee3eb371ebfec33))
* **i18n:** add trans_default_domain to lifecycle_overrides embed blocks ([f665043](https://github.com/moag1000/Little-ISMS-Helper/commit/f6650432d342705b089d9c7cead1fe5f71e2fe55))
* **i18n:** admin.policy_style + sibling blocks mis-nested in admin.{de,en}.yaml ([#375](https://github.com/moag1000/Little-ISMS-Helper/issues/375)) ([5ea0074](https://github.com/moag1000/Little-ISMS-Helper/commit/5ea00743fea6a0ed839858196a403accc705afec))
* **i18n:** missing translations under Datenschutz + EU-Behörden mega-menu ([#370](https://github.com/moag1000/Little-ISMS-Helper/issues/370)) ([671e0ee](https://github.com/moag1000/Little-ISMS-Helper/commit/671e0eeef13b2f41cf4fac3a40ff11124ee491de))
* **icons:** map bare Bootstrap-Icon names to Aurora namespaced classes ([a9645c6](https://github.com/moag1000/Little-ISMS-Helper/commit/a9645c645ddb83b14a547f33a278e28eaf5bbd40))
* **icons:** map ImplementationJourneyService phase icons to Aurora classes ([97ff06d](https://github.com/moag1000/Little-ISMS-Helper/commit/97ff06d73e132f44184d45e733a6f7fe433e694d))
* **icons:** portfolio-report cell icons use Aurora status-* names ([d3f04d7](https://github.com/moag1000/Little-ISMS-Helper/commit/d3f04d7f6e917cfb3f912ab36b840c217ba59276))
* **icons:** rename bi-* PHP icon literals to Aurora canonical names (round 2, controllers) ([c31adb1](https://github.com/moag1000/Little-ISMS-Helper/commit/c31adb14ee5c3e103014bb30b88026b56881deec))
* **icons:** rename bi-* PHP icon literals to Aurora canonical names (round 2, services) ([523a990](https://github.com/moag1000/Little-ISMS-Helper/commit/523a990f11058d564a6339cbd47192c36f384de8))
* **icons:** round 3 — remaining bi-* literals in templates + bare bootstrap names ([38de1fb](https://github.com/moag1000/Little-ISMS-Helper/commit/38de1fb553d4e69afad164b38677eb133a9590c8))
* **icons:** round 4 — convert inline &lt;i class="bi …"&gt; tags to Aurora fa-icon ([126244c](https://github.com/moag1000/Little-ISMS-Helper/commit/126244c257ca066e51b9abe89f5bcf6369b7379b))
* **lifecycle:** add route requirements on workflow/transition wildcards (Gate 10) ([4176437](https://github.com/moag1000/Little-ISMS-Helper/commit/4176437c96ad13494b4fdbdfbeabfb3796c99190))
* **lifecycle:** critical post-deploy fixes (login, lock_version tables, translations, anon-access) ([#407](https://github.com/moag1000/Little-ISMS-Helper/issues/407)) ([df6f366](https://github.com/moag1000/Little-ISMS-Helper/commit/df6f366ef397f3629110e14e9def44dace6b1ef1))
* **lifecycle:** defensive guards in lock_version migration ([c53cb1e](https://github.com/moag1000/Little-ISMS-Helper/commit/c53cb1e3076e0a8d0c4e340ba2ad7d473690836c))
* **lifecycle:** translations for all X.1+X.2 statuses + transitions ([#418](https://github.com/moag1000/Little-ISMS-Helper/issues/418)) ([c5c962f](https://github.com/moag1000/Little-ISMS-Helper/commit/c5c962f10ec9e74dbfbdce2beda2415e6051af99))
* **megamenu:** MRIS-report prefetch downloads + evidence-reverification trailing slash ([#366](https://github.com/moag1000/Little-ISMS-Helper/issues/366)) ([22f72ec](https://github.com/moag1000/Little-ISMS-Helper/commit/22f72ec989b3ed3e84cdcebf1a425aa62eb38147))
* **migrations:** add isTransactional()=false to 47 DDL migrations (CLAUDE.md Pitfall [#6](https://github.com/moag1000/Little-ISMS-Helper/issues/6)) ([6993dfe](https://github.com/moag1000/Little-ISMS-Helper/commit/6993dfea7ce6541cf4fa2af505c7684fe0f7d0eb))
* **migrations:** consolidate 74-statement entity-vs-DB drift ([#400](https://github.com/moag1000/Little-ISMS-Helper/issues/400)) ([0f1a58c](https://github.com/moag1000/Little-ISMS-Helper/commit/0f1a58cfd0b15ece4889def9878f5f210d8a61db))
* **migrations:** restore lifecycle_config CREATE + drop DROP-clause in drift-consolidation ([005ee4b](https://github.com/moag1000/Little-ISMS-Helper/commit/005ee4b439341534f01c5db5b7e6342e86ccd2ae))
* **migrations:** restore lifecycle_config CREATE + drop DROP-clause in drift-consolidation ([#402](https://github.com/moag1000/Little-ISMS-Helper/issues/402)) ([c6dd833](https://github.com/moag1000/Little-ISMS-Helper/commit/c6dd8338c4118e441f293db54c41c12ff7d6aaba))
* **policy-doc:** force light-theme on preview wrapper for WYSIWYG print readability ([#378](https://github.com/moag1000/Little-ISMS-Helper/issues/378)) ([a08aa83](https://github.com/moag1000/Little-ISMS-Helper/commit/a08aa83e88cb90ac931804ca57fec2a44d3d7b09))
* **quality:** Gate 28 skip comments + lifecycleService receivers ([#420](https://github.com/moag1000/Little-ISMS-Helper/issues/420)) ([75e1303](https://github.com/moag1000/Little-ISMS-Helper/commit/75e1303da4b47fb49be4b58750db0c4e797b1c24))
* **quick-fix:** bulletproof index + auto-detect entity-vs-DB drift ([#396](https://github.com/moag1000/Little-ISMS-Helper/issues/396)) ([295a26b](https://github.com/moag1000/Little-ISMS-Helper/commit/295a26b084294312fa93fdaca4cad1c216f07ddf))
* **quick-fix:** catch phantom-diff schema state (marked executed, DDL never ran) ([#343](https://github.com/moag1000/Little-ISMS-Helper/issues/343)) ([3c59e77](https://github.com/moag1000/Little-ISMS-Helper/commit/3c59e773a477484fe2dd142544936a73d94fd93c))
* **quick-fix:** exempt global-catalogue entities from orphan repair (NotificationTemplate) ([#344](https://github.com/moag1000/Little-ISMS-Helper/issues/344)) ([2fc4631](https://github.com/moag1000/Little-ISMS-Helper/commit/2fc46318313c06af59808508492f151f32ff4610))
* **quick-fix:** FK-aware reconcile drops+readds blocking FK for ALTER COLUMN ([def2d06](https://github.com/moag1000/Little-ISMS-Helper/commit/def2d068f859bcd76adef71702e28db076af34f1))
* **quick-fix:** FK-aware reconcile drops+readds blocking FK for ALTER COLUMN ([#399](https://github.com/moag1000/Little-ISMS-Helper/issues/399)) ([6016846](https://github.com/moag1000/Little-ISMS-Helper/commit/6016846a51e4b485b2c9df6d9de58b77226b611a))
* **quick-fix:** mark-all per-version isolated loop (Approach C) ([#389](https://github.com/moag1000/Little-ISMS-Helper/issues/389)) ([896fc94](https://github.com/moag1000/Little-ISMS-Helper/commit/896fc946896541bdfc03c5d4ddf7e98d57c6f061))
* **quick-fix:** reset closed EM between mark-all-phantom-diff iterations ([#390](https://github.com/moag1000/Little-ISMS-Helper/issues/390)) ([63dd765](https://github.com/moag1000/Little-ISMS-Helper/commit/63dd765a5bdb6439e19a6073d5bab54e5ae97bbd))
* **quick-fix:** surface phantom-diff recovery card outside error-only branch ([#384](https://github.com/moag1000/Little-ISMS-Helper/issues/384)) ([a609a4a](https://github.com/moag1000/Little-ISMS-Helper/commit/a609a4ac9b5be1b08bae0e18f15d3bac316d397f))
* **report-style:** cast nullable opacity value before multiply ([1f27f05](https://github.com/moag1000/Little-ISMS-Helper/commit/1f27f05eefcb2a289fccb373f3bd4bca2a087b07))
* **report-style:** cast nullable opacity value before multiply in twig ([481e960](https://github.com/moag1000/Little-ISMS-Helper/commit/481e9601846c66da14397842042bfd93e309000c))
* **role-management:** suppress legacy auto-form rendering of permissions field ([#360](https://github.com/moag1000/Little-ISMS-Helper/issues/360)) ([a8bdf35](https://github.com/moag1000/Little-ISMS-Helper/commit/a8bdf3527d743876a7ee4d7bde322e0127798cff))
* **routes:** declare GET methods on 259 read-only routes (Symfony BP item [#3](https://github.com/moag1000/Little-ISMS-Helper/issues/3)) ([81703e1](https://github.com/moag1000/Little-ISMS-Helper/commit/81703e12cc0c2d23448753dea69688b6cee453f1))
* **routes:** declare GET+POST methods on 55 form-handle routes (Symfony BP item [#3](https://github.com/moag1000/Little-ISMS-Helper/issues/3)) ([8162f56](https://github.com/moag1000/Little-ISMS-Helper/commit/8162f56a8b2ae4fc95b11722b00af5bed89b1fd0))
* **routes:** declare POST methods on 2 state-changing routes (Symfony BP item [#3](https://github.com/moag1000/Little-ISMS-Helper/issues/3)) ([eb85778](https://github.com/moag1000/Little-ISMS-Helper/commit/eb85778197379dcd5d1bc00ffde093f6c5b97763))
* **soa:** dedicated no-tenant landing page with actionable CTAs ([ebde3e3](https://github.com/moag1000/Little-ISMS-Helper/commit/ebde3e375c9b3ea48c12b081205aa0e8941a9fe5))
* **soa:** nest no_tenant: keys under top-level soa: block ([b183c49](https://github.com/moag1000/Little-ISMS-Helper/commit/b183c49160f7daa40b075f16ac838b6950c757c8))
* **soa:** sticky header overlap + bulk-applicability quick action ([#376](https://github.com/moag1000/Little-ISMS-Helper/issues/376)) ([4b49c19](https://github.com/moag1000/Little-ISMS-Helper/commit/4b49c19e700e41c686b04cde7b3b5806150a4776))
* **soa:** use isApplicable() not getApplicable() on Control entity ([c0f79d9](https://github.com/moag1000/Little-ISMS-Helper/commit/c0f79d9e0997b1c829547b73bec22696f2e017ca))
* **tenant-context:** re-initialize when cached tenant=null but user logged in ([d11ed35](https://github.com/moag1000/Little-ISMS-Helper/commit/d11ed35f9522359d24cb6f587a4008e75bfdc3c9))
* **tests:** align expectExceptions to App\Exception\* domain types ([ffc7171](https://github.com/moag1000/Little-ISMS-Helper/commit/ffc71715d7e201664e90cfea46abc14aa0c6fe5c))
* **tests:** align form-crawler selectors + baseline freetext annotation ([d33ecfa](https://github.com/moag1000/Little-ISMS-Helper/commit/d33ecfada91b086b871e531667c8f4504e54c84a))
* **tests:** pass \$user to AdminDashboardController::index() after #[CurrentUser] ([7da4599](https://github.com/moag1000/Little-ISMS-Helper/commit/7da45997c8ce7e2d6413edd563260855d9ef7338))
* **tests:** purge double-transformer + enum-stringify after Phase-1 rollout ([#442](https://github.com/moag1000/Little-ISMS-Helper/issues/442)) ([db6102c](https://github.com/moag1000/Little-ISMS-Helper/commit/db6102c272d19e87b19f60b4ecda63820890d43e))
* **tests:** repair 3 upstream test failures after main's routes-refactor + i18n sweep ([#401](https://github.com/moag1000/Little-ISMS-Helper/issues/401)) ([38e621f](https://github.com/moag1000/Little-ISMS-Helper/commit/38e621fdf6626ee54307a88658cb43e3d5ce779d))
* **workflow:** allow ROLE_MANAGER (ISB) to create/edit workflow definitions and steps ([#345](https://github.com/moag1000/Little-ISMS-Helper/issues/345)) ([b00d193](https://github.com/moag1000/Little-ISMS-Helper/commit/b00d193159d368345cab288aa51eefb4ec21641d))


### Performance

* **ci:** replace shell-wrapped debug:translation with pure-Python YAML diff ([0000c66](https://github.com/moag1000/Little-ISMS-Helper/commit/0000c66ca61b0c9c07dc80c86bd5a1b3cbda2197))


### Changed

* **AdminDashboardController:** adopt #[CurrentUser] in index action ([7be2bce](https://github.com/moag1000/Little-ISMS-Helper/commit/7be2bce6e6eac23323118d1003a9a334c0676354))
* **admin:** slim mega-menu + add back-links from sub-pages to Hub ([#347](https://github.com/moag1000/Little-ISMS-Helper/issues/347)) ([082fceb](https://github.com/moag1000/Little-ISMS-Helper/commit/082fcebfbed41da0c1434e320ea1889ce0402b41))
* **controllers:** adopt #[CurrentUser] in 2 actions (Symfony BP item [#8](https://github.com/moag1000/Little-ISMS-Helper/issues/8)) ([f246acb](https://github.com/moag1000/Little-ISMS-Helper/commit/f246acbac8b17a4f35ef93341787202727a41da2))
* **controllers:** adopt #[CurrentUser] in 3 actions (Symfony BP item [#8](https://github.com/moag1000/Little-ISMS-Helper/issues/8)) ([cb7983f](https://github.com/moag1000/Little-ISMS-Helper/commit/cb7983f36a104f3a23570b0a3cd169ff0152f4e3))
* **controllers:** adopt #[CurrentUser] in 3 actions (Symfony BP item [#8](https://github.com/moag1000/Little-ISMS-Helper/issues/8)) ([bf8250d](https://github.com/moag1000/Little-ISMS-Helper/commit/bf8250d9132d4c818c293eb5e75d873905275aa3))
* **controllers:** adopt #[CurrentUser] in 7 actions (Symfony BP item [#8](https://github.com/moag1000/Little-ISMS-Helper/issues/8)) ([c764a8f](https://github.com/moag1000/Little-ISMS-Helper/commit/c764a8fd6aa7b7673b2a15100d4c03495583663f))
* **controllers:** adopt #[CurrentUser] in 9 actions (Symfony BP item [#8](https://github.com/moag1000/Little-ISMS-Helper/issues/8)) ([baa0747](https://github.com/moag1000/Little-ISMS-Helper/commit/baa0747ca835ffd345f21007ba446b6910271a83))
* **controllers:** adopt #[IsCsrfTokenValid] in 3 actions (Symfony 7.1+ BP) ([33ab841](https://github.com/moag1000/Little-ISMS-Helper/commit/33ab841c158e955340ee89c4521ee4fc4309f012))
* **enum:** Document status — phase 1 foundation (Item 5 sweep) ([#423](https://github.com/moag1000/Little-ISMS-Helper/issues/423)) ([7a01d70](https://github.com/moag1000/Little-ISMS-Helper/commit/7a01d708684d5bc478572926fc1aad2cc75a4842))
* **enum:** Status-Enums batch A — privacy/policy (10 entities) ([#428](https://github.com/moag1000/Little-ISMS-Helper/issues/428)) ([54d8537](https://github.com/moag1000/Little-ISMS-Helper/commit/54d8537cd95f4facc4032852e13429cc8405a0d9))
* **enum:** Status-Enums batch B — ISMS-core + BCM (10 entities) ([#430](https://github.com/moag1000/Little-ISMS-Helper/issues/430)) ([51b3466](https://github.com/moag1000/Little-ISMS-Helper/commit/51b3466cb99b8f21583df3cbd045cfd35398b76b))
* **enum:** Status-Enums batch C — remediation + security + assets (10 entities) ([#427](https://github.com/moag1000/Little-ISMS-Helper/issues/427)) ([ca76cb7](https://github.com/moag1000/Little-ISMS-Helper/commit/ca76cb7e5f9828a95dc5eace87da2debc4f9dddf))
* **enum:** Status-Enums batch D — workflow + wizard + training (9 entities) ([#429](https://github.com/moag1000/Little-ISMS-Helper/issues/429)) ([844b862](https://github.com/moag1000/Little-ISMS-Helper/commit/844b8626fbbe9ec7b0246367377446f0c693f384))
* **forms:** mark 74 form types as final (Symfony BP item [#9](https://github.com/moag1000/Little-ISMS-Helper/issues/9)) ([26ade45](https://github.com/moag1000/Little-ISMS-Helper/commit/26ade45214d2c4ccbfd45a1aa13d45bb28661703))
* **listeners,twig,validator:** mark 41 classes as final (Symfony BP [#9](https://github.com/moag1000/Little-ISMS-Helper/issues/9)) ([5cec4e2](https://github.com/moag1000/Little-ISMS-Helper/commit/5cec4e267dba6449d56d649d9b100b711bfca82a))
* **routes:** migrate 11 controllers from Annotation\Route to Attribute\Route ([cbc590a](https://github.com/moag1000/Little-ISMS-Helper/commit/cbc590ae877646dd3b0532b90fd3615903678733))
* **services:** mark 92 services as final (Symfony BP item [#9](https://github.com/moag1000/Little-ISMS-Helper/issues/9)) ([220291f](https://github.com/moag1000/Little-ISMS-Helper/commit/220291f141d6efd07213581d4888f3ac5f915b0b))
* **voters:** mark 25 voters as final (Symfony BP item [#9](https://github.com/moag1000/Little-ISMS-Helper/issues/9)) ([c788b3d](https://github.com/moag1000/Little-ISMS-Helper/commit/c788b3d62e1e71ff434d9bc783f7b596496ff089))


### Reverted

* **i18n:** restore 175 translation files to pre-sweep state ([831c6ba](https://github.com/moag1000/Little-ISMS-Helper/commit/831c6ba9af2ffa33ec34690a8e6c52aa529333de))
* **i18n:** unstage 175 translation files erroneously included in 5cec4e26 ([e188be4](https://github.com/moag1000/Little-ISMS-Helper/commit/e188be41bbcfab4cb93ac1ddbdf8a27d2c290846))


### Documentation

* **design-system:** align token-table with current fairy-aurora.css ([5f5dbbb](https://github.com/moag1000/Little-ISMS-Helper/commit/5f5dbbb92109887cf29d0f31514bf58d6b8d4684))
* **lifecycle:** X.5 — ADR + user-guide + CLAUDE.md ([#411](https://github.com/moag1000/Little-ISMS-Helper/issues/411)) ([dadd3fc](https://github.com/moag1000/Little-ISMS-Helper/commit/dadd3fc70ce7fbbc51d8a938581da4017297c576))
* **plan:** add Sprint Y.2b — entity show-page lifecycle UI sweep ([3ec858f](https://github.com/moag1000/Little-ISMS-Helper/commit/3ec858f92f7f67081bd5fa8c988db7161f883941))
* **plan:** lifecycle foundation pilot — 16 parallelizable tasks ([8fdbf1a](https://github.com/moag1000/Little-ISMS-Helper/commit/8fdbf1ac3731fae5a8ee551a8584b164e84c4ca1))
* **plan:** workflow-system unification (Y.0-Y.4 migration) ([5567cd6](https://github.com/moag1000/Little-ISMS-Helper/commit/5567cd6af2f4969886dd54e8cd0f47411aae6b95))
* **spec:** lifecycle foundation pilot — Symfony Workflow adoption ([6a74395](https://github.com/moag1000/Little-ISMS-Helper/commit/6a743956b2fd809bfbe74f13ce2151c8634a33e9))

## [3.6.0](https://github.com/moag1000/Little-ISMS-Helper/compare/v3.5.2...v3.6.0) (2026-05-12)


### Added

* 3.6.0 — Sprint 2 — Bulk-Import for Risk + BusinessProcess + VVT-BfDI-Export + Alva-Hints ([cde7842](https://github.com/moag1000/Little-ISMS-Helper/commit/cde7842909cc0d4d0680404011daeb37510b6165))


### Fixed

* **alva-hint:** 6 action-routes mismatch with HTTP-method (405 fix) ([4957dc8](https://github.com/moag1000/Little-ISMS-Helper/commit/4957dc8e57fe7d5e92d0ed35b12a2b672f3f8d99))
* **alva-hint:** register missing Stimulus controller so hint buttons work ([b4bc844](https://github.com/moag1000/Little-ISMS-Helper/commit/b4bc844fb54f07ee44666f776454b64573b756da))
* **ui:** duplicate-header sweep — admin-templates + inline h1 fixes ([d72ca3d](https://github.com/moag1000/Little-ISMS-Helper/commit/d72ca3dec25d21d8376129dc6c9d81430736e0b8))

## [3.5.2](https://github.com/moag1000/Little-ISMS-Helper/compare/v3.5.1...v3.5.2) (2026-05-11)


### Fixed

* 3.5.2 — bulk-import wizard + audit-xlsx + aurora-sweep + quality-gates ([e9e1918](https://github.com/moag1000/Little-ISMS-Helper/commit/e9e19181787647a97aa9844b33da66c04faff137))
* **policy-wizard:** bestandsaufnahme — embed-block domain-propagation + Aurora pill replacement ([a2d40a5](https://github.com/moag1000/Little-ISMS-Helper/commit/a2d40a59c1400cb972b68abcb610cc810c17fcfb))
* **scheduled-report:** tenantContext method getTenantId() → getCurrentTenantId() ([8f309c2](https://github.com/moag1000/Little-ISMS-Helper/commit/8f309c2da53a1ed74643d1be9748f7e324bc40ba))

## [3.5.1](https://github.com/moag1000/Little-ISMS-Helper/compare/v3.5.0...v3.5.1) (2026-05-11)


### Documentation

* **plan:** roadmap v5 — DACH refocus + UX-specialist DS-integration patches ([85e0a81](https://github.com/moag1000/Little-ISMS-Helper/commit/85e0a81dd544fef07a0c176c40954c37f5f77edc))
* **plan:** roadmap v6 — sprint sequencing redesign for CM-value-maximization ([4fb143c](https://github.com/moag1000/Little-ISMS-Helper/commit/4fb143c8bfdb30eddcadf109508e2be7ce8c21e4))

## [3.5.0](https://github.com/moag1000/Little-ISMS-Helper/compare/v3.4.0...v3.5.0) (2026-05-11)


### Added

* **activity:** user activity-feed service + widget (V3 C6) ([e9454a4](https://github.com/moag1000/Little-ISMS-Helper/commit/e9454a4639c83221dfdef65e35bf4ac7b9e6904f))
* **admin-hub:** card-grid landing for /admin/hub (Sprint 1) ([ddabab5](https://github.com/moag1000/Little-ISMS-Helper/commit/ddabab5edbc2f186d2ba4a81ccf814de239358fb))
* **admin-hub:** cover 5 missing critical admin areas ([b24bb6e](https://github.com/moag1000/Little-ISMS-Helper/commit/b24bb6e2ac60a7dc28f3efa96b55f0340ae6054c))
* **admin-hub:** promote /admin/hub to primary entry-point ([d0ba022](https://github.com/moag1000/Little-ISMS-Helper/commit/d0ba022d911653c22d0d5db1e2b1ab646ca4c4ec))
* **admin:** integrate compliance settings into hub + tenant detail ([d37d1e0](https://github.com/moag1000/Little-ISMS-Helper/commit/d37d1e0a28e299bba7fa030a3a02df6f14d6e197))
* **admin:** per-tenant policy-doc style configurator + live preview ([7ec9808](https://github.com/moag1000/Little-ISMS-Helper/commit/7ec980805fd517e3f64f1b91b8deedbccf424b2d))
* **admin:** per-tenant report-doc style configurator (audience-aware) + live preview ([09e73a9](https://github.com/moag1000/Little-ISMS-Helper/commit/09e73a9fc9ba6f0e1501385306f121f9ad757adb))
* **alva-hint:** 10 new cross-module global rules (asset-ohne-risk, approved-doc-ohne-ack, open-incident-sla, risk-ohne-owner, overdue-audit-finding, missing-dpo, missing-bc-exercise, vuln-without-patch, missing-data-breach-proc, konzern-drift) ([be60a86](https://github.com/moag1000/Little-ISMS-Helper/commit/be60a86565c6d97a1e4a7fc1debdb39959e04f02))
* **alva-hint:** five tier-2 audit-gap-closer rules ([805dbc9](https://github.com/moag1000/Little-ISMS-Helper/commit/805dbc97fdcd197579759349c0af01c5336c6f74))
* **alva-hint:** five tier-3 strategic data-reuse rules ([d253113](https://github.com/moag1000/Little-ISMS-Helper/commit/d253113a39d998a8eda908ffe8b3f7d6e2b6b3bc))
* **alva-hint:** foundation for proactive in-app hint cards ([38c84a5](https://github.com/moag1000/Little-ISMS-Helper/commit/38c84a5ce36e689d24de3a2e4402c3a6ef68788f))
* **alva-hint:** four tier-1 regulatory hint rules ([397d1a7](https://github.com/moag1000/Little-ISMS-Helper/commit/397d1a772b0e4751cbd0ce1fcf7c3ba158e45270))
* **alva-hint:** global /alva-hints inbox page + nav link ([354ed22](https://github.com/moag1000/Little-ISMS-Helper/commit/354ed220b95fbe0e7d0b2d86ddc15a7d4f369666))
* **alva-hint:** render hint-slot on 30+ index pages, 5 dashboards, wizards + admin ([e419b4c](https://github.com/moag1000/Little-ISMS-Helper/commit/e419b4cea854dfabe498bff1d3b3b82723e2ffb8))
* **alva-hint:** render telemetry and dismiss-rate dashboard ([0323c28](https://github.com/moag1000/Little-ISMS-Helper/commit/0323c28fa3beae360bea5e5530a7ee09e2f39d30))
* **alva-hint:** telemetry, controller test, hint versioning ([a5d3a97](https://github.com/moag1000/Little-ISMS-Helper/commit/a5d3a9774a48b75f8e17f215f579f6eb900caf40))
* **alva-hint:** tenant-global hints fn + AlvaHintService cross-module extension ([4e6d258](https://github.com/moag1000/Little-ISMS-Helper/commit/4e6d2582df35aaa5826c19440dc0370f8da05f33))
* **alva-hint:** three cross-domain rules ([385a358](https://github.com/moag1000/Little-ISMS-Helper/commit/385a358a4560ce11739857bc79f359a1c2dc0d0c))
* **approval:** business-summary block + clarification button + deadline + step name humanisation ([db7b8eb](https://github.com/moag1000/Little-ISMS-Helper/commit/db7b8ebaf18f2f5254fe8563c18348876fe8dc20))
* **asset:** AI-Agent gating + EU AI Act Art. 5 prohibited-validation (T31.2.3) ([07c8358](https://github.com/moag1000/Little-ISMS-Helper/commit/07c83580cc0354d52a174d59be3c7c0300e4c66c))
* **asset:** processing-activity M2M relation (V3 W2-Bug3) ([aa1e35f](https://github.com/moag1000/Little-ISMS-Helper/commit/aa1e35f702c093e35f734af2bb79dbbb177a5f45))
* **audit-log:** compact-view tab using _fa_audit_row macro (Sprint 7) ([6dbb65b](https://github.com/moag1000/Little-ISMS-Helper/commit/6dbb65b69e9e1c81a3f590fa8449aa2135618867))
* **audit:** add AuditLogger::logBulk() hybrid pattern (Sprint 0 Task 0.3) ([bcd9be2](https://github.com/moag1000/Little-ISMS-Helper/commit/bcd9be2db9ef3be45ce2a487f82e5c1489757cd2))
* **audit:** add AuditLogger::logBulk() hybrid pattern (Sprint 0 Task 0.3) ([0bc2dec](https://github.com/moag1000/Little-ISMS-Helper/commit/0bc2dece8cd5bfc6e7a1624078a6abe8f81026f4))
* **audits:** Cross-Cutting Klassifikatoren — AuditFinding source + CorrectiveAction actionType + ChangeRequest clauseReference (T31.3.1) ([4c3a994](https://github.com/moag1000/Little-ISMS-Helper/commit/4c3a994ab2fefc9135153cbb85b4e84d797e51e5))
* **audit:** wire dead saveProgress button + dashboard widget-actions ([84ecc3d](https://github.com/moag1000/Little-ISMS-Helper/commit/84ecc3dafaa21fc05db3e3151f5185f41b69eeae))
* **aurora-components:** admin-panel macros + Stimulus controllers (Sprint 3) ([3d811d3](https://github.com/moag1000/Little-ISMS-Helper/commit/3d811d309cc3833f78fc5327bb5782667e4ae9c2))
* **aurora:** 4 new core UI components — toast, table, action-bar, bulk-action-bar (V3 W3-UX) ([51bdca0](https://github.com/moag1000/Little-ISMS-Helper/commit/51bdca0036942b4254f59ccb1c067dfbafa62cfd))
* **aurora:** add 3 v4 macros for upcoming wizard/diff/condition UIs (Sprint 0 Task 0.4) ([6360d4c](https://github.com/moag1000/Little-ISMS-Helper/commit/6360d4cc33202019f9d2423ce96c2ffba110063c))
* **aurora:** add 3 v4 macros for upcoming wizard/diff/condition UIs (Sprint 0 Task 0.4) ([5469fcb](https://github.com/moag1000/Little-ISMS-Helper/commit/5469fcb5824805ad6d7b966f419d74aea0a6b112))
* **automation:** V1-Backlog UF-3 audit-log verify + WS-2 acceptance-expiry cron (V3 W2-M8) ([c30243b](https://github.com/moag1000/Little-ISMS-Helper/commit/c30243b5b82d531e41eeb281cba663febc5c499a))
* **bcm:** Person-Rollout Phase B1 — BCM cluster owner-fields use Person ([7c8a858](https://github.com/moag1000/Little-ISMS-Helper/commit/7c8a858c8dd3e51bec0e2c4990fc21340b3a3f34))
* **bcm:** structured JSON fields per BCM-Specialist (T31.2.4) ([8bd1677](https://github.com/moag1000/Little-ISMS-Helper/commit/8bd167771143126c2ccbc2e3774114da5d082220))
* **bsi-mapping:** expand IT-Grundschutz coverage from 15 → 106 Bausteine ([9807e9c](https://github.com/moag1000/Little-ISMS-Helper/commit/9807e9c27706b298db6e72c06c70e6de15ddf8e2))
* **bsi:** canonical catalogue tree for IT-Grundschutz-Kompendium 2023 ([67bb14b](https://github.com/moag1000/Little-ISMS-Helper/commit/67bb14ba094adec21ed810aa71ead041074ff073))
* **bsi:** single canonical loader + deprecate 5 old loaders ([b1a6249](https://github.com/moag1000/Little-ISMS-Helper/commit/b1a624939ee39b1201e54ab2b629daea273733a3))
* **bulk:** canonical bulk-action-bar CSS + Stimulus per design-system spec ([90d04cb](https://github.com/moag1000/Little-ISMS-Helper/commit/90d04cb94c21117772435901ec6437cefbdeaad7))
* **catalogues:** add ISO 27017/18/27701/42001/PCI-DSS + BSI-C5:2020 full + Grundschutz variants ([aa443c3](https://github.com/moag1000/Little-ISMS-Helper/commit/aa443c36dd0fd5de66a9ace9c3d2178f93be0b7e))
* **catalogues:** bundle NIST CSF 2.0 active subcategories as JSON ([d17bd42](https://github.com/moag1000/Little-ISMS-Helper/commit/d17bd42bdbf32d1124ae529717c3fe005f912718))
* **catalogues:** DORA Level-2 RTS/ITS expansion (131 new requirements) ([b4976ac](https://github.com/moag1000/Little-ISMS-Helper/commit/b4976ace57992a1b33308b5473a90e1826411787))
* **catalogues:** EU AI Act + NIS2UmsuCG full + ISO 27017/18 expansion ([d8d2ca3](https://github.com/moag1000/Little-ISMS-Helper/commit/d8d2ca3cc846494fa284db1937a9417ccda6add7))
* **catalogues:** GDPR (99 Articles) + EU CRA (50 entries) + NIS2 (70 entries) full ([7183f23](https://github.com/moag1000/Little-ISMS-Helper/commit/7183f235815a16d0557de82dba9316d0096b6338))
* **cert-bundle:** enrich INDEX.csv with approver/sha256/run_id + multi-framework support ([9f2fea2](https://github.com/moag1000/Little-ISMS-Helper/commit/9f2fea2667de30357d9865bf15f753db90cab6e7))
* **cert-bundle:** konzern-scoped multi-tenant export with aggregated INDEX + RACI ([a5b3ec5](https://github.com/moag1000/Little-ISMS-Helper/commit/a5b3ec5334b998350536b4fc4e884d5fbf5c2515))
* **cert-bundle:** multi-framework parameter (Audit-V3 A3) ([3660dc9](https://github.com/moag1000/Little-ISMS-Helper/commit/3660dc97919edb3f7ceb26eaab082833f87e0ce0))
* **comments:** adopt _isms_comment_thread in 3 more show-pages (V3 W3-Aurora comments) ([99941d5](https://github.com/moag1000/Little-ISMS-Helper/commit/99941d585305232c2788072df6574a2ba8335c2c))
* **comments:** adopt _isms_comment_thread in 3 show-pages (V3 W2-H3) ([8c2b140](https://github.com/moag1000/Little-ISMS-Helper/commit/8c2b140c5a5e8420f3d0b32da1cf495c20f5bbec))
* **compliance-wizard:** add BSI C5:2026 readiness wizard ([21bf0e3](https://github.com/moag1000/Little-ISMS-Helper/commit/21bf0e3f23608b31177838a5857af9271ea7fd96))
* **compliance-wizard:** add EU AI Act, EUCS and CRA readiness wizards ([4d76cc9](https://github.com/moag1000/Little-ISMS-Helper/commit/4d76cc9cd5948d52eb1ef5037355c8999f427953))
* **compliance-wizard:** add NIS2 maturity ladders (Baseline / Enhanced) ([c20345f](https://github.com/moag1000/Little-ISMS-Helper/commit/c20345f3a04f60604d840fe2a055339c3566016b))
* **compliance-wizard:** add PCI-DSS v4.0.1 and SOC 2 Type II readiness wizards ([4b1913c](https://github.com/moag1000/Little-ISMS-Helper/commit/4b1913c013f3ec7a51e4e38bd54456ac5ffabe27))
* **compliance-wizard:** Alva-fairy import for shipped mapping libraries ([14ce2a3](https://github.com/moag1000/Little-ISMS-Helper/commit/14ce2a3500c4de4c86d306e24e653a9aa63c5258))
* **compliance-wizard:** on-demand data takeover from mapped frameworks ([da01d4c](https://github.com/moag1000/Little-ISMS-Helper/commit/da01d4c2d9b837b6bd570f3e6501344913e666ed))
* **compliance-wizard:** V4-EF-3 — Wizard-History Diff-View ([9be64ed](https://github.com/moag1000/Little-ISMS-Helper/commit/9be64ed436ab67e331c12a55e9b55988637ae357))
* **compliance:** V4-EF-5 + V4-EF-8 — CM-Heatmap-Drill + Cert-Bundle-Preflight ([32f9b94](https://github.com/moag1000/Little-ISMS-Helper/commit/32f9b94e3140debaefa024f09ac7d3128ee59e48))
* **consent:** GDPR Art. 7(3) Widerruf-Tracking — withdrawnAt + reason + channel (T31.1.3) ([c582188](https://github.com/moag1000/Little-ISMS-Helper/commit/c582188d88c0b0af3fb5fe4cb3d4f5f7af1dec55))
* **content:** author 8 BSI policy bodies — awareness/cloud/deletion/detection/emergency/travel/iam/incident_response ([9b2e4fc](https://github.com/moag1000/Little-ISMS-Helper/commit/9b2e4fcefd3aa050265cf3d663aad0777971d9bc))
* **content:** consolidate 25 BSI + 7 ISO policy bodies into batch4/batch5 ([1196846](https://github.com/moag1000/Little-ISMS-Helper/commit/1196846846b75cc545e28b5169a34670f8cc4d90))
* **content:** re-author 13 thin ISO 27001 policy bodies to specialist depth (6-11K chars each) ([98d768c](https://github.com/moag1000/Little-ISMS-Helper/commit/98d768cbd1205774b2a83403fccea271609c70c7))
* **content:** seed + author 10 ISO 27701:2025 PIMS policy templates ([440ec44](https://github.com/moag1000/Little-ISMS-Helper/commit/440ec4476c063c627e59fdddb84b4eb9211a7943))
* **content:** seed + author 10 SOC 2 Trust Services Criteria policy templates ([f5ca42a](https://github.com/moag1000/Little-ISMS-Helper/commit/f5ca42aec3b495645dbd0a0d9271fbe3cc018049))
* **content:** seed + author 10 TISAX policy templates (VDA ISA + PSx + DSx) ([5cb15a7](https://github.com/moag1000/Little-ISMS-Helper/commit/5cb15a7e2610b9e0a877581539f7a5a26394df30))
* **content:** seed + author 12 BSI C5:2026 cloud security policy templates ([88ec018](https://github.com/moag1000/Little-ISMS-Helper/commit/88ec0182b933bd2da620c5787b121d427c1e7e54))
* **content:** seed + author 12 NIS2 policy templates (Art. 21 measures + Art. 23 reporting) ([f957a15](https://github.com/moag1000/Little-ISMS-Helper/commit/f957a155ebf6d3da2b29ece5ee356b6c2689956a))
* **content:** seed + author 15 additional BSI Bausteine (APP/SYS/NET/INF/IND) ([926a065](https://github.com/moag1000/Little-ISMS-Helper/commit/926a06589caf49d2687f4d0c8e2b451d49734196))
* **content:** seed + author 8 KRITIS sector-specific B3S policy templates ([8781452](https://github.com/moag1000/Little-ISMS-Helper/commit/8781452a102d6efc72e3616eabb1764d25ad56c8))
* **controls+risk+supplier:** Sprint 6-7 — Control effectiveness/cloud + RiskType DORA-ICT + Supplier MaRisk (T31.5+) ([5b84a28](https://github.com/moag1000/Little-ISMS-Helper/commit/5b84a28858ad041cd31181562fc42403cc63a669))
* **coverage:** inverse-impact for control/asset/risk (V3 B6) ([95cc1dd](https://github.com/moag1000/Little-ISMS-Helper/commit/95cc1ddd2aad0024f2cc0a94d2f8833608f8ef76))
* **dashboard:** compliance-manager dashboard (V3 C2) ([a860d97](https://github.com/moag1000/Little-ISMS-Helper/commit/a860d97a46558b5e5ac0ad8d37a46faf35d1c9eb))
* **de-fitness:** BAFA LkSG annual-report CSV export and DPIA PDF SDM block ([bca70e9](https://github.com/moag1000/Little-ISMS-Helper/commit/bca70e937b1cc7e608190109aa556c3452d1e774))
* **de-fitness:** wire UI for NIS2 MUS, LkSG, and SDM 3.1 ([ef8df47](https://github.com/moag1000/Little-ISMS-Helper/commit/ef8df4732fc71b9b695bf934a1d4f0f15a946882))
* **design-system:** apply ISMS Audit-Trail to workflow instance approval history (S2-extension) ([af1fef4](https://github.com/moag1000/Little-ISMS-Helper/commit/af1fef4657bab58742715e5a8bfa8cd203530c3f))
* **design-system:** implement ISMS Audit-Trail Aurora pattern (S2) ([5f1be20](https://github.com/moag1000/Little-ISMS-Helper/commit/5f1be204f292cd052efa98e6677a91fb20140feb))
* **design-system:** implement ISMS Risk-Matrix Aurora pattern (S1) ([1cd665a](https://github.com/moag1000/Little-ISMS-Helper/commit/1cd665ae8373d17ad58cb8d861c5d5fb2c10fec9))
* **document:** effectiveness review workflow + warning-acknowledgement audit + step-7 explicit confirmation ([724308c](https://github.com/moag1000/Little-ISMS-Helper/commit/724308c43933bad1c8505a9000ca5ee9c04da911))
* **document:** policy body preview on show + junior-friendly drift wording ([88c5ad3](https://github.com/moag1000/Little-ISMS-Helper/commit/88c5ad3997db9b0db50f08d22e301a9c111b7b7a))
* **document:** review-cycle auto-set on approval (V3 W2-LB-8 + WS-9) ([5c4f13e](https://github.com/moag1000/Little-ISMS-Helper/commit/5c4f13ed9ca2177a36bcfea195cf8d9b7a6f851d))
* **dpia:** map SDM 3.1 protection goals onto DSFA ([5220e37](https://github.com/moag1000/Little-ISMS-Helper/commit/5220e370b1a309a39639dbead5b88cf5b90f1958))
* **dpia:** review-cycle reminder cron (V3 W2-FV-7) ([72021e1](https://github.com/moag1000/Little-ISMS-Helper/commit/72021e108b0f40bb96f2278bb12027620501dec7))
* **dsr:** GDPR Art. 12(3) Frist-Tracking — responseAt + extendedDeadline + reason + document + method + rejection (T31.1.4) ([26a2108](https://github.com/moag1000/Little-ISMS-Helper/commit/26a21086be7888206d9f5db2ad0831d22fe519b5))
* **events:** 5 auto-reactions (DPIA/Training/Risk/CA/Ack) (V3 C3) ([f5e46dd](https://github.com/moag1000/Little-ISMS-Helper/commit/f5e46dd79b004dc4d419e65d5d9771a1babc365d))
* **events:** asset-schutzbedarf DPIA-check listener (V3 W2-WS-10) ([f517546](https://github.com/moag1000/Little-ISMS-Helper/commit/f51754613dda4b061456eb3980e33361c6443a97))
* **events:** expand DPIA-trigger to Asset.dataClassification + vulnerable-categories (V3 W2-H5) ([8a8ee3c](https://github.com/moag1000/Little-ISMS-Helper/commit/8a8ee3cec72ab5986a1bea0ddc60065c06080b98))
* **events:** framework-import auto-coverage-check (V3 W2-WS-12) ([e196314](https://github.com/moag1000/Little-ISMS-Helper/commit/e1963145f2bd318c878a5f1786efba2acedd0f6d))
* **events:** notifications for auto-reactions (V3 W2-H4) ([27fdd98](https://github.com/moag1000/Little-ISMS-Helper/commit/27fdd98062f50d53f188ea6d8bafbf9b41628e5f))
* **events:** severity-based CA-due-days config (V3 W2-WS-7) ([5be7245](https://github.com/moag1000/Little-ISMS-Helper/commit/5be7245a649536b92f1682b13edf9f51141925ab))
* **form-theme:** file/range/date/time/datetime widgets im Aurora cyber-input ([96826d0](https://github.com/moag1000/Little-ISMS-Helper/commit/96826d0b444cdf37dc21ae8e6d8d0757e3c22d94))
* **forms:** add _fa_cyber_field macro for hand-rolled Aurora-Frame inputs ([f48cf1c](https://github.com/moag1000/Little-ISMS-Helper/commit/f48cf1c4c3f29b212a98e0da54f8009e347cf5d9))
* **i18n:** make management-report + cert-bundle PDF templates fully translatable (DE+EN) ([251055d](https://github.com/moag1000/Little-ISMS-Helper/commit/251055d87dbf2aba79bb284af85b8f80d925a0b7))
* **i18n:** make MRIS audit-report PDF fully translatable (DE+EN) ([444bfc4](https://github.com/moag1000/Little-ISMS-Helper/commit/444bfc4081cb0129d92399b7455c60cb9512d060))
* **incident:** ISO A.5.28 evidence + DORA Art. 17-19 ICT-incident-fields (T31.2.2) ([57b5ac3](https://github.com/moag1000/Little-ISMS-Helper/commit/57b5ac3a29e67cd6ccc2485f240cfb3968b8c0d2))
* **incident:** linked-risk update-button + audit-trail (V3 W2-FV-5) ([04bb60f](https://github.com/moag1000/Little-ISMS-Helper/commit/04bb60f2a25d10fe7f0f6e3216efd7b6e142ea3c))
* **isms:** approval-stages + comment-thread aurora-patterns (V3 C7) ([851fe55](https://github.com/moag1000/Little-ISMS-Helper/commit/851fe5513876822fdaec08413db8212fc522d60b))
* **konzern-rollup:** quarterly trend sparkline + one-pager pdf export skeleton ([19c806f](https://github.com/moag1000/Little-ISMS-Helper/commit/19c806fea5d59aaa37904c258e012872b53590eb))
* **management-review:** ISO 27001 §9.3 norm-fields (T31.2.5) ([daccc7e](https://github.com/moag1000/Little-ISMS-Helper/commit/daccc7eb4fa22987fa9d90a7401698177bf06796))
* **mappings:** add BSI C5:2020 ↔ C5:2026 cross-framework mapping (pilot) ([aaa47dd](https://github.com/moag1000/Little-ISMS-Helper/commit/aaa47ddd8c54f28dcc289371a386ecd17d8d888e))
* **mappings:** BSI C5:2026 ↔ NIS2 Art. 21 cross-framework mapping ([c737edb](https://github.com/moag1000/Little-ISMS-Helper/commit/c737edb96974fb285ca865bf6f48c5294f74e793))
* **mappings:** bundle BSI C5:2026 catalogue + redo C5:2020↔2026 with real IDs ([e784d48](https://github.com/moag1000/Little-ISMS-Helper/commit/e784d48bd6744d2528a93985af7bd2e9a5a7eb41))
* **mappings:** C5:2026 ↔ ISO27001 + ISO27001 ↔ NIST CSF 2.0 sweep ([db516e5](https://github.com/moag1000/Little-ISMS-Helper/commit/db516e5e4f1d26832ae87cbaadc1f7b3e2a5e482))
* **mappings:** DORA ↔ NIS2 lex-specialis mapping (Article 4(1) NIS2) ([aa376c7](https://github.com/moag1000/Little-ISMS-Helper/commit/aa376c70a073fb699b55dedc4b842af065cd32f9))
* **mappings:** EUCS / CRA / NIS2 / ISO27001 cross-framework sweep + SIM/OIS fix ([6c32730](https://github.com/moag1000/Little-ISMS-Helper/commit/6c3273078f62a27e30e31580bee569e9f16caf2b))
* **mappings:** full catalogues + working bidirectional import (2755 mappings live) ([2a3eda7](https://github.com/moag1000/Little-ISMS-Helper/commit/2a3eda7005b68cbe39cd56f9015be7751c212f24))
* **mappings:** seven new cross-framework pairs (705 mappings, +DB total 3543) ([129906e](https://github.com/moag1000/Little-ISMS-Helper/commit/129906ed36915b58c72f3c6a3cc406aa3cd5012b))
* **menu:** expose 12 hidden modules via mega-menu (Audit-V3 A2) ([fa22570](https://github.com/moag1000/Little-ISMS-Helper/commit/fa2257032604b906424604daf4a5c3bcefd11f2b))
* **mgmt-review:** auto-collect from §9.3 sources (V3 B4) ([986c947](https://github.com/moag1000/Little-ISMS-Helper/commit/986c94751e78ee0cc41df3945cf076801cd65e84))
* **migration:** Sprint-1 norm-fields — Risk justifikation, Consent withdrawal, DSR frist-tracking (T31.1.7) ([3af1d49](https://github.com/moag1000/Little-ISMS-Helper/commit/3af1d4928e8912b12382f447a9f6a64d01db54e1))
* **migration:** Sprint-2 norm-fields — Incident DORA + BCM JSON + ManagementReview (T31.2.6) ([e327d02](https://github.com/moag1000/Little-ISMS-Helper/commit/e327d02b213d707876cf8acde6a8f53190bc43ee))
* **modules:** 8 konsolidierte Module-Keys + ModuleAwareFormTrait für FormType-Gating ([2f21e01](https://github.com/moag1000/Little-ISMS-Helper/commit/2f21e01c6fc990db6324d4d30ca35f99bced7e8c))
* **modules:** add 6 new module-keys for v4-roadmap features (Sprint 0 Task 0.2) ([a8c38fa](https://github.com/moag1000/Little-ISMS-Helper/commit/a8c38fac1e296a8d8a0e12662f7d05c709661f5d))
* **modules:** add 6 new module-keys for v4-roadmap features (Sprint 0 Task 0.2) ([02817ed](https://github.com/moag1000/Little-ISMS-Helper/commit/02817edeb7629f5c96e21cde33ee4159a4a46f01))
* **modules:** whole-form-gating 8 GDPR+BCM controllers (T31.2.1) ([e13edce](https://github.com/moag1000/Little-ISMS-Helper/commit/e13edce2354dce025317a91db49c874852c7990f))
* **my-day:** central inbox aggregator (V3 C1) ([e8138f7](https://github.com/moag1000/Little-ISMS-Helper/commit/e8138f7a136da50f93444ef12d4f065844794817))
* **nav:** cm-dashboard in mega-menu (V3 W2-H2) ([9346b50](https://github.com/moag1000/Little-ISMS-Helper/commit/9346b50b4d145577287fd63e623d867a5f589bc1))
* **nav:** item-level module-gating for Operations + Compliance panels ([ccbcb0b](https://github.com/moag1000/Little-ISMS-Helper/commit/ccbcb0b1cbe5fe542b4786993e965129283d4dd4))
* **nav:** item-level module-gating for Operations + Compliance panels ([ecfa11e](https://github.com/moag1000/Little-ISMS-Helper/commit/ecfa11e2ab8912727c635e54efbe06bf9e9b1f9a))
* **nis2:** BSI MUS export for Article 23 incident reporting ([c8932ad](https://github.com/moag1000/Little-ISMS-Helper/commit/c8932ad9d212171f80c87181898645603738f75b))
* **objective+aurora:** close T31.5.1 + Plan-2 alva-z fallback gaps ([4923198](https://github.com/moag1000/Little-ISMS-Helper/commit/492319851d60f28e7d53f3c98bae51dd877edaf4))
* **objective+aurora:** close T31.5.1 + Plan-2 alva-z fallback gaps ([0765dfc](https://github.com/moag1000/Little-ISMS-Helper/commit/0765dfc3aa6873fa40fa913c07a774fb96980a9d))
* **policy-doc:** adopt design-system 5-slot template (cover/toc/history/body/signature) for PDF + show-view ([0818608](https://github.com/moag1000/Little-ISMS-Helper/commit/081860832d22fdb24680e885e050376bea2ba95c))
* **policy-wizard:** 93 Annex-A control titles + Junior-friendly descriptions ([4b1b4df](https://github.com/moag1000/Little-ISMS-Helper/commit/4b1b4dfb9a576a83bfa5434313bf9cbb32d57023))
* **policy-wizard:** add ISO 27001 PolicyTemplate seed (25 templates) ([7e5a48c](https://github.com/moag1000/Little-ISMS-Helper/commit/7e5a48c9303d8ced1f4fc5d9465a778de1300c77))
* **policy-wizard:** approver-role validation per topic + audit-trail of role match/mismatch ([bd3fa60](https://github.com/moag1000/Little-ISMS-Helper/commit/bd3fa603728d156176ad8238b196083514096b7e))
* **policy-wizard:** auto-route approval to ROLE_TOP_MGMT user when present ([0735c79](https://github.com/moag1000/Little-ISMS-Helper/commit/0735c79f50106ca00786edd8528166c230f07380))
* **policy-wizard:** auto-update SoA implementation_status on document generation ([704a159](https://github.com/moag1000/Little-ISMS-Helper/commit/704a1599ab53c270ec3abf453810fed7ef852536))
* **policy-wizard:** close 16 Junior-ISB wishes + NIS-2/DORA lex specialis ([ea1d487](https://github.com/moag1000/Little-ISMS-Helper/commit/ea1d48706ee37c15e07a5b32a805a320603a8bd8))
* **policy-wizard:** cross-framework coverage matrix + workflow/soa/approval links on result page ([b783203](https://github.com/moag1000/Little-ISMS-Helper/commit/b783203617c60c2410c9bfb451340e734a838942))
* **policy-wizard:** editable policy bodies post-generation (drift-aware) ([b362810](https://github.com/moag1000/Little-ISMS-Helper/commit/b3628108f16105e643581576425067bb2c490ec5))
* **policy-wizard:** expose NIS2/TISAX/SOC2/C5 in standard picker + 3 new industry presets ([8e286cb](https://github.com/moag1000/Little-ISMS-Helper/commit/8e286cbc84000d21cdec36b12d5b94b3dec2f27f))
* **policy-wizard:** junior-friendly annex-a klartext + dora q-preview + crypto algo explanations ([4065534](https://github.com/moag1000/Little-ISMS-Helper/commit/40655344c2bbc93d7a23b2e675e0b927289a821b))
* **policy-wizard:** Junior-ISB UX rollout + 5 Bestandsaufnahme MUST-have items ([0e39c54](https://github.com/moag1000/Little-ISMS-Helper/commit/0e39c5411342547d6dad5ccfe03c06843e2d141e))
* **policy-wizard:** Person-Rollout Phase A — Step-4 Roles person-pickers ([0c94d38](https://github.com/moag1000/Little-ISMS-Helper/commit/0c94d38d4487b87b5c9d5c765927bc0a472bc22d))
* **policy-wizard:** Policy-Wizard W1-W7 + GDPR/DORA/BSI/BCM/PIMS + DPO veto + 4 industry presets ([d3ffa63](https://github.com/moag1000/Little-ISMS-Helper/commit/d3ffa6399ed80a3d84968cf4e698e51b14da60ad))
* **policy-wizard:** prominent norm-anker header + bsi 200-2 interval guidance + dora-validity + climate-change badge ([30b2f97](https://github.com/moag1000/Little-ISMS-Helper/commit/30b2f97818d3b1801530b4b3e3640892e00931cc))
* **policy-wizard:** replace step 7 raw JSON blob with readable review summary ([3fc2049](https://github.com/moag1000/Little-ISMS-Helper/commit/3fc20494447b7497953ff20b5c6eef9bba2643d3))
* **policy-wizard:** Step 2 prefill + Location-MultiSelect + AuditFinding picker + Aurora form-theme cleanup ([b8737c6](https://github.com/moag1000/Little-ISMS-Helper/commit/b8737c60cba6491a9704cce0f1c856736571eb91))
* **policy-wizard:** W1: Domain + audit-readiness baseline (39 files, 5539 LOC) ([0a483d2](https://github.com/moag1000/Little-ISMS-Helper/commit/0a483d2e4a66755ea514491a7896c5e056ea9560))
* **policy-wizard:** W2: 7-step Wizard core + Sandbox + Targeted re-run (50 files, 5816 LOC) ([392f8d7](https://github.com/moag1000/Little-ISMS-Helper/commit/392f8d70dc89e7e00c411f78f742f7602c88dc40))
* **policy-wizard:** W3 content refinements — agent post-commit polish ([268b809](https://github.com/moag1000/Little-ISMS-Helper/commit/268b809504060de93c120507bedba497a8023bbd))
* **policy-wizard:** W3: DocumentGenerator + SoA + Konzern push-down + DPO veto + content (55 files, 11583 LOC) ([91befe9](https://github.com/moag1000/Little-ISMS-Helper/commit/91befe9bdeac9463a4246acfa55101ae122aa60a))
* **policy-wizard:** W3.5: close 4 integration gaps + production triggers (37 files) ([b1ec20d](https://github.com/moag1000/Little-ISMS-Helper/commit/b1ec20db80375fc5b234c6bc420d8b0caf5c587f))
* **policy-wizard:** W4: DORA addon + IndustryPresetBundle + Bestandsaufnahme + DORA Compliance-Checks (59 files, 8989 LOC) ([ccec18b](https://github.com/moag1000/Little-ISMS-Helper/commit/ccec18b312833d44401669ced3bb5b7acd70d313))
* **policy-wizard:** W5: BSI Grundschutz + BCM (62 files, 17316 LOC) ([1f64b50](https://github.com/moag1000/Little-ISMS-Helper/commit/1f64b5013f4bde87714d7d10e38fb07753fab011))
* **policy-wizard:** W6: DPO Phase + GDPR-section pattern (53 files, 10316 LOC) ([893bfe0](https://github.com/moag1000/Little-ISMS-Helper/commit/893bfe0fa8023c01192d52e00cabf2e98364daca))
* **policy-wizard:** W7: PDF + ZIP exports + Konzern rollup + diff UX + Alva-Hints + dashboard widgets (63 files, 9994 LOC) ([ef90598](https://github.com/moag1000/Little-ISMS-Helper/commit/ef905982b9fabdf7ef8e2adddb0be3de16068a5a))
* **presets:** industry presets de-mittelstand/kritis/health/saas (V3 C4) ([491a666](https://github.com/moag1000/Little-ISMS-Helper/commit/491a66665bb781b3f9a87d2a448e6bb548be73fb))
* **presets:** real industry-preset loader + admin UI (V3 W2-M3) ([1064f25](https://github.com/moag1000/Little-ISMS-Helper/commit/1064f25eb20839768487b8f2a5b6ff62e5096c06))
* **privacy+incident:** Person-Rollout Phase B2 — privacy/incident/audit owner-fields use Person ([37ea530](https://github.com/moag1000/Little-ISMS-Helper/commit/37ea530ea4a61235e951a60e7876e9538789bfba))
* **profile:** clearer password rules + always-on length validation ([2fa09d3](https://github.com/moag1000/Little-ISMS-Helper/commit/2fa09d3b6e8f888c36e9a2fcb3b224412df7738c))
* **quick-fix:** all-in-one Operator-UI — DataRepair-Operations integriert ([7d8dce4](https://github.com/moag1000/Little-ISMS-Helper/commit/7d8dce427e85baaf3bb782e1336a40ce17948104))
* **quick-fix:** auto-reconcile additive schema drift on runtime exception ([1eb2b47](https://github.com/moag1000/Little-ISMS-Helper/commit/1eb2b478650f1efad9dc8fc0930e38171632a978))
* **quick-fix:** auto-reconcile additive schema drift on runtime exception ([14b6683](https://github.com/moag1000/Little-ISMS-Helper/commit/14b6683a67aaa1fda44e9a5184f02c05e73eecbf))
* **report-doc:** design-system 5-slot report template ([e041cd0](https://github.com/moag1000/Little-ISMS-Helper/commit/e041cd0593dc99a903cbae0643461313f912e576))
* **reports:** locale-override for PDF routes (?locale=de|en) ([194b839](https://github.com/moag1000/Little-ISMS-Helper/commit/194b839c602a5d2c5e7fc797de23ac7a64a3229c))
* **reports:** migrate 10 management_reports PDF templates to _fa_report_doc 5-slot macro (Wave 1) ([8014268](https://github.com/moag1000/Little-ISMS-Helper/commit/8014268b00de1b2c090a456c2c26e07d7d02262f))
* **reports:** UI locale-switch link below each PDF download button ([d37a5f9](https://github.com/moag1000/Little-ISMS-Helper/commit/d37a5f983940636e1b83456513105430bc1a79e2))
* **reports:** Wave 2 — migrate group_report (7) + portfolio_report (2) to fa-report-doc macro ([27e00b5](https://github.com/moag1000/Little-ISMS-Helper/commit/27e00b5a99fd3d5e20f32f7cf87e918f2e8fbafe))
* **reports:** Wave 3 — migrate reports/ PDF templates to Aurora-token CSS ([2738875](https://github.com/moag1000/Little-ISMS-Helper/commit/27388759b288dd5dac93789a99d447e833b86767))
* **reports:** Wave 4 — soa/audit/audit_freeze PDFs to Aurora tokens + remove raw hex ([2516537](https://github.com/moag1000/Little-ISMS-Helper/commit/2516537ec528d3abf3286741deca545a9d15b538))
* **reports:** Wave 5 — pdf/ analysis + certification_bundle templates, Aurora tokens ([b033340](https://github.com/moag1000/Little-ISMS-Helper/commit/b0333404802db9f0a1a448c2a8c0e3278cab45a9))
* **reports:** Wave 6 — compliance_wizard gap_report macro + pdf Aurora tokens ([cab4d51](https://github.com/moag1000/Little-ISMS-Helper/commit/cab4d51840c10157c6e615781e48f2ca82e2b27d))
* **reports:** Wave 7 — incident/privacy/DORA PDF templates to Aurora tokens ([917d31d](https://github.com/moag1000/Little-ISMS-Helper/commit/917d31d107969e6ec3ea9cab5e7565dae9871aaa))
* **reports:** Wave 8 — mris/prototype_protection/management_review/report_builder PDFs ([44d98ad](https://github.com/moag1000/Little-ISMS-Helper/commit/44d98ad82e1acbb6b3c6e0b926877ad14cb43e39))
* **risk:** add acceptance-expiry-date for ISO Cl.8.3 (Audit-V3 A6) ([bf603d2](https://github.com/moag1000/Little-ISMS-Helper/commit/bf603d268d83a0dcd9c08e06abde05222ff90542))
* **risk:** justifikation-fields + GDPR-subset gating + RiskAcceptanceVoter (T31.1.2) ([66ad361](https://github.com/moag1000/Little-ISMS-Helper/commit/66ad361f2510b95a4ac3ba90c8ce9af64f0c60fd))
* **samples:** add Vulnerability + Patch + ChangeRequest + CorrectiveAction sample fixtures ([27b4692](https://github.com/moag1000/Little-ISMS-Helper/commit/27b4692b915e22777f9e939bf1ba9679b9a57f55))
* **schema-maintenance:** diagnose migration failures with actionable repair suggestions ([89962de](https://github.com/moag1000/Little-ISMS-Helper/commit/89962deb4d56449ffc2ee0a7d582d8e51bad89fa))
* **settings:** Tier-1 compliance settings — locale, audit window, DPO, TLP, retention + global security defaults ([36cd6af](https://github.com/moag1000/Little-ISMS-Helper/commit/36cd6aff8167ce73ee5ae7b844ae4a34d2fe6799))
* **settings:** Tier-2 operational settings — risk methodology + matrix size + wizard maturity target ([d876715](https://github.com/moag1000/Little-ISMS-Helper/commit/d876715dcfabcad09d0f7095c07269a6cf590488))
* **settings:** Tier-2 operational settings — risk methodology + matrix size + wizard maturity target ([8087adf](https://github.com/moag1000/Little-ISMS-Helper/commit/8087adf821b2ea9669e5c0868a364a2fbafbffc7))
* **settings:** Tier-3 globals + JSON sub-form-UI on tenant compliance settings ([9ac2767](https://github.com/moag1000/Little-ISMS-Helper/commit/9ac276790ea6ce952da92c91efb97277aa34c638))
* **setup-wizard:** V4-EF-1 — Industry-Preset Express-Path im Setup-Wizard ([3e91916](https://github.com/moag1000/Little-ISMS-Helper/commit/3e91916250fa9960200f223b30954614981f5003))
* **show:** risk + audit show with full collections (V3 B3) ([20145e1](https://github.com/moag1000/Little-ISMS-Helper/commit/20145e12f72756c24e8556c5b2a5df59e22af249))
* **show:** risk show with linked-assets section (V3 W2-FV-2) ([d1257d7](https://github.com/moag1000/Little-ISMS-Helper/commit/d1257d7e2b0b4ca12aa83c74b8793b602788cefe))
* **soa:** point-in-time snapshot service + as-of-date cert bundle export ([a5890b8](https://github.com/moag1000/Little-ISMS-Helper/commit/a5890b8bc6b0861c2ae8c57c70f0b07e0fa1bab8))
* **stylelint:** ban raw numeric z-index values ≥6 + fix 7 T2-misses ([5140804](https://github.com/moag1000/Little-ISMS-Helper/commit/51408044caa50ed933892a307b2cebe75eccfa2b))
* **supplier:** LkSG due-diligence fields and reporting query ([5684a58](https://github.com/moag1000/Little-ISMS-Helper/commit/5684a5809cd6e3463b87c7ac6c414ab190d275eb))
* **support-clauses:** ISO 27001 §7.2 Competence + §7.3 Awareness + §7.4 Communication (T31.4.1) ([b4062ca](https://github.com/moag1000/Little-ISMS-Helper/commit/b4062ca31179badd8a9d40db46635074d310f6cb))
* **threat-intel + fair:** Sprint-8 ThreatIntel TLP/MITRE + Quantitative-Risk FAIR (T31.8.1) ([4c8e153](https://github.com/moag1000/Little-ISMS-Helper/commit/4c8e15363416e66e081f568be6940cfaf17bfabf))
* **threat-intelligence:** full CRUD web controller + templates + tests ([dc81ee9](https://github.com/moag1000/Little-ISMS-Helper/commit/dc81ee96893cc2286b3d1f7aef49620f75605d3e))
* **tokens:** consolidate z-index stack + add --r-icon/--shadow-overlay/--surface-translucent ([1ef0637](https://github.com/moag1000/Little-ISMS-Helper/commit/1ef0637e124210e10e50b6daddb56cd2af0c7ca7))
* **translations:** add 8 translation-domain skeletons for v4-roadmap (Sprint 0 Task 0.1) ([dc0b55b](https://github.com/moag1000/Little-ISMS-Helper/commit/dc0b55bc1f35410ba859253d86ad4b85fb8f2831))
* **translations:** add 8 translation-domain skeletons for v4-roadmap (Sprint 0 Task 0.1) ([f4c1664](https://github.com/moag1000/Little-ISMS-Helper/commit/f4c166462e40a4927cdf7b3279d75e4447ac92bd))
* **twig:** _badge severity-mapping + bridge doku (Audit H2 + ROADMAP Phase 5) ([5ee583e](https://github.com/moag1000/Little-ISMS-Helper/commit/5ee583e48efe5df670bc703fa792d44b93b1c7c6))
* **twig:** complete _card macro Aurora bridge (Audit H1) ([66fdce8](https://github.com/moag1000/Little-ISMS-Helper/commit/66fdce8cfe301554d6422f0d959a6879f316c961))
* **ui:** _fa_progress Macro — Aurora Progress-Bar ([a6232ff](https://github.com/moag1000/Little-ISMS-Helper/commit/a6232ff4cbf5c78a7af9f3acbc88f6a97f468233))
* **ui:** Aurora toast + confirm helpers replacing native alert/confirm ([5145b43](https://github.com/moag1000/Little-ISMS-Helper/commit/5145b4380efde50ab65c547639b97c9bf7aed0aa))
* **ux:** V4-EF-7 + V4-EF-6 — MyDay CM-Buckets + ActivityFeed Compliance-Scope ([97f5689](https://github.com/moag1000/Little-ISMS-Helper/commit/97f56894335249aa9c94fc9d9c7ff08b7dbf6608))
* **ux:** V4-LB-1 Round-2 — MyDay +6 Buckets (Incident/Breach/Vuln/Checklist/Change/MgmtReview) ([169dc5a](https://github.com/moag1000/Little-ISMS-Helper/commit/169dc5abf684866ef77612fb69611995904631e4))
* **ux:** V4-LB-4 — Comment-Thread auf Asset/Control/CA/DSR Show-Pages ([38378f2](https://github.com/moag1000/Little-ISMS-Helper/commit/38378f251fbd0d06f5a505b5348126ca0a3f5be2))
* **wizard:** compliance-wizard snapshots + history (V3 B5) ([25fc036](https://github.com/moag1000/Little-ISMS-Helper/commit/25fc0366e37b58ec5fddeff35fde3f04aaf64067))
* **wizards:** catalogue-coverage KPI + Baseline/Enhanced maturity rollout to all 22 wizards ([eb98298](https://github.com/moag1000/Little-ISMS-Helper/commit/eb98298593765566d933f03c1a4bdbe016e67523))
* **wizards:** roll out Baseline/Enhanced maturity ladders to DORA + GDPR + AI Act ([d5fb60d](https://github.com/moag1000/Little-ISMS-Helper/commit/d5fb60def1e896e3cb0a1556d6d68f1c4b682f69))


### Fixed

* **a11y:** remove duplicate skip-link in base.html.twig (Audit-V3 A4) ([8f0275a](https://github.com/moag1000/Little-ISMS-Helper/commit/8f0275a4a3b82f248c8dcbb36a69a817568d431a))
* **activity-feed:** Document has no getTitle() — use getOriginalFilename() ([3afdabb](https://github.com/moag1000/Little-ISMS-Helper/commit/3afdabb27a46128f7f5fe6abbb98b0f86a1bd937))
* **admin-hub:** missing CSS for hub layout / search / groups ([aa10b4f](https://github.com/moag1000/Little-ISMS-Helper/commit/aa10b4f0aef9fdf77d2b84732a33bc87d71934af))
* **admin-settings:** Tier-1-aware security card + KernelTestCase coverage ([7293736](https://github.com/moag1000/Little-ISMS-Helper/commit/7293736df6986a17b2526fd2215d342bdfcf168c))
* **admin/modules:** add missing CSRF _token to activate/deactivate forms ([8b7adb2](https://github.com/moag1000/Little-ISMS-Helper/commit/8b7adb29654f0a6a711ef226d314f5db617b04cd))
* **admin:** tenant_compliance_settings breadcrumb i18n + admin.tenant_management.title (V3 W3-i18n-90) ([1dd881a](https://github.com/moag1000/Little-ISMS-Helper/commit/1dd881a47e2e1b5deb8605c1840f4317005d8db2))
* **alva-hint:** import macro at template scope + drop phantom DQL field ([6af73d6](https://github.com/moag1000/Little-ISMS-Helper/commit/6af73d68933bb4f5438610e88f1c30f36eb89c4b))
* **alva-hint:** tenant scope, snooze, role gating, and request cache ([cf139ab](https://github.com/moag1000/Little-ISMS-Helper/commit/cf139ab568d65bba0f2ce3bc2e11151bb452275e))
* **asset:** re-add AssetOwnerSyncListener (V3 W2-LB-6 follow-up) ([302bb26](https://github.com/moag1000/Little-ISMS-Helper/commit/302bb26b992f27816b71b3e71e3b1fdeca06d03c))
* **bcm:** missing _fa_empty_state import in bc_exercise/index.html.twig ([82e0238](https://github.com/moag1000/Little-ISMS-Helper/commit/82e02389c453b9e73b0d7b73beb2ae511dcc39cb))
* **bcm:** missing _fa_empty_state import in bc_exercise/index.html.twig ([925b473](https://github.com/moag1000/Little-ISMS-Helper/commit/925b473efdde37a874e793c66da599d10673c5c8))
* **bsi:** consolidate framework code BSI-Grundschutz → BSI_GRUNDSCHUTZ ([73c06dc](https://github.com/moag1000/Little-ISMS-Helper/commit/73c06dc4b3d0c0f417ea50bf71b11ff74b1fa128))
* **certification-bundle:** controls_applicable formula + Turbo-disable + 100%% escape ([80612ee](https://github.com/moag1000/Little-ISMS-Helper/commit/80612ee4def8ecd9fb42223e37ee8d4ed5a5c5cb))
* **certification-bundle:** include wizard-generated policies + skip archived legacy docs ([12ae37a](https://github.com/moag1000/Little-ISMS-Helper/commit/12ae37a66ed35fae562655fa6396cb0784aa6f9f))
* **ci+docs:** Sprint-0 readiness — phpunit deprecation policy + CLAUDE.md updates ([4ad85a7](https://github.com/moag1000/Little-ISMS-Helper/commit/4ad85a717439985bf758487f4eabdaf27c8ac149))
* **ci+docs:** Sprint-0 readiness — phpunit deprecation policy + CLAUDE.md updates ([3fb36f7](https://github.com/moag1000/Little-ISMS-Helper/commit/3fb36f7aa239af7084a538745e91f947ab61803e))
* **ci:** add untracked SetupIndustryPresetService + template + test ([3972ff7](https://github.com/moag1000/Little-ISMS-Helper/commit/3972ff7049a49807c9ceaf57e2e3d26363bce737))
* **ci:** post-merge-wave test repairs + CI schema reconcile ([cd5e2d5](https://github.com/moag1000/Little-ISMS-Helper/commit/cd5e2d5da29ce23e2fe65cb36ca3f3aa5a6f6ed3))
* **ci:** unblock CI Code-Quality stage (DQL scanner + PHPStan) ([b3d5e23](https://github.com/moag1000/Little-ISMS-Helper/commit/b3d5e235aaa47076ea80836ed8e56c055f6509cf))
* **compliance:** correct getComplianceFramework() → getFramework() / getScopedFramework() ([e8954f8](https://github.com/moag1000/Little-ISMS-Helper/commit/e8954f808baa3be3a952da46841359e60dc6abc5))
* **csrf:** add missing _token inputs to 16 raw POST-forms (CSRF-audit) ([2b72b11](https://github.com/moag1000/Little-ISMS-Helper/commit/2b72b115bc5e874864dafdb9f70d88355ccfd871))
* **dashboard:** aurora-tone progress-bars in cm-dashboard (V3 W3-Aurora dashboard) ([58669f0](https://github.com/moag1000/Little-ISMS-Helper/commit/58669f0cbc2a5c6bc8f4084eb2f5164aa7097337))
* **dashboard:** close Super-Admin tenant-less KPI inconsistency ([4d1227f](https://github.com/moag1000/Little-ISMS-Helper/commit/4d1227fe33c2c479a4cde61c39679da01dcb1290))
* **dashboard:** cm-dashboard field-naming + summary fields (V3 W2-M2) ([c072a42](https://github.com/moag1000/Little-ISMS-Helper/commit/c072a42fcf7ed418cb35988e4c1f25af961aafbb))
* **document/edit:** exclude policyBody from auto-form catch-all ([e3a2c53](https://github.com/moag1000/Little-ISMS-Helper/commit/e3a2c539b67e3c88c68a8a0a0d8bf9c69906e9d7))
* **document+nav:** close 3 policy-wizard discoverability gaps reported by user ([7e2664b](https://github.com/moag1000/Little-ISMS-Helper/commit/7e2664bea1d6512f3be28ff39054eb6785b28a2c))
* **document+wizard:** PDF preview drawer broken — inline disposition + 4 missing translations ([a488818](https://github.com/moag1000/Little-ISMS-Helper/commit/a4888185f98cf3096bdbfa45bcf75ac9eb6d2a72))
* **document:** add /document/bulk-delete-check endpoint (was 404) ([5d76835](https://github.com/moag1000/Little-ISMS-Helper/commit/5d76835f7a6fa0b050f3b7cde5c927698dffcdd6))
* **document:** add /document/bulk-export endpoint streaming a ZIP ([88d529d](https://github.com/moag1000/Little-ISMS-Helper/commit/88d529d3bddebb2cea99305325c3c415616cf673))
* **dora:** 7 further undefined-method calls in DoraComplianceController ([8938599](https://github.com/moag1000/Little-ISMS-Helper/commit/8938599ff71e639302aeba8d31869dafc0dc4af6))
* **dora:** Asset+Incident undefined-method calls ([d2dd3a9](https://github.com/moag1000/Little-ISMS-Helper/commit/d2dd3a959d9c458bd5e16881deb86ba1219d7b29))
* **dora:** Risk::getName() existiert nicht — auf getTitle() umgestellt ([7c03024](https://github.com/moag1000/Little-ISMS-Helper/commit/7c0302446d4880d33c4335caca6637961d28d3ad))
* **entity:** cascade persist on TrainingParticipation.training (CI fix) ([2da9459](https://github.com/moag1000/Little-ISMS-Helper/commit/2da945962ccae1a3f7fa3405e2e036ff5c3f2ca9))
* **fa-alert:** make {% embed %} pattern actually render output ([8b1c1e3](https://github.com/moag1000/Little-ISMS-Helper/commit/8b1c1e30dca3ef6eea71a6fbb52eb4b0a3840222))
* **form-theme:** file_widget — use form_widget_simple block instead of parent() ([10379fc](https://github.com/moag1000/Little-ISMS-Helper/commit/10379fc4f3900b47a49a2cc692a224b68f81b3bd))
* **form/consent:** Document choice_label 'title' → 'originalFilename' ([663436e](https://github.com/moag1000/Little-ISMS-Helper/commit/663436e3e5981157c4c4925653edd781197d3bd8))
* **forms:** _auto_form catch-all + asset/new field-coverage (Aurora v4 split-form bug) ([9e64f4e](https://github.com/moag1000/Little-ISMS-Helper/commit/9e64f4e891f21c5abb8672a6424771a751b08e23))
* **forms:** _auto_form catch-all + asset/new field-coverage (Aurora v4 split-form bug) ([7f085b6](https://github.com/moag1000/Little-ISMS-Helper/commit/7f085b68b6895346e23b6599f80601817189139c))
* **forms:** _form_field.html.twig auf Aurora-cyber-input umstellen (DESIGN_SYSTEM v4) ([559622c](https://github.com/moag1000/Little-ISMS-Helper/commit/559622cafd1df51c2c4e4661074fa784439d7f63))
* **forms+twig:** bc_exercise empty-state import scope + Url-deprecation defense-in-depth ([1b37cf6](https://github.com/moag1000/Little-ISMS-Helper/commit/1b37cf61db4172a6733cf794b54c29688532887c))
* **forms+twig:** bc_exercise empty-state import scope + Url-deprecation defense-in-depth ([6f83547](https://github.com/moag1000/Little-ISMS-Helper/commit/6f8354777d4fdca3257fda92eefefb4435618947))
* **forms:** re-apply Aurora delegation in _form_field.html.twig (war reverted) ([d010835](https://github.com/moag1000/Little-ISMS-Helper/commit/d0108355a0c28ab6d448f43164c154ec6f8de317))
* **i18n:** close ISO 27701 maturity translation gaps + correct plan spec ([8170d7b](https://github.com/moag1000/Little-ISMS-Helper/commit/8170d7b2d009f949b3e0a596c9ca3f79eea78f35))
* **i18n:** move tenant_settings block from kpi_threshold to admin namespace ([0a8cf24](https://github.com/moag1000/Little-ISMS-Helper/commit/0a8cf24308dc9fc0fe4395e62f3eef08fc00f804))
* **i18n:** translate my_day + activity_feed (V3 W3-i18n-90) ([7c3da78](https://github.com/moag1000/Little-ISMS-Helper/commit/7c3da783a3bf1106780a290f876727321ba8e879))
* **mappings:** align Framework codes with wizard registry + normalise fixtures ([9d88697](https://github.com/moag1000/Little-ISMS-Helper/commit/9d886977347db20605b74a51dd443c4bccb134ac))
* **mappings:** correct semantic mismatches in C5:2020 ↔ 2026 pilot ([b3b58bb](https://github.com/moag1000/Little-ISMS-Helper/commit/b3b58bbf2a4ced39859d817af85f877ba50a8acd))
* **mappings:** EUCS A16 → ISO A.5.35 instead of A.5.31 ([8e0f466](https://github.com/moag1000/Little-ISMS-Helper/commit/8e0f46664aaad67e7e5b5b46ce1c404d6b0fcac6))
* **mappings:** normalise C5:2020 prefixes against authoritative BSI catalogue ([0bcb1f2](https://github.com/moag1000/Little-ISMS-Helper/commit/0bcb1f237925ac55cbb55de24c0d061d024b43de))
* **mappings:** tighten C5:2026 ↔ NIS2 semantics post audit ([263c328](https://github.com/moag1000/Little-ISMS-Helper/commit/263c32893a1e668c222816b3808cd2364befec51))
* **merge:** dedupe duplicate 'bsi' key in DOMAINS_BY_STANDARD ([f3b4ba8](https://github.com/moag1000/Little-ISMS-Helper/commit/f3b4ba8204896d5b6a6143fe4024c66b6b2d01d3))
* **merge:** post-merge wave repairs ([b45d826](https://github.com/moag1000/Little-ISMS-Helper/commit/b45d8266e2bf89cf7d5b40cb256eb24d90cd9dd8))
* **migration:** bc_exercise.exercise_leader_user_id FK references users (plural) not user ([eecaea6](https://github.com/moag1000/Little-ISMS-Helper/commit/eecaea66af634efc78038858cda79032bd596e25))
* **migration:** defensive RENAME INDEX in Version20260502114544 + subscriber log ([13c0ae9](https://github.com/moag1000/Little-ISMS-Helper/commit/13c0ae9a91aa7c0e9744a4da32f80d82b3ace371))
* **migration:** defensive RENAME INDEX in Version20260502114544 + subscriber log ([90dc373](https://github.com/moag1000/Little-ISMS-Helper/commit/90dc373e7803aeee27b4a77e52b5d6f3003f08c5))
* **migrations:** make Alva-Hint migrations idempotent for upgrades ([38624fb](https://github.com/moag1000/Little-ISMS-Helper/commit/38624fb14443a95a6bdb94494d4749df3df3d71d))
* **modules+wizards:** commit untracked SetupIndustryPresetService + map wizard module-keys to canonical config-keys ([3464f7c](https://github.com/moag1000/Little-ISMS-Helper/commit/3464f7cc73416be948483f0a4b466264dc275099))
* **modules:** add 14 missing operational modules to config + wizard-dependency mapping ([b2d7de8](https://github.com/moag1000/Little-ISMS-Helper/commit/b2d7de8ea5cb6be22314d3bc514ca3b244b9c465))
* **modules:** re-add 8 norm-gating module-keys (parallel-agent revert recovery) ([144de67](https://github.com/moag1000/Little-ISMS-Helper/commit/144de67f2ebdb0421fe6701d42620c0eea0c3838))
* **modules:** re-apply BCM module-gating reverted by parallel-agent (T31.2.1-redo) ([78c4fdf](https://github.com/moag1000/Little-ISMS-Helper/commit/78c4fdf223159f6497119c6b28b5c408508d0381))
* **orm:** pin policy_doc_show_annex_a_refs column-name to match migration ([7ef3af2](https://github.com/moag1000/Little-ISMS-Helper/commit/7ef3af261f069673c2050b666adc98760a56f6b9))
* **policy-wizard:** 4 critical runtime bugs blocking the Generate flow ([d560c37](https://github.com/moag1000/Little-ISMS-Helper/commit/d560c37c09c237486cc9865e2d83308d3a9af3db))
* **policy-wizard:** 4 surface bugs from user testing — sane defaults + readable labels ([1d4d5bb](https://github.com/moag1000/Little-ISMS-Helper/commit/1d4d5bb31a52f9fb53108cdd35bda91877d7f7ff))
* **policy-wizard:** Bestandsaufnahme UX baseline — translations + topic labels + conditional pickers ([9401bbe](https://github.com/moag1000/Little-ISMS-Helper/commit/9401bbe871af0976c1c56a3561b796d8515fb50a))
* **policy-wizard:** close 4 deep template-validate mismatches in wizard steps ([5b20bf6](https://github.com/moag1000/Little-ISMS-Helper/commit/5b20bf67084f573f470b8a4d8cb21d7e77c66030))
* **policy-wizard:** close 8 W1-W6 plan-conformance gaps (29 files, 2951 LOC) ([ad5c1ca](https://github.com/moag1000/Little-ISMS-Helper/commit/ad5c1ca30ae2fd1fa003e1e531d3cd93b51bb750))
* **policy-wizard:** drop broken errors._warnings nesting in RolesStep ([1c8e9cd](https://github.com/moag1000/Little-ISMS-Helper/commit/1c8e9cd7027ad5530f417cb642b0bef2d481a559))
* **policy-wizard:** embed-block trans_default_domain — 10 step partials ([c9cfa90](https://github.com/moag1000/Little-ISMS-Helper/commit/c9cfa905e98b34abfc2ce392941926e430927b0a))
* **policy-wizard:** explicit name on PolicyTemplate.linkedAnnexAControls column ([1326ab5](https://github.com/moag1000/Little-ISMS-Helper/commit/1326ab58e74152e6c928796c426f342cafc5f692))
* **policy-wizard:** legal_name no longer trips 30-char tailoring min_length ([79057eb](https://github.com/moag1000/Little-ISMS-Helper/commit/79057eb78525a0ac51a53ca0ef01db5fb74f3ec5))
* **policy-wizard:** Lifecycle per-policy overrides accept empty (= use default) ([ce3739e](https://github.com/moag1000/Little-ISMS-Helper/commit/ce3739e707d1c479868157179a717dc30b4ef6a9))
* **policy-wizard:** register policy-wizard-targeted-pick Stimulus controller ([afc95dc](https://github.com/moag1000/Little-ISMS-Helper/commit/afc95dc957e417628528679b2e2a7f2e5dd91369))
* **policy-wizard:** replace native window.confirm() with Aurora window.faConfirm() in bulk action bar ([1a78b6e](https://github.com/moag1000/Little-ISMS-Helper/commit/1a78b6eda6d1db74c3cd0cd023b3ab1c7d471589))
* **policy-wizard:** resolve cross-domain policy-titles in Lifecycle table + Person-link in Step 4 ([cb4981f](https://github.com/moag1000/Little-ISMS-Helper/commit/cb4981fb7a3bb8d4beca44fcf888fec46f61703f))
* **policy-wizard:** set trans_default_domain inside embed blocks ([0e89109](https://github.com/moag1000/Little-ISMS-Helper/commit/0e89109fd253610c4f6d80ec41c7afc2632e2b8f))
* **policy-wizard:** single-user-tenant bypass in PolicySectionApprovalService ([241b7f1](https://github.com/moag1000/Little-ISMS-Helper/commit/241b7f10d55be84457f40495bb9faaa8ab474024))
* **policy-wizard:** single-user-tenant exception for self-approval prohibition ([b0cd502](https://github.com/moag1000/Little-ISMS-Helper/commit/b0cd50286d1bb752c31fb1d9de9a1a8d841c3db1))
* **policy-wizard:** step.html re-render preserves available_locations + prefilled hints ([759e989](https://github.com/moag1000/Little-ISMS-Helper/commit/759e9894aaa822d2bc86c27ff4a0f499ebc1858b))
* **policy-wizard:** translation key collisions blocking Step 2 + diff/drift UI ([e5c1c7e](https://github.com/moag1000/Little-ISMS-Helper/commit/e5c1c7e800973778d7e2b4dd57b2ac3e8b32dd26))
* **policy-wizard:** unauthored-template guard + backfill command ([18b1533](https://github.com/moag1000/Little-ISMS-Helper/commit/18b153301df542aa0f43ff639ab72c104fb8cca9))
* **policy-wizard:** use -message-param for ui-actions confirm dialog ([e5e768a](https://github.com/moag1000/Little-ISMS-Helper/commit/e5e768accb85dddfe0297d5d8e6a8b7b6f21ec79))
* **quality:** macro-scope checker v3 — stack-based embed-scope tracking ([962ff8d](https://github.com/moag1000/Little-ISMS-Helper/commit/962ff8dc56c9363a076ebd2301c284a6f5265659))
* **quality:** rewrite macro-scope checker to detect only embed-block issues ([6a94f17](https://github.com/moag1000/Little-ISMS-Helper/commit/6a94f17387a6a7892a4e06f67eec855a675a302a))
* **quick-fix:** auto-chain reconcile after migrations apply ([98db972](https://github.com/moag1000/Little-ISMS-Helper/commit/98db9724a81150816e5be0a3b7d92f00dd34132b))
* **quick-fix:** auto-reconcile also on /quick-fix path ([dbb84f3](https://github.com/moag1000/Little-ISMS-Helper/commit/dbb84f3075144f2dd036f1c3c051f8da41687eb1))
* **quick-fix:** auto-reconcile also on /quick-fix path ([f7c1ff9](https://github.com/moag1000/Little-ISMS-Helper/commit/f7c1ff97a21eb984f020668a27e0d3a564f3152a))
* **quick-fix:** destructive reconcile via UI mit Risk-Acceptance-Checkbox ([92379db](https://github.com/moag1000/Little-ISMS-Helper/commit/92379db3956d3e8ce4242fe3dda3534c0e8d7444))
* **quick-fix:** drain pending migrations before reconcile in auto-fix path ([c1ed378](https://github.com/moag1000/Little-ISMS-Helper/commit/c1ed37814f2cae2c921255d3bd6ff155f4dde561))
* **quick-fix:** pending-migrations FS-source sichtbar + auto-recovery bei Block ([426356d](https://github.com/moag1000/Little-ISMS-Helper/commit/426356dd33445cfe27b43bc112516953bf0f8498))
* **reports:** _fa_progress macro-import-scope in executive one-pager ([04e3d8a](https://github.com/moag1000/Little-ISMS-Helper/commit/04e3d8a9b0c0117763fba56fe79b55da148543ed))
* **reports:** convert _fa_pdf_locale_switch macro→include for nested-block scope ([c9f5883](https://github.com/moag1000/Little-ISMS-Helper/commit/c9f5883c2bc8a3f7d9da2289b7f019a8a09b06e3))
* **reports:** risk-trend PDF templates use correct service-shape keys ([48053df](https://github.com/moag1000/Little-ISMS-Helper/commit/48053dfe1c72c90809ae7c98e38bd803b818d1be))
* **runtime:** 4 production bugs found post-3.5 polish ([bbaaeba](https://github.com/moag1000/Little-ISMS-Helper/commit/bbaaeba4c6db18f49f1a015c7093cb98795842ac))
* **security:** File-Upload-Hardening alle 5 FileTypes — MIME+Size+SecurityService (T31.1.6) ([7709bba](https://github.com/moag1000/Little-ISMS-Helper/commit/7709bba42f4f83efeec3a5eb5219cff7fe6c31c4))
* **security:** SSRF-protection NoInternalIp constraint for URL fields (T31.1.5) ([8740412](https://github.com/moag1000/Little-ISMS-Helper/commit/8740412b8c562a3e3aaf5765d37c146c31157e8b))
* **security:** structured-entity for Training-Auto-Listener (V3 W2-C3) ([627c21a](https://github.com/moag1000/Little-ISMS-Helper/commit/627c21a4469470628b838c5087698ba3e2f904cd))
* **security:** tenant-scope + DSR-PII restriction in MyDayAggregator (V3 W2-C2) ([845af6b](https://github.com/moag1000/Little-ISMS-Helper/commit/845af6b0cf4d9e2ed30e9ab596125e58dbc0c198))
* **security:** tenant-scope ActivityFeed + repos (V3 W2-C1) ([3e22388](https://github.com/moag1000/Little-ISMS-Helper/commit/3e223880a1f695daf81baf6330b6e1140641712e))
* **security:** V4-LB-9 — CommentController Entity-Existence + Tenant-Validation ([27aed58](https://github.com/moag1000/Little-ISMS-Helper/commit/27aed58a6102b6eed0c090be7cbde260f1415bf2))
* **setup:** unify existing_frameworks layout to setup/_layout (Audit-V3 A7) ([2b27227](https://github.com/moag1000/Little-ISMS-Helper/commit/2b272276dba9ecab5b442205fb8c6b0bdbb6a4fe))
* **stimulus:** register Sprint 1+3 controllers explicitly ([34da7d1](https://github.com/moag1000/Little-ISMS-Helper/commit/34da7d1e00ca410bdcb4b25ad81df6f5be0a4944))
* **test:** AdminPolicyStyleControllerTest assert structure not localized title ([cd000e9](https://github.com/moag1000/Little-ISMS-Helper/commit/cd000e96597788936759cbbbe5dddd95593bac72))
* **test:** AdminPolicyStyleControllerTest CI-resilience ([e5e1e70](https://github.com/moag1000/Little-ISMS-Helper/commit/e5e1e70a4c2d5949d6b27db870fee08c57d479b4))
* **test:** CertificationBundleControllerKonzernTest CSRF + role fixture ([2e0a713](https://github.com/moag1000/Little-ISMS-Helper/commit/2e0a713dad17c3137909e73c8b61ecc928fa0641))
* **tests:** add UrlGenerator mock to ActivityFeedTest setUp (CI-fix) ([6c5bfa0](https://github.com/moag1000/Little-ISMS-Helper/commit/6c5bfa0beb5da80ff8840122feff89ee1d9b79c4))
* **tests:** close 3 CI failures from PR [#319](https://github.com/moag1000/Little-ISMS-Helper/issues/319) merge ([de2a9c5](https://github.com/moag1000/Little-ISMS-Helper/commit/de2a9c54038da850b1f8db2a1366efb10a3171e4))
* **tests:** drop second unreachable form-field 'impactJustification' in RiskControllerTest ([15ecf8b](https://github.com/moag1000/Little-ISMS-Helper/commit/15ecf8badba3f3eec5d4615ef984f2797730e839))
* **tests:** repair CI failures from T31 module-gating + audit-tests (T31.CI) ([d913238](https://github.com/moag1000/Little-ISMS-Helper/commit/d9132384ced18c91ab5d77ca5278a86a2bc22263))
* **tests:** repair CI failures from T31 module-gating + audit-tests (T31.CI) ([b71df5a](https://github.com/moag1000/Little-ISMS-Helper/commit/b71df5a69c9d01f80c7e9ba59331bac311b81610))
* **tests:** resolve 2 CI failures from run 25570470688 ([1921b3c](https://github.com/moag1000/Little-ISMS-Helper/commit/1921b3c4dca35685afa977d720a00c0fe1643c1a))
* **tests:** resolve 2 CI failures from run 25570470688 ([c6579be](https://github.com/moag1000/Little-ISMS-Helper/commit/c6579be72e16232391843b5fc4e9ca7604c8a33f))
* **translations:** add 49 missing keys + fix 32 quality issues ([48afd68](https://github.com/moag1000/Little-ISMS-Helper/commit/48afd689ba301c0e83826802139c5f44439128d7))
* **translations:** certification_bundle DE — '100 %%' → '100 %' (followup zu 80612ee4) ([0b92a62](https://github.com/moag1000/Little-ISMS-Helper/commit/0b92a62e4316b324682beafb5d95cd69ac8b5329))
* **translations:** close all 90 missing translation keys (V3 W3-i18n-90) ([2d20240](https://github.com/moag1000/Little-ISMS-Helper/commit/2d202401f7e7c1e3a1ce9e3b1921b4e0e98ff527))
* **translations:** quote YAML 1.1 boolean keys in monitoring.{de,en}.yaml ([150f26f](https://github.com/moag1000/Little-ISMS-Helper/commit/150f26f220e5a4b8865e458da6110c4ee1fc0a0d))
* **translations:** replace hardcoded text in user-facing templates ([22692ab](https://github.com/moag1000/Little-ISMS-Helper/commit/22692abccf85905d69b8e6735a7dbc825c986d0c))
* **translations:** T31 validator-keys in 'validators' domain (Self-Review-Fix) ([80dfed8](https://github.com/moag1000/Little-ISMS-Helper/commit/80dfed8d6d5310c2ced24b63d6a3eda2ab6a24a2))
* **turbo:** clear progress bar after Content-Disposition: attachment ([688e433](https://github.com/moag1000/Little-ISMS-Helper/commit/688e4339973eddcef91ad96e6365dba3a9a3598e))
* Twig nested-comment leak in _fa_table macro + consent form translations ([290dfe5](https://github.com/moag1000/Little-ISMS-Helper/commit/290dfe5f0819e9ed3da42ee389512235d13f9dd3))
* **ui:** _fa_progress import in dpia/index nested embed-table-body ([b361a80](https://github.com/moag1000/Little-ISMS-Helper/commit/b361a80e98ea960e5d1d1393b880b5db53b9c3e8))
* **ui:** _fa_progress import in soa/index embed-table-body ([8797122](https://github.com/moag1000/Little-ISMS-Helper/commit/8797122cda4bf7586764715c8ca2a9f83fb73893))
* **ui:** _fa_progress import-scope — move into block fuer extends-templates ([075e36a](https://github.com/moag1000/Little-ISMS-Helper/commit/075e36a4a1998c596d511355bdb9c87821b4451c))
* **ui:** Aurora token tiles — number contrast in dashboards/analytics ([c499642](https://github.com/moag1000/Little-ISMS-Helper/commit/c49964295dee5e0c8d23f42ab8696676ad16e8ff))
* **ui:** cap mapping-strength bar at 100% — show actual % via text+tooltip ([b2c16f2](https://github.com/moag1000/Little-ISMS-Helper/commit/b2c16f216fca315706307929087b5fc7e311cc67))
* **ui:** macro import-scope — embed-block fixes fuer _fa_progress + _isms_approval_stages ([765849b](https://github.com/moag1000/Little-ISMS-Helper/commit/765849b728cac305939f8528766e30ae84074b94))
* **ui:** macro-imports in nested/sibling-embed-blocks — Bulk-Sweep 4 Templates ([be18e96](https://github.com/moag1000/Little-ISMS-Helper/commit/be18e96eac729ee401ae10cca91714d6e69f6a13))
* **ui:** page-header gradient line strikethrough + 2nd bait-bug ([04606a7](https://github.com/moag1000/Little-ISMS-Helper/commit/04606a747b3da3b490f9d00f97a77d64c9b60b31))
* **ui:** page-header gradient stripe behind content via isolation+z-index ([26e7b94](https://github.com/moag1000/Little-ISMS-Helper/commit/26e7b943b03e6cee28b4e930b61fc94d6c823446))
* **ui:** V4 release-blockers — risk/show card-header anti-pattern + mega-menu gating ([7aefb59](https://github.com/moag1000/Little-ISMS-Helper/commit/7aefb598151e078003fb565ba133693d66a3fbf0))
* **ui:** V4-FP-1 — Toast-System Migration auf canonical _fa_toast ([61c0903](https://github.com/moag1000/Little-ISMS-Helper/commit/61c090387d328216fbafdbedb5ce0ce480290af3))
* **ui:** V4-LB-2 + V4-FP-2 — 4 Persona-Dashboards in Mega-Menu + i18n-Lücken ([47f3f13](https://github.com/moag1000/Little-ISMS-Helper/commit/47f3f134110cba9cdc121be82d167d4d3fa9f8a1))
* **validator:** add requireTld to Url assertions on CrisisTeam + Patch ([637144c](https://github.com/moag1000/Little-ISMS-Helper/commit/637144c0eef09035599c7a55713770142d4b6c42))
* **validator:** add requireTld to Url assertions on CrisisTeam + Patch ([85d74e7](https://github.com/moag1000/Little-ISMS-Helper/commit/85d74e7c222c07e3cf63f184deee3a0b00fa37dc))
* **wizard-result + dashboards:** body-snippet on result page + Document.getTitle() callsites ([63bf8fe](https://github.com/moag1000/Little-ISMS-Helper/commit/63bf8feb1cfda1c2f7aaf29eb67b0ceeb4348549))
* **wizard:** per-wizard slot for snapshots + dynamic compare-FW + real PDF (V3 W2-M1/M6/M7) ([f8c08b4](https://github.com/moag1000/Little-ISMS-Helper/commit/f8c08b4d336c6d7e88ff4fabd4a114bf97bc740c))
* zusaetzlicher import direkt im table_body-block. ([8797122](https://github.com/moag1000/Little-ISMS-Helper/commit/8797122cda4bf7586764715c8ca2a9f83fb73893))


### Changed

* **admin:** Aurora-V4 migration of remaining 15 sub-templates (Sprint 4) ([b8eca59](https://github.com/moag1000/Little-ISMS-Helper/commit/b8eca59a45760f6c1223927b5a58b6230efd8b20))
* **admin:** Aurora-V4 migration of top-frequented subs (Sprint 2) ([51270b3](https://github.com/moag1000/Little-ISMS-Helper/commit/51270b3487175769f6129097d51345047b39401c))
* **admin:** finish FairyAurora v4 migration of admin sub-templates (Sprint 6) ([4c1164d](https://github.com/moag1000/Little-ISMS-Helper/commit/4c1164d76cd1ad9dc26c67bd8c13506ff9f1bab3))
* **admin:** migrate 2 straggler templates to Aurora v4 ([12a4ff2](https://github.com/moag1000/Little-ISMS-Helper/commit/12a4ff2ad097d01d9d572c29f427ed69cb0b5459))
* **bulk:** mass-migrate 8 list-templates to canonical bulk-action-bar markup ([a4feba8](https://github.com/moag1000/Little-ISMS-Helper/commit/a4feba8f833ca046a1dbf665b875e869f2491452))
* **cert-bundle:** aurora UI + multi-FW dropdown (V3 W2-H1) ([12c7961](https://github.com/moag1000/Little-ISMS-Helper/commit/12c7961ba6012a5a5d623588d501c4415cdca3a0))
* **cert-bundle:** variant:'kpi' → _fa_feature_card finally (V3 W3-Aurora cert-bundle) ([8d48dc0](https://github.com/moag1000/Little-ISMS-Helper/commit/8d48dc09fdc8b0c2c0fe1e4d2dab712a7b667e2e))
* **compliance-wizard:** aurora-pattern for 4 wizard pages (V3 B2) ([6a69dd5](https://github.com/moag1000/Little-ISMS-Helper/commit/6a69dd5feea644ee01e4ebe467db03ab35853435))
* **design-system:** R1 follow-up — migrate 21 show-pages to _fa_page_header ([58d97ed](https://github.com/moag1000/Little-ISMS-Helper/commit/58d97ed0662a632204f50e47920738b0fc50519a))
* **design-system:** R1 migrate 12 show templates to _fa_page_header ([7fce5b1](https://github.com/moag1000/Little-ISMS-Helper/commit/7fce5b11025a7503ede3c8ddedd6818a73ee109f))
* **design-system:** R2 mass-replace bootstrap badge bg-* with fa-status-pill ([647f123](https://github.com/moag1000/Little-ISMS-Helper/commit/647f12325e16ef2af6b9c1c6d9769be57f64dc0d))
* **design-system:** R5 migrate raw bootstrap alerts to fa-alert ([395bced](https://github.com/moag1000/Little-ISMS-Helper/commit/395bced5bb614ebbc0733713a67f1233a125c879))
* **design-system:** R6 migrate raw bootstrap btn-* to fa-cyber-btn ([7a7a1e4](https://github.com/moag1000/Little-ISMS-Helper/commit/7a7a1e4c9d70eb70b80354d09941259791b9db20))
* **design-system:** R8+R9 admin-layout cleanup + command-palette icon-migration ([20439c4](https://github.com/moag1000/Little-ISMS-Helper/commit/20439c4496e25085c8a9f1594c829fc10680ae6e))
* **design-system:** Tier-1 Quick-Wins from Audit V2 (Q1-Q8) ([1ff064e](https://github.com/moag1000/Little-ISMS-Helper/commit/1ff064ef0ffbbdc4958849d0948bdef232daf05d))
* **design-system:** Tier-1 Quick-Wins from Audit V2 (Q1-Q8) ([bfa5feb](https://github.com/moag1000/Little-ISMS-Helper/commit/bfa5feb965532882ee5732796fd1f04ae5e89895))
* **design-system:** Tier-2 Foundation — F1+F2+F5+F6+F7 ([967a71a](https://github.com/moag1000/Little-ISMS-Helper/commit/967a71ad301bd055b15d6afe3c63fe6d14f30536))
* **filters:** aurora filter-chips in 5 list-pages (V3 C5) ([7edd10b](https://github.com/moag1000/Little-ISMS-Helper/commit/7edd10b3f120b006960d95135eb42b2a25be134e))
* **forms:** add fa-cyber-input__field class to hand-rolled filter inputs ([2f4fcfe](https://github.com/moag1000/Little-ISMS-Helper/commit/2f4fcfe65b08032a7bde685a7b3a5d26c04466b5))
* **forms:** convert per-field form_widget to form_row for Aurora-frame coverage ([17edd2a](https://github.com/moag1000/Little-ISMS-Helper/commit/17edd2a8bd7a38c36d6d407a3e0b81441f43082e))
* **forms:** drop bootstrap-theme overrides, route via Aurora cyber-input ([27b8608](https://github.com/moag1000/Little-ISMS-Helper/commit/27b8608175c797779f0251620aabc8cb218d590f))
* **forms:** migrate 71 edit/new templates to _fa_page_header (V3 B1) ([c40214f](https://github.com/moag1000/Little-ISMS-Helper/commit/c40214fcd44ff605d3328ea0d8d148001cbaf691))
* **forms:** re-apply Tier-1 Q3+Q5 cleanup after parallel-agent revert ([e5a3fba](https://github.com/moag1000/Little-ISMS-Helper/commit/e5a3fbaae9cd29213ff0860a76162ed9fac436fa))
* **forms:** wrap hand-rolled inputs in fa-cyber-field macro (Phase 2) ([fc6e13a](https://github.com/moag1000/Little-ISMS-Helper/commit/fc6e13af00342216dd38e39f55e3d06d41efd80f))
* **home:** aurora hero + first-hour-checklist (V3 W2-M9) ([8f3126a](https://github.com/moag1000/Little-ISMS-Helper/commit/8f3126ae70e561b85c7ec0f84a10621560b6ea50))
* **icons:** unify bi-* → fa-icon--* (Tier-4 D.H1) ([e71c038](https://github.com/moag1000/Little-ISMS-Helper/commit/e71c0380772a3ade8b59c4f0f08168051e8c5f2d))
* **industry-preset:** aurora cards (V3 W3-Aurora industry-preset) ([49ef54d](https://github.com/moag1000/Little-ISMS-Helper/commit/49ef54d2a7ffcc59a411c4292f22fc0c5e255d86))
* **isms:** extract approval-stages to macro (V3 W2-M4) ([d520130](https://github.com/moag1000/Little-ISMS-Helper/commit/d520130de0900190027d81b4c63dc58047dbe7d8))
* **layout:** remove header-alva (V3 W2-H7) ([693491a](https://github.com/moag1000/Little-ISMS-Helper/commit/693491a97a197f0e8f009ea6f98ec8156c14dbb0))
* **lists:** migrate 9 list-pages to fa-table Aurora pattern (V3 W3-UX-Rollout) ([034ad00](https://github.com/moag1000/Little-ISMS-Helper/commit/034ad00609e18daf0ac7a774ad38d42b7a9d662c))
* **nav:** admin mega-menu IA-restructure (V3 W2-H6) ([6d09514](https://github.com/moag1000/Little-ISMS-Helper/commit/6d095144a4d57584bdf49adf1295200f7a2b22a0))
* **nav:** mega-menu IA restructure compliance panel (V3 B7) ([6e31900](https://github.com/moag1000/Little-ISMS-Helper/commit/6e31900549e8668eea554fa5ec0a99ac93de7b75))
* **nav:** mega-menu item-level module-gating coverage 8 → 64/82 ([4f265ed](https://github.com/moag1000/Little-ISMS-Helper/commit/4f265ed09ef2fbbc6a6514af04263e38093f32a3))
* **policy_wizard:** wrap hand-rolled inputs in fa-cyber-field macro (Phase 1) ([fdd79d7](https://github.com/moag1000/Little-ISMS-Helper/commit/fdd79d7f4e214b24b188097ff10fe55ea3586b0f))
* **policy-wizard:** full DS-compliance audit on 12 wizard step partials ([61afb85](https://github.com/moag1000/Little-ISMS-Helper/commit/61afb85a0a0b35564443b0ac1ed5ac405e6eb4c8))
* **risk:** V4-LB-3 — RiskSkeletonListener Idempotenz via FK statt Title-Match ([8b8aae9](https://github.com/moag1000/Little-ISMS-Helper/commit/8b8aae939aa5faec8ee434fc73047cdd7d4ee642))
* **role-mgmt:** Aurora-V4 migration of role_management templates (Sprint 5) ([bad24f1](https://github.com/moag1000/Little-ISMS-Helper/commit/bad24f1d6490c5ff22f40cf467dffc20c3da614c))
* **styles:** drop Bootstrap-hex-fallback in policy-wizard-mobile.css ([02d10f0](https://github.com/moag1000/Little-ISMS-Helper/commit/02d10f008b95ac527254a7aae2eda0a1ce179699))
* **styles:** map all hardcoded z-index to tokens ([4f0ec3c](https://github.com/moag1000/Little-ISMS-Helper/commit/4f0ec3ca73bfc7cebe4baa90bef6be68373103e7))
* **styles:** map black-rgba shadows to --shadow-* tokens (Audit M1+M2) ([6898fe7](https://github.com/moag1000/Little-ISMS-Helper/commit/6898fe7495d37624ba9fc9935f53310d83837414))
* **styles:** map hardcoded border-radius to --r-* tokens (C8 Audit M3-extended) ([fb9d965](https://github.com/moag1000/Little-ISMS-Helper/commit/fb9d965fb3a900a0617410d09ec9a711443d7b31))
* **styles:** replace 'color: white' with --on-* tokens (Audit M3-extended) ([65dab12](https://github.com/moag1000/Little-ISMS-Helper/commit/65dab12d29a77cc0b98abf26697c96152df13969))
* **styles:** rgba() raw colors → Aurora token references ([8f093bb](https://github.com/moag1000/Little-ISMS-Helper/commit/8f093bba3ae46ea890373217f60ccf333c146dd1))
* **tables:** migrate asset/show, management_reports, compliance_wizard/compare to fa-table ([f1f33b5](https://github.com/moag1000/Little-ISMS-Helper/commit/f1f33b5e7c7b21a02e723e2cb6674c24fe2a7153))
* **tables:** migrate bsi_grundschutz_check/index to fa-table Aurora API ([b84070e](https://github.com/moag1000/Little-ISMS-Helper/commit/b84070e0a45ec92d8699be60c618ef21f2327324))
* **tables:** migrate compliance/* to fa-table Aurora API ([f441aae](https://github.com/moag1000/Little-ISMS-Helper/commit/f441aae5e80a505b4fded28afd0318f76ef9b92e))
* **tables:** migrate dashboards/compliance_manager and cm_heatmap_drill to fa-table ([f238d0d](https://github.com/moag1000/Little-ISMS-Helper/commit/f238d0d481759ec55a7214b098e1461b3fa48d35))
* **tables:** migrate management_reports/{compliance,risk} and compliance_wizard/{history,_gap_report_body} to fa-table ([35b1f12](https://github.com/moag1000/Little-ISMS-Helper/commit/35b1f1260ab0593a27fc8beb6f8cba406b27eab9))
* **tables:** migrate policy_wizard/konzern_rollup and compliance_wizard/category to fa-table ([051a551](https://github.com/moag1000/Little-ISMS-Helper/commit/051a55166424c2db4da1b35ea8d9d9b7bc639b6f))
* **tables:** migrate soa_snapshot, industry_baseline, bcm_insights, mris, supplier to fa-table ([e7bb465](https://github.com/moag1000/Little-ISMS-Helper/commit/e7bb465ead2ac63c7b1eaf1a7ae6752fe7be70a5))
* **templates:** migrate dashboards to Aurora-first patterns (Audit M6) ([073385f](https://github.com/moag1000/Little-ISMS-Helper/commit/073385fd54e5bbab12b2f519867ba2fb16aa7de1))
* **ui:** _fa_progress Adoption Batch 1 — 13 templates migriert ([7cabb9a](https://github.com/moag1000/Little-ISMS-Helper/commit/7cabb9abb8f0b77a090b223e4ec91245cb124a35))
* **ui:** _fa_progress Adoption Batch 2 — 14 templates migriert ([f5eec0d](https://github.com/moag1000/Little-ISMS-Helper/commit/f5eec0dc0c5691b0669c5e09403e3620a15dd4e6))
* **ui:** _fa_progress Adoption Batch 3 — 28 templates migriert ([43c71a0](https://github.com/moag1000/Little-ISMS-Helper/commit/43c71a075ca7c38073bb03f058344b98a20ea320))
* **ui:** _fa_table Adoption Batch 1 — 17 templates migriert ([d95063f](https://github.com/moag1000/Little-ISMS-Helper/commit/d95063fabc9c16875a52be9453148b163e894a52))
* **ui:** card-header bg-* Anti-Pattern Cleanup Batch 1 ([0fd5c9e](https://github.com/moag1000/Little-ISMS-Helper/commit/0fd5c9ebe73b3d035570918cd8390fb111402e25))
* **ui:** card-header bg-* Anti-Pattern Cleanup Batch 2 ([3458ccc](https://github.com/moag1000/Little-ISMS-Helper/commit/3458ccce3abecf7e2d84ee17564436475784eb06))
* **ui:** card-header bg-* Anti-Pattern Cleanup Batch 3 ([d330956](https://github.com/moag1000/Little-ISMS-Helper/commit/d330956070f4eda07a7c4f2b8f03465400818139))
* **ui:** card-header bg-* Anti-Pattern Cleanup Batch 4 ([c63cb29](https://github.com/moag1000/Little-ISMS-Helper/commit/c63cb295fec894d4377769bc09fa9774e167d2ed))
* **ui:** Phase-3 fa-table migration — Batch 2 (admin tenant/import/preset) ([042a512](https://github.com/moag1000/Little-ISMS-Helper/commit/042a512577195a3e40ab785b69b9e3013fc3ec96))
* **ui:** Phase-3 fa-table migration — Batch 3 (analytics, help, bcm, ai-agent) ([9740be4](https://github.com/moag1000/Little-ISMS-Helper/commit/9740be4c10855066434d25157545d740914d5a6d))
* **ui:** replace native alert/confirm with Aurora helpers across 31 files ([682bea6](https://github.com/moag1000/Little-ISMS-Helper/commit/682bea6a39fb02d620bb1ac612ab8f6f363c6bbc))
* **ux:** polish KL-6 help-section + alert/btn cleanup (V3 W2-L1+L3) ([b816605](https://github.com/moag1000/Little-ISMS-Helper/commit/b816605dced552ba6d33c2e4d5fa82d273799dc5))


### Documentation

* add CITATION.cff + SECURITY.md for academic + responsible-disclosure discoverability ([b085bc8](https://github.com/moag1000/Little-ISMS-Helper/commit/b085bc8ca079b9f56c49bc04149fa4130d57cfe9))
* add CITATION.cff + SECURITY.md for academic + responsible-disclosure discoverability ([b2e4c90](https://github.com/moag1000/Little-ISMS-Helper/commit/b2e4c90b01cd67c5fdadaefdbbb422b615442b39))
* **claude.md:** drop obsolete Policy-Wizard Branch Protocol section ([fc535d8](https://github.com/moag1000/Little-ISMS-Helper/commit/fc535d8e12c4584bce88c985f3fa22db4f260a7d))
* **claude.md:** v3.5 update — Persona-Roles + Aurora macros + Twig embed-scope pitfall + QuickFix-UI ([30c3648](https://github.com/moag1000/Little-ISMS-Helper/commit/30c3648ff896bbc432fee5996e0e1f495e9a28df))
* **compliance:** v3.5 update — GDPR/DORA/NIS2/BSI normative Aenderungen ([463dc33](https://github.com/moag1000/Little-ISMS-Helper/commit/463dc336c3caf74f3b691a52f46988fbd286a690))
* **design:** cheatsheet update — actual r-token values + new tokens + Z-Index-Stack ([224320a](https://github.com/moag1000/Little-ISMS-Helper/commit/224320a773a2ac236fa219c193afa22c8fe8bf10))
* **form-theme:** refresh fa_cyber_input.html.twig docblock post audit ([fa9ccf3](https://github.com/moag1000/Little-ISMS-Helper/commit/fa9ccf354808da22f46c2f0f02f33dbc337e8696))
* **forms:** standards section in CONTRIBUTING.md + comment in twig.yaml ([f7dc3c2](https://github.com/moag1000/Little-ISMS-Helper/commit/f7dc3c2b5d0a77c27fd98197287dcaa4c3f01041))
* **my-day:** retroactive attribution — V4-LB-1 MyDayAggregator buckets ([d7e912d](https://github.com/moag1000/Little-ISMS-Helper/commit/d7e912dbf669bdda3a3ed93b8f8b9f7d37bdb840))
* **plan:** aurora v4 + z-index big-bang implementation plan ([15dc0a7](https://github.com/moag1000/Little-ISMS-Helper/commit/15dc0a7fa0eaa268201206b38aa24a40bc1eaa10))
* **plan:** feature-roadmap v2 — UX + CM + ISMS specialist reviews integrated ([8f9f539](https://github.com/moag1000/Little-ISMS-Helper/commit/8f9f539e5d00c4daabaf8a442e559eb6584e7aef))
* **plan:** feature-roadmap v2 — UX + CM + ISMS specialist reviews integrated ([20da9df](https://github.com/moag1000/Little-ISMS-Helper/commit/20da9df2cc02b84934ea3d5926921ac6398063b4))
* **plan:** feature-roadmap v3 — integration-completeness checklist per feature ([93087cb](https://github.com/moag1000/Little-ISMS-Helper/commit/93087cb6e3684278a57bcc11967d8dc1476d66dd))
* **plan:** feature-roadmap v3 — integration-completeness checklist per feature ([a678693](https://github.com/moag1000/Little-ISMS-Helper/commit/a678693f50dd71c3898e9d0be00901473079791c))
* **plan:** feature-roadmap v4 — EU/DACH competitor scan integration ([da9fcb9](https://github.com/moag1000/Little-ISMS-Helper/commit/da9fcb93f9855f4224e0cbccc3895e2d698a1e72))
* **plan:** feature-roadmap v4 — EU/DACH competitor scan integration ([f6fc48e](https://github.com/moag1000/Little-ISMS-Helper/commit/f6fc48e1a1a06a2ac1c7dff4af895b857cccbaf0))
* **plan:** formtype norm-gating rollout — 6 specialists konsolidiert ([31cd821](https://github.com/moag1000/Little-ISMS-Helper/commit/31cd821308f5d36582f58f05a79c3b046a0c7f23))
* **plan:** formtype norm-gating rollout — 6 specialists konsolidiert ([995f3da](https://github.com/moag1000/Little-ISMS-Helper/commit/995f3da3c636a0b847676b92e8133a715f2eec18))
* **plan:** plan v2 — Task 0.5 stylelint-cleanup, slate-overlay no-touch, severity-mapping ([465abb0](https://github.com/moag1000/Little-ISMS-Helper/commit/465abb04a99153382b1f66bb2a9272e8f726ef9d))
* **policy-wizard:** expand approval workflow — bulk + review-fast-path ([d7ef7f1](https://github.com/moag1000/Little-ISMS-Helper/commit/d7ef7f1b317bcef4eb1bffe4355e5cede6fe6fe9))
* **policy-wizard:** expand approval workflow — bulk + review-fast-path ([e7addfa](https://github.com/moag1000/Little-ISMS-Helper/commit/e7addfa352c7470bb345aa84a4f021cd4a997e93))
* **policy-wizard:** Phase 1 specialist input — ISO/BSI/DORA/BCM (3795 lines) ([d69e1c6](https://github.com/moag1000/Little-ISMS-Helper/commit/d69e1c6e0c8c845fa2a96fa073a59a7128049b25))
* **policy-wizard:** Phase 1 specialist input — ISO/BSI/DORA/BCM (3795 lines) ([d7fe1ed](https://github.com/moag1000/Little-ISMS-Helper/commit/d7fe1ed3f4ebb83cc41cf5652b7ce1753c635ab3))
* **policy-wizard:** Phase 1-E DPO input + section-vs-standalone rework ([ab2990a](https://github.com/moag1000/Little-ISMS-Helper/commit/ab2990aaa11e944af826df1e1067412da7e4465c))
* **policy-wizard:** Phase 1-E DPO input + section-vs-standalone rework ([db702b6](https://github.com/moag1000/Little-ISMS-Helper/commit/db702b6f8e3a0b8d3393303c2213c9308a79057f))
* **policy-wizard:** Phase 2 architectural synthesis (589 lines) ([d9e7879](https://github.com/moag1000/Little-ISMS-Helper/commit/d9e7879930046113d2b0485eac66ade0db23a146))
* **policy-wizard:** Phase 2 architectural synthesis (589 lines) ([5677b9d](https://github.com/moag1000/Little-ISMS-Helper/commit/5677b9d824e1166607f77e72b14c4c18f082fbfc))
* **policy-wizard:** Phase 3 — 9 persona reviews (2902 lines) ([7b24341](https://github.com/moag1000/Little-ISMS-Helper/commit/7b2434190356631bb8a49b7b39553f76f23b5917))
* **policy-wizard:** Phase 3 — 9 persona reviews (2902 lines) ([e30b46b](https://github.com/moag1000/Little-ISMS-Helper/commit/e30b46b2dbfff2c01f8b3edcabc3feab55d32eaa))
* **policy-wizard:** Phase 4-A + 4-B — architecture P1 fixes + DPO Matrix v2 ([0e9b62b](https://github.com/moag1000/Little-ISMS-Helper/commit/0e9b62b2ed1879d25b8759da82e2f3c405232fd2))
* **policy-wizard:** Phase 4-A + 4-B — architecture P1 fixes + DPO Matrix v2 ([b58ecf6](https://github.com/moag1000/Little-ISMS-Helper/commit/b58ecf6d50bb55c20e3453e186ac6660d7b5ee70))
* **policy-wizard:** Phase 4-C sprint reconciliation (707 lines) ([44c9f57](https://github.com/moag1000/Little-ISMS-Helper/commit/44c9f5707aa49af32cb04dd80589927a8aa1cf09))
* **policy-wizard:** Phase 4-C sprint reconciliation (707 lines) ([9a69247](https://github.com/moag1000/Little-ISMS-Helper/commit/9a69247d078fee410eeb1585a2e3b094e8d13481))
* **policy-wizard:** Phase 4-D — sign-off block + doc-count cross-walk + CLAUDE.md updates ([0a15852](https://github.com/moag1000/Little-ISMS-Helper/commit/0a158527cd8ce33b5dded791f17dcc2a2ea43c6c))
* **policy-wizard:** Phase 4-D — sign-off block + doc-count cross-walk + CLAUDE.md updates ([bb43c6e](https://github.com/moag1000/Little-ISMS-Helper/commit/bb43c6e87ad00ad644ab7ed0ebddbede7dbf89aa))
* **policy-wizard:** Phase 5 — plan index + executive summary ([ff90e77](https://github.com/moag1000/Little-ISMS-Helper/commit/ff90e77c0ca93bf984778ee9046e64eeabf776cf))
* **policy-wizard:** Phase 5 — plan index + executive summary ([ff82166](https://github.com/moag1000/Little-ISMS-Helper/commit/ff821664bf9ee1387e5b07d167ea42540736803a))
* **readme:** add Honorable Mentions section for OSS ISMS landscape ([830318c](https://github.com/moag1000/Little-ISMS-Helper/commit/830318c1a8956ea20b43aa5d1b2be22ccdca737e))
* **readme:** update setup-wizard step count + my-day inbox (V3 W2-L5) ([317316e](https://github.com/moag1000/Little-ISMS-Helper/commit/317316e8ffc35631862a0ff509db8125f98ab98b))
* **sichtwechsel:** expand catalog with Audit-V3 + policy-wizard screens ([2eb7778](https://github.com/moag1000/Little-ISMS-Helper/commit/2eb7778d036bbe5e44f4014a4abf58600b01ff2c))
* **sichtwechsel:** hide Symfony web debug toolbar in screenshots ([4462c03](https://github.com/moag1000/Little-ISMS-Helper/commit/4462c033d04ae1f2b13559be892fc64201dc54ea))
* **sichtwechsel:** persona walkthroughs absorb V3/policy-wizard screens ([cde8147](https://github.com/moag1000/Little-ISMS-Helper/commit/cde81475869ee9470e6430d49ee9829ddec8a308))
* **sichtwechsel:** persona-driven screenshot walkthroughs + Quickstart ([fac8ca6](https://github.com/moag1000/Little-ISMS-Helper/commit/fac8ca6a0297f81f2ac6649c8e3216f22df65c09))
* **sichtwechsel:** re-capture screenshots with sample data ([84672e2](https://github.com/moag1000/Little-ISMS-Helper/commit/84672e205a6deaa5c2b25eaddace1a3513c8062c))
* **sichtwechsel:** redact DB user, dedupe per persona, expand catalog ([c4e44e0](https://github.com/moag1000/Little-ISMS-Helper/commit/c4e44e00c1490ac28f78dd411ece887224887cf7))
* **spec:** aurora v4 + z-index big-bang refactor design ([ccea358](https://github.com/moag1000/Little-ISMS-Helper/commit/ccea358f9162fddfd1c5bebb86894dd841d486ab))
* **spec:** aurora v4 spec v3 — slate-overlay exclusion + severity-mapping + C0.5 ([75bca9e](https://github.com/moag1000/Little-ISMS-Helper/commit/75bca9e9b7a72b45bd1b04fd690b0a6b3dbf76f4))
* **spec:** tighten aurora v4 big-bang spec (v2) ([0eacab8](https://github.com/moag1000/Little-ISMS-Helper/commit/0eacab8bd8b9b4016454e2586c8b360e084bd0a5))
* **t31:** FormType-Norm-Gating-Rollout — comprehensive documentation (T31.D) ([3a95410](https://github.com/moag1000/Little-ISMS-Helper/commit/3a95410c75666c3f5221ed61f3e99f3ec620e7dd))
* **v3.5:** user-guide + audit-trail docs update fuer v3.5 release ([ccd3774](https://github.com/moag1000/Little-ISMS-Helper/commit/ccd377473fb9824d63237f4905c68f110d72726f))

## [3.4.0](https://github.com/moag1000/Little-ISMS-Helper/compare/v3.3.2...v3.4.0) (2026-05-04)


### Added

* **asset:** add 'orphaned' + 'all tenants' view filters (admin only) ([11d85b3](https://github.com/moag1000/Little-ISMS-Helper/commit/11d85b36f2a7d935f129f1db63b77663c69efe96))
* **asset:** add ownerPerson tri-state — User → Person → legacy ([4fed1f8](https://github.com/moag1000/Little-ISMS-Helper/commit/4fed1f86d3baecb3874af97979071e9e8b1be4a4))
* **asset:** tri-state Person ownership + n-deputies ([ecdc717](https://github.com/moag1000/Little-ISMS-Helper/commit/ecdc71711f0f35140fc2f48b99368b5f01a856e7))
* **audit-finding:** tri-state Person ownership + n-deputies for assignedTo + reportedBy ([2ecace6](https://github.com/moag1000/Little-ISMS-Helper/commit/2ecace611de37b18944e15eeca8a66a5f475938c))
* **bc-plan:** tri-state Person ownership + n-deputies ([60bb2fd](https://github.com/moag1000/Little-ISMS-Helper/commit/60bb2fd224ac3f3e6d82f78c44e0d376c5b243e4))
* **business-process:** tri-state Person ownership + n-deputies ([8017b8c](https://github.com/moag1000/Little-ISMS-Helper/commit/8017b8cfa792eb42641ee90853f907ebb8fe92f5))
* **compliance-requirement-fulfillment:** expose Person fields in quick-update form ([cd688c8](https://github.com/moag1000/Little-ISMS-Helper/commit/cd688c8d345b7a0c19b43f70b89e7b24a3f43659))
* **compliance-requirement-fulfillment:** tri-state Person ownership + n-deputies (with rename) ([c5465ee](https://github.com/moag1000/Little-ISMS-Helper/commit/c5465ee006acddfee6c9a836672dc907bcaae707))
* **compliance-wizard:** add BSI IT-Grundschutz Basis-Absicherung readiness wizard ([1718407](https://github.com/moag1000/Little-ISMS-Helper/commit/17184079b2186c29e2c44f9b4d4c03628002462b))
* **compliance-wizard:** add BSI IT-Grundschutz Kern-Absicherung readiness wizard ([1469876](https://github.com/moag1000/Little-ISMS-Helper/commit/14698767e6e7e28a35907e7e57e5f2a99d417c5a))
* **compliance-wizard:** add BSI IT-Grundschutz Standard-Absicherung readiness wizard ([7f22f2f](https://github.com/moag1000/Little-ISMS-Helper/commit/7f22f2f2dfe3f2e614ac4b5f51df057898d291f5))
* **compliance-wizard:** add consent_coverage check type ([ad559a3](https://github.com/moag1000/Little-ISMS-Helper/commit/ad559a34250fd4e4fec85ffc57b3f7a9fe4dc927))
* **compliance-wizard:** add dpia_coverage check type ([f111288](https://github.com/moag1000/Little-ISMS-Helper/commit/f111288b9d27a521f0308af6224a53ad2577caf8))
* **compliance-wizard:** add dsr_coverage check type ([592c6d3](https://github.com/moag1000/Little-ISMS-Helper/commit/592c6d3f461dde98a6bb644b63c33a8cc6091f6f))
* **compliance-wizard:** add ISO 22301 BCM readiness wizard ([51b59dd](https://github.com/moag1000/Little-ISMS-Helper/commit/51b59dd0b5a1ba4b1f7acff45371bd82f9e63d47))
* **compliance-wizard:** add ISO 27017 cloud security readiness wizard ([589ad80](https://github.com/moag1000/Little-ISMS-Helper/commit/589ad8052d8534a5b90ff456dfff84a6b676be57))
* **compliance-wizard:** add ISO 27018 cloud privacy readiness wizard ([5aeb348](https://github.com/moag1000/Little-ISMS-Helper/commit/5aeb348cecc01a916fd3d009b3ac2e74f2b12aed))
* **compliance-wizard:** add ISO 27701 PIMS readiness wizard ([b2cd1ca](https://github.com/moag1000/Little-ISMS-Helper/commit/b2cd1cae0a484860bfb409d4484328dd5b128475))
* **compliance-wizard:** add ISO 42001 AI management readiness wizard ([47c9e71](https://github.com/moag1000/Little-ISMS-Helper/commit/47c9e71146d648168d13fa0cedc7e3fc5680a89e))
* **compliance-wizard:** add KRITIS / NIS2-DE-Umsetzung readiness wizard ([81ded94](https://github.com/moag1000/Little-ISMS-Helper/commit/81ded94f08f7ed268f07de7a68309890dedf5065))
* **compliance-wizard:** add NIST Cybersecurity Framework 2.0 readiness wizard ([c456fb9](https://github.com/moag1000/Little-ISMS-Helper/commit/c456fb9be4aca3fb7e51c402c160c4a4188a90cd))
* **compliance-wizard:** wire ISO 22301 wizard route + translations ([293611c](https://github.com/moag1000/Little-ISMS-Helper/commit/293611ce2d09e4f9ec453dc47a36d041bb45df15))
* **compliance-wizard:** wire ISO 27701 wizard route + translations ([1e920b5](https://github.com/moag1000/Little-ISMS-Helper/commit/1e920b5620638e86ee743e60ab1cbbac9132257e))
* **control:** tri-state Person ownership + n-deputies ([93249f1](https://github.com/moag1000/Little-ISMS-Helper/commit/93249f1e31a02899ed716061d3867368c9cdf75b))
* **corrective-action:** tri-state Person ownership + n-deputies (with rename) ([ab7a330](https://github.com/moag1000/Little-ISMS-Helper/commit/ab7a330f5294aa1ec9312ce16baec9fc22ddcd92))
* **crisis-team:** tri-state Person ownership + n-deputies for teamLeader + deputyLeader ([0d7d47d](https://github.com/moag1000/Little-ISMS-Helper/commit/0d7d47dc6540866b26c9c45cab24939901ba4459))
* **custom-report:** add Tri-State owner form + settings_edit route ([8057524](https://github.com/moag1000/Little-ISMS-Helper/commit/8057524ddac797aa38cc441493da412cfbdf8d3a))
* **custom-report:** tri-state Person ownership + n-deputies for owner (form skipped: no CustomReportType exists) ([660e8c7](https://github.com/moag1000/Little-ISMS-Helper/commit/660e8c7c4136d1c1e896e7e84daf4939a0220e88))
* **data-breach:** tri-state Person ownership + n-deputies ([b2da6c2](https://github.com/moag1000/Little-ISMS-Helper/commit/b2da6c23459f7d0546e1a2c6b4a2a950223df2ff))
* **data-subject-request:** tri-state Person ownership + n-deputies for assignedTo ([2dd760b](https://github.com/moag1000/Little-ISMS-Helper/commit/2dd760b1887dc41b04dd6256110098e668fa7c31))
* **dpia:** tri-state Person ownership + n-deputies (3 slots) ([87f32f7](https://github.com/moag1000/Little-ISMS-Helper/commit/87f32f7cb13cbfe0155f3727ffe40a3cecf08721))
* **entity:** add signed days helpers for review/target dates ([7191374](https://github.com/moag1000/Little-ISMS-Helper/commit/719137451f91d9a02fd81ecde2812703d64e67bb))
* **forms:** at-least-one(User|Person) Callback validator across Tri-State forms ([008783a](https://github.com/moag1000/Little-ISMS-Helper/commit/008783a633cb127f573c5ecf46d6e781295bb1a1))
* **four-eyes-approval:** tri-state Person ownership + n-deputies for requestedApprover (form skipped: no FourEyesApprovalRequestType) ([7559fc0](https://github.com/moag1000/Little-ISMS-Helper/commit/7559fc058392a0a7d92938baeaaacc2615415a29))
* **four-eyes:** add approver Tri-State form + app_four_eyes_edit route ([214f4d9](https://github.com/moag1000/Little-ISMS-Helper/commit/214f4d935491248e7c34852e938be425b01efdb3))
* **incident:** add 'orphaned' + 'all tenants' view filters (admin only) ([78fd319](https://github.com/moag1000/Little-ISMS-Helper/commit/78fd31997cbaf5256ab7128df3972b3c8425e566))
* **incident:** tri-state Person ownership + n-deputies ([a955c66](https://github.com/moag1000/Little-ISMS-Helper/commit/a955c668a00a5a14f4437f000ff829c9fdf1e0b5))
* **management-review:** tri-state Person ownership + n-deputies for reviewedBy ([b1db738](https://github.com/moag1000/Little-ISMS-Helper/commit/b1db738b4c8f466f2b6108a61045578b9593959d))
* **owner-resolver:** centralize Tri-State Person resolution ([b9468c4](https://github.com/moag1000/Little-ISMS-Helper/commit/b9468c4d65107a8d1f8ed0c527c714a2a75941ba))
* **person:** allow creating Person from existing User account ([d1e59b7](https://github.com/moag1000/Little-ISMS-Helper/commit/d1e59b76123938ee4073889009961a9806d78138))
* **processing-activity:** tri-state Person ownership + n-deputies + contactPerson rename ([004002e](https://github.com/moag1000/Little-ISMS-Helper/commit/004002ee79becfb8311778722ad6ac59c8a7b3b3))
* **prototype-protection:** tri-state Person ownership + n-deputies for assessor ([42da5b1](https://github.com/moag1000/Little-ISMS-Helper/commit/42da5b138d250599f9a5a50b450dfee9ed0b3706))
* **risk-treatment-plan:** tri-state Person ownership + n-deputies (with rename) ([8e47793](https://github.com/moag1000/Little-ISMS-Helper/commit/8e477930cea2dc12cd8e967071d6b6a2b99d39b5))
* **risk:** add 'orphaned' + 'all tenants' view filters (admin only) ([c246865](https://github.com/moag1000/Little-ISMS-Helper/commit/c246865e4e14d3cc3ed0569aed39b792c7815ccc))
* **risk:** tri-state Person ownership + n-deputies ([a973c97](https://github.com/moag1000/Little-ISMS-Helper/commit/a973c97f08e2f2739d8efa3db6f337da5de8f754))
* **threat-intelligence:** add ThreatIntelligenceType with Tri-State assignee fields ([2825a5c](https://github.com/moag1000/Little-ISMS-Helper/commit/2825a5cfb2ed67c2de1e62c55f0a24aa94a5440c))
* **threat-intelligence:** tri-state Person ownership + n-deputies for assignedTo (form skipped: API-only, no ThreatIntelligenceType) ([bd81f94](https://github.com/moag1000/Little-ISMS-Helper/commit/bd81f940e40a16daf01652cc56431dbc4a5640c5))
* **training:** tri-state Person ownership + n-deputies for trainerUser (extends Pattern A dual-state) ([cfff5dc](https://github.com/moag1000/Little-ISMS-Helper/commit/cfff5dc717a9f6366c04fa277fcfa415ad7fc477))
* **twig:** generic filter-select macro and enum_cases() function ([5ad058c](https://github.com/moag1000/Little-ISMS-Helper/commit/5ad058c78854228d696cd9c1cd178eb134beef1f))


### Fixed

* **admin:** data-repair Dropdowns zeigen jetzt Orphans + Cross-Tenant ([a360317](https://github.com/moag1000/Little-ISMS-Helper/commit/a36031788d06d83b0d3ad1ac93c2f19c7ad2d515))
* **api:** add Groups annotations to new Person fields on 6 API-exposed entities ([091a240](https://github.com/moag1000/Little-ISMS-Helper/commit/091a240bfbb6686a13a5a7f0ba34b571a8ca1460))
* **asset:** expose all six lifecycle states in status filter ([7bac6df](https://github.com/moag1000/Little-ISMS-Helper/commit/7bac6dfdaae64a29508c444a625c00bb2f4e7064))
* **asset:** guard type-distribution chart against empty assets list ([61e7d8c](https://github.com/moag1000/Little-ISMS-Helper/commit/61e7d8c0d53c3fe90dc46b688b84d109a382bceb))
* **asset:** stop hiding non-active assets from the index ([c6ce2e5](https://github.com/moag1000/Little-ISMS-Helper/commit/c6ce2e5ed9a9585ee462c3a2bb20d4a83608ff88))
* **business-process:** review fixes — imports, static OwnerResolver, drop NotBlank ([ea9ddb9](https://github.com/moag1000/Little-ISMS-Helper/commit/ea9ddb928dbced99bd97e5374f7903127cf2c73a))
* **compliance-wizard:** correct route names in ISO 22301 categories ([04c26dd](https://github.com/moag1000/Little-ISMS-Helper/commit/04c26ddd2d5577da7bdeb1e004150f7260b0f04e))
* **compliance-wizard:** translate consent_coverage gaps, round score, add partial gap ([acb07ae](https://github.com/moag1000/Little-ISMS-Helper/commit/acb07aea62a500854acdf975a85c2852ae0a3383))
* **context:** correct sign for daysUntilReview/daysSinceReview KPI ([3c5d606](https://github.com/moag1000/Little-ISMS-Helper/commit/3c5d606690ecf65669ec85cd85ee1953f3d0afd9))
* **control:** rename responsiblePersonContact → responsiblePersonRef ([57a57b7](https://github.com/moag1000/Little-ISMS-Helper/commit/57a57b744bb7fa831fe232aa35e2302d97d3b774))
* **dashboard:** count operationally active assets, not just status='active' ([23e9619](https://github.com/moag1000/Little-ISMS-Helper/commit/23e96190c527ffd7c296da6491d029723603eeed))
* **date-math:** use entity helpers / invert flag in remaining call sites ([b4a6066](https://github.com/moag1000/Little-ISMS-Helper/commit/b4a6066fe0e5ff7952823d77ba730a460ba4e854))
* **document:** hide soft-deleted/archived documents via isOperational() ([3c17f75](https://github.com/moag1000/Little-ISMS-Helper/commit/3c17f759115c0b52458b6fef836084f1333e5d3f))
* **entities:** add InverseJoinColumn onDelete CASCADE to Person ManyToMany joins ([14e3601](https://github.com/moag1000/Little-ISMS-Helper/commit/14e3601e85f88ea481cbacc96161357bb9ba372a))
* **filters:** align dropdowns with form/entity choices, drop hardcoded active filters ([758fb1a](https://github.com/moag1000/Little-ISMS-Helper/commit/758fb1a2f96d1a68b614d1e4aba2b2d2c81f2ea0))
* **filters:** re-import filter macro inside embed blocks ([aea3be5](https://github.com/moag1000/Little-ISMS-Helper/commit/aea3be5bb6922075f0490e776221a0ee3d8e8b87))
* **migrations:** isTransactional()=false on 22 Tri-State migrations ([0a98504](https://github.com/moag1000/Little-ISMS-Helper/commit/0a985043ef67e8a5d3fd8d9aef4ff8439062a76e))
* **migrations:** isTransactional()=false on NotBlank-cleanup migration ([0748826](https://github.com/moag1000/Little-ISMS-Helper/commit/07488260d959060e5111b9dbbc824bddc7b3cb90))
* **schema:** add --mark-migrations-executed to reconcile command ([1a3f02d](https://github.com/moag1000/Little-ISMS-Helper/commit/1a3f02de9d65d90815562d48f9a557788ec5e6eb))
* **schema:** detect DBAL phantom drift in reconcile command ([7d6a83c](https://github.com/moag1000/Little-ISMS-Helper/commit/7d6a83c51e8e031e72dc4ed185e3a5d9f9d57572))
* **setup:** seed ISMSContext from wizard step 6 ([1654391](https://github.com/moag1000/Little-ISMS-Helper/commit/165439161a562fb3c997a5ca1a83846135a0663d))
* **stats:** use entity helpers for sign-correct days math ([fe09a0e](https://github.com/moag1000/Little-ISMS-Helper/commit/fe09a0e70302896a193b662c41e4052900f39ad1))
* **templates:** add missing processOwnerUser/Person/Deputy fields to BusinessProcess edit+new forms ([dc92bbe](https://github.com/moag1000/Little-ISMS-Helper/commit/dc92bbe8fa01cd14401188651a6e090ab13f080e))
* **templates:** expose tri-state Person fields in form templates for 16 entities ([f7d311f](https://github.com/moag1000/Little-ISMS-Helper/commit/f7d311fbfd21a045066f4d0f8206da674b4373f9))
* **templates:** replace asset.owner.email with asset.effectiveOwner in reports ([580a5b1](https://github.com/moag1000/Little-ISMS-Helper/commit/580a5b1aa81a41aa74f67bd405be1da8fafc51b1))
* **templates:** use effectiveRiskOwner/effectivePlanOwner in display contexts ([63dc1a1](https://github.com/moag1000/Little-ISMS-Helper/commit/63dc1a169408b828bd449959c86b63527f8c0c7d))
* **tests:** update controller tests for at-least-one(User|Person) validator ([51721d8](https://github.com/moag1000/Little-ISMS-Helper/commit/51721d85fed07aa8b7dd54a1d8aa34d2f76b751b))
* **tri-state:** drop NotBlank on legacy string fields across Tri-State entities ([2d5fd7e](https://github.com/moag1000/Little-ISMS-Helper/commit/2d5fd7eec982bdb389082e6bcd35e708ec5fdcda))


### Changed

* **context:** move days-since-review math out of template ([8816879](https://github.com/moag1000/Little-ISMS-Helper/commit/881687970bba311e456be7de64b1fdeede8597f6))


### Documentation

* **audit:** map all entity Person-slots for Tri-State rollout ([8cdf266](https://github.com/moag1000/Little-ISMS-Helper/commit/8cdf2660bc1fac239886d2dedd6cd490e64f5211))
* **changelog:** note ISO 22301 + ISO 27701 wizards in [Unreleased] ([5d4fd41](https://github.com/moag1000/Little-ISMS-Helper/commit/5d4fd4129572516cc95df8b162c073780713d911))
* **changelog:** note Tri-State Person complete rollout ([7d90a19](https://github.com/moag1000/Little-ISMS-Helper/commit/7d90a192f4ebad8b462ff77a38bdbd110317c989))
* **changelog:** note Tri-State Person Foundation in [Unreleased] ([d5ea20d](https://github.com/moag1000/Little-ISMS-Helper/commit/d5ea20db0c86d5c444f268274e9432a59ec12124))
* **claude:** document isTransactional() requirement for DDL migrations ([5728c9d](https://github.com/moag1000/Little-ISMS-Helper/commit/5728c9d03071fcb143a8a82d5ab49affb26d8da7))
* **claude:** document release-please workflow + cadence rules ([96338b7](https://github.com/moag1000/Little-ISMS-Helper/commit/96338b7c900276e621716c74cc27489f0a516caf))
* **claude:** emphasize migrations:diff requires manual isTransactional override ([835ba01](https://github.com/moag1000/Little-ISMS-Helper/commit/835ba01a24c475c77f63257c7febd731179ed12b))
* **filter-select:** note embed-scope import requirement ([6fe2497](https://github.com/moag1000/Little-ISMS-Helper/commit/6fe2497405f6b5e30c079d40519eb740f1e88c13))
* **supplier:** add isOperational() symmetric to Asset/Document ([bebd70a](https://github.com/moag1000/Little-ISMS-Helper/commit/bebd70a1456b11bc16ce5eda4b54c054bf49df56))

## [Unreleased]

### Added
- **Compliance-Wizard ISO 22301 (BCM)** — 7-Klausel-Readiness-Check (Context, Leadership, Planning, Support, Operation, Evaluation, Improvement). Wiederverwendet vorhandene Check-Types `bcm_coverage`, `audit_status`, `document_review`, `training_coverage`, `risk_coverage`, `treatment_plan`, `manual`. Erscheint im Wizard-Index sobald das `bcm`-Modul aktiv ist.
- **Compliance-Wizard ISO 27701 (PIMS)** — 8-Bereich-Readiness-Check (PIMS-Kontext, Privacy-Policy, Betroffenenrechte, Privacy-Risikomanagement, Verarbeitungsverzeichnis, Datenschutzvorfälle, Privacy by Design, Auftragsverarbeiter). Neue Check-Types `consent_coverage`, `dsr_coverage`, `dpia_coverage` lesen `Consent`, `DataSubjectRequest` und `ProcessingActivity`-Entities mit gerundeten Prozent-Scores + Partial-Coverage-Gaps + Translator-wrapped Gap-Texte.
- **Tri-State Person Foundation** — neuer Service `OwnerResolver` (statisch, pure-function) zentralisiert die `User → Person → Legacy-String`-Aufloesung quer ueber Entities mit Person-Slots. BusinessProcess ist der Reference-Rollout: `processOwnerPerson` (ManyToOne) + `processOwnerDeputyPersons` (ManyToMany, Vertretung) + `getAllProcessOwners()` Liste fuer mehrfache Verantwortliche. Audit-Doc `docs/audit/person-slots.md` mappt alle 27 Owner-Slots (12 Plan B + 14 Plan C) fuer den Folge-Rollout.
- **Tri-State Person Rollout — komplett** — Pattern auf alle 18 Owner-/Responsible-Entities ausgerollt: Asset, BusinessProcess (Sample), Risk, Control, Incident, BusinessContinuityPlan, DataBreach (DPO + Assessor), DataProtectionImpactAssessment (DPO + Conductor + Approver), ProcessingActivity (ContactPerson + DPO, mit Rename), AuditFinding (Assignee + Reporter), CrisisTeam (TeamLeader + DeputyLeader), CustomReport, DataSubjectRequest, ManagementReview, PrototypeProtectionAssessment, ThreatIntelligence, FourEyesApprovalRequest, Training. Insgesamt 27 Person-Slots: pro Slot existiert jetzt eine `*Person` ManyToOne (Stammdaten-Person ohne System-Login, z.B. externe Stakeholder, Berater, Shared-Mailbox-Inhaber) plus `*DeputyPersons` ManyToMany (Vertretung / mehrfache Verantwortliche). Effective-Accessoren delegieren via `OwnerResolver::resolveEffective` an die User → Person → Legacy-Kette, neue `getAll*Owners()` liefern die komplette Liste fuer Reports/Listen. Form-Templates haben Person-Dropdown + TomSelect-Multi-Select fuer Deputies. CustomReport, ThreatIntelligence und FourEyesApprovalRequest haben kein Form-File (API-only) — Entity-Schema dort vorbereitet, Form on-demand nachruestbar. Renames in ProcessingActivity, ComplianceRequirementFulfillment, CorrectiveAction und RiskTreatmentPlan: bestehende `?User responsiblePerson|contactPerson` zu `*User`-Suffix umbenannt; Caller (Controller, Templates, Services, Monitor-Commands) durchgaengig auf `getEffective*` (Display) bzw. `*User` (Audit-Trail, E-Mail-Recipients) umgestellt.

## [3.3.2] — 2026-04-30

### Fixed
- **HTTP-Deployments hinter Reverse-Proxy**: prod-Config `cookie_secure: true` (hardcoded) brach Sessions ueber HTTP — jeder Request neue Session, CSRF-Token immer fail, Setup-Wizard-POST→302→Loop. Jetzt `'auto'` + trusted_proxies-Config fuer X-Forwarded-Proto.
- **Skip-Restore Race-Condition**: Symfony-Messenger Doctrine-Transport (`auto_setup: true`) legte parallel `messenger_messages` an waehrend `runFreshSchemaInstall` lief → Bulk-Batch crash "already exists". Idempotenter Batch via `CREATE TABLE IF NOT EXISTS` Munging.
- **Async-Job-Pattern**: `detachAndContinue()` Helper mit explizitem `ob_end_flush + flush` vor `fastcgi_finish_request()`. 5 Call-Sites umgestellt — POST-Hang gegen `output_buffering=4096` default behoben.
- **Step 8 Compliance-Frameworks**: Mandatory-Frameworks jetzt korrekt `disabled` gerendert + force-included server-side (DOM-Tampering-safe). Recommended bleibt pre-checked aber abwaehlbar.
- **`::placeholder` Ueberlagerung in Floating-Labels**: Aurora-Override hatte Bootstrap-Default ueberschrieben → Placeholder + Label uebereinander unleserlich. Jetzt transparent in `.form-floating`-Wrappern.
- **Schema-Drift Detection**: `SchemaExceptionSubscriber` redirected jetzt auch bei Schema-Drift ohne pending Migration (Column-Mismatch nach fehlgeschlagener Migration zeigte vorher 500).

### Added
- **Quick-Fix Reconcile-UI**: Drift-Branch mit SQL-Preview + Apply-Button (nur additive Statements; destructive blockiert mit CLI-Hinweis). Neuer Endpoint `/quick-fix/reconcile`.
- **ISMSContext Wizard-Seeding**: Step-6-Daten werden jetzt in den ISMS-Kontext (Clause 4) uebernommen — Organisationsname identisch zum Tenant, Scope-Starter aus Branchen/Land/Mitarbeiterzahl, Beschreibung in `internalIssues`. Idempotent — User-Edits werden nicht ueberschrieben.

### Changed
- nginx `fastcgi_read_timeout 1800s` + `fastcgi_buffering off` als Safety net fuer langsame Hardware (Pi/SD-Card-I/O).

## [3.3.1] — 2026-04-30

### Fix: Docker-Build wieder auf PHP 8.5 + Setup-Wizard ~36 % schneller

`v3.2.8` hatte das Docker-Image vorübergehend auf PHP 8.4 zurückgerollt,
weil der `docker-php-ext-install`-Pfad auf Debian Trixie + 8.5 brach
(`cp: modules/* No such file or directory`). Lösung: Build-Pfad auf das
gepflegte `mlocati/install-php-extensions:2`-Helper-Image umgestellt; das
löst die apt-Build-Deps + Cleanup automatisch und kommt mit PHP 8.5 klar.

Gleichzeitig wurde der Setup-Wizard-Pfad „Step 3 → Option 2 (Skip / Neu-
Installation)" merklich beschleunigt — aus ~62 Sekunden wurden ~40 Sek.
Die verbleibenden 40 s sind disk-bound (`innodb_flush_log_at_trx_commit=1`
des MariaDB-Defaults); ein DB-User mit SUPER-Recht halbiert das nochmals
(best-effort `SET GLOBAL innodb_flush_log_at_trx_commit=2`).

#### Docker

- **PHP 8.4 → 8.5.5** (`php:8.5-fpm-trixie@sha256:7d1586e8…`).
- **`docker-php-ext-install` → `mlocati/install-php-extensions:2`**: ein
  COPY + ein RUN, statt zerbrechlicher Configure-Compile-Cleanup-Dance.
- **Apt-Liste -8 Pakete**: `libzip-dev`, `libonig-dev`, `libpng-dev`,
  `libfreetype6-dev`, `libjpeg62-turbo-dev`, `libxml2-dev`, `libicu-dev`,
  `libmariadb-dev` werden vom Installer transient gezogen und nach
  Compile wieder entfernt → Image bleibt kompakt.
- **Dev-Stage**: `pecl install xdebug` + `linux-headers-generic` raus,
  `install-php-extensions xdebug` rein.
- Lokal verifiziert: `docker build --target production` 4 m 17 s,
  Container startet `healthy` in 6 s, Symfony 7.4.8 prod-boot OK.

#### Setup-Wizard Step 3 / Option 2

- **Redundanter `dropAndRecreateDatabase`-Call** (per-Table-Loop auf
  separater PDO-Verbindung, ~125 RTTs) entfernt — `runFreshSchemaInstall`
  macht den Drop bereits batched.
- **DROP DATABASE / CREATE DATABASE** statt 125 × `DROP TABLE` (Drop-
  Phase: 14.9 s → 8.2 s). Fallback auf per-Table-Loop, falls dem Setup-
  User die `DROP/CREATE DATABASE`-Privilegien fehlen.
- **`SET UNIQUE_CHECKS = 0`** während des CREATE-Bulks (Create-Exec-
  Phase: 46.0 s → 28.4 s).
- **Best-effort `SET GLOBAL innodb_flush_log_at_trx_commit = 2`** für die
  Dauer des Bulks; greift nur, wenn der Setup-User SUPER hat. Ohne SUPER
  kein Schaden.
- **Timing-Diagnose**: `runFreshSchemaInstall` schreibt jetzt eine
  `timings`-Map ins Resultat (metadata, drop, create_sql_gen, create_exec,
  migrations_register, total) — Logger-Eintrag in beiden Pfaden
  (`step3CreateSchema`, `step3RestoreBackupSkip`).
- **Alva-Hilfetext** kommuniziert jetzt offen die ~40 Sekunden Wartezeit
  und den SUPER-Recht-Tipp (DE + EN).

#### Tooling

- Neuer Konsolenbefehl `app:bench-schema-install` benchmarkt den exakt
  gleichen Code-Pfad gegen die aktuelle DB und gibt die Per-Phasen-
  Timings aus (DESTRUCTIVE — Test-DB only).

## [3.3.0] — 2026-04-29

Erstes Minor-Release nach `3.2.x` — bringt zwei substantielle neue Module
(Generic-SSO, Framework-Baselines), den GSTOOL-XML-Import-Pfad sowie i18n
für die MRIS-Baselines. Keine Breaking-Changes, alle Migrationen sind
additiv und idempotent.

### Feature: Generic SSO (OIDC/OAuth2)

Multi-IdP-Login mit Admin-Modul. Login-Seite zeigt Buttons nur für
aktive Provider; Tenant-scoped + globale IdPs koexistieren; Domain-
Bindung filtert Sichtbarkeit per E-Mail-Domain.

- OAuth2 Authorization-Code-Flow mit PKCE (S256), `state`-Schutz via
  `hash_equals`, Session-gestützte Nonce-Verwaltung.
- ID-Token-Verifikation gegen JWKS via `web-token/jwt-library`
  (RS256/RS384/RS512/PS256/ES256), Issuer/Audience/Exp/Iat-Checks.
- Discovery-Doc + JWKS-Cache (1h, Auto-Refresh bei unbekanntem `kid`).
- Client-Secret AEAD-verschlüsselt at-rest (XChaCha20-Poly1305-IETF mit
  BLAKE2b-abgeleitetem Schlüssel aus `kernel.secret`).
- JIT-Provisioning mit Approval-Queue (Default: Admin freigibt; opt-in
  Auto-Approve), domain-bound Account-Linking, Default-Rollen-Vergabe.
- Admin-UI `/{locale}/admin/sso` (CRUD, Toggle, Discovery-Test, Delete)
  + `/admin/sso/approvals` (Approve/Reject mit Begründung).
- ROLE_ADMIN für Tenant-IdPs, ROLE_SUPER_ADMIN für globale IdPs.
- Migration `Version20260429210000_generic_sso` legt
  `identity_provider`, `sso_user_approval` und
  `users.sso_external_id`/`users.sso_provider_id` an.

### Feature: Framework-Baselines (Industry-Maturity-Targets)

35 vorkonfigurierte Reife-Soll-Pakete pro Branche × Framework. Anwenden
setzt nur `maturityTarget` — Self-Assessments (Ist-Werte) bleiben
unangetastet.

| Framework | KRITIS | Finance | SaaS | Manufacturing | Healthcare |
|-----------|--------|---------|------|---------------|------------|
| ISO 27001:2022 (47 Annex-A) | ✓ | ✓ | ✓ | ✓ | ✓ |
| BSI IT-Grundschutz (113 Anf.) | ✓ | ✓ | ✓ | ✓ | ✓ |
| BSI C5:2020 (24 Kriterien) | ✓ | ✓ | ✓ | ✓ | ✓ |
| NIS2 Art. 21.2 (10 Maßnahmen) | ✓ | ✓ | ✓ | ✓ | ✓ |
| DORA (15 Artikel) | ✓ | ✓ | ✓ | ✓ | ✓ |
| TISAX/VDA-ISA (99 Controls) | ✓ | ✓ | ✓ | ✓ | ✓ |
| GDPR (16 Artikel) | ✓ | ✓ | ✓ | ✓ | ✓ |

Reasons referenzieren konkrete Aufsichtserwartungen: BSIG §8a/§8b,
KRITIS-Verordnung, BAIT, MaRisk, B3S-Gesundheit, KHZG (§ 75c SGB V),
MDR (EU 2017/745), DSGVO Art. 9, IEC 62443, TISAX VDA-ISA, BSI
TR-02102.

- Service `IndustryBaselineService` framework-agnostisch, locale-aware
  (DE/EN via `*_en`-Suffix), path-traversal-geschützt.
- Admin-UI `/{locale}/admin/industry-baselines` mit Framework-Listing,
  Per-Framework-Detail, Manager-gated Apply mit Dry-Run-Vorschau und
  Audit-Log-Eintrag (`compliance.baseline.apply`).
- 7 Unit-Tests (Loader, Locale, Dry-Run, Path-Traversal, alle 35
  YAMLs gegen Schema).

### Feature: GSTOOL-XML-Import (5 Phasen + Admin-UI)

Vollständiger Migrationspfad für GSTOOL/Verinice-Profile (Edition 2023).

- **Phase 1**: Zielobjekte → `Asset` (mit Schutzbedarf-Mapping
  vernachlässigbar/normal/hoch/sehr-hoch → 1..5).
- **Phase 2**: Modellierung → `Asset.dependsOn` (Abhängigkeitsgraph für
  BSI-3.6-Maximumprinzip).
- **Phase 3+4**: Bausteine + Maßnahmen → `ComplianceRequirement` +
  `Control` mit ISO-27001-Mapping.
- **Phase 5**: Risikoanalyse (BSI 200-3) → `Risk` mit
  Eintrittshäufigkeit/Schadenshöhe-Mapping (4-stufig BSI → 5-stufig
  Tool, Wert 3 übersprungen).
- Admin-UI `/admin/gstool-import` mit Upload + Tabbed-Preview (Bausteine/
  Maßnahmen/Risiken) + Commit-Button.
- XSLT-Wrapper für reale Verinice-Exporte (decoupling von Schema-
  Varianten).
- 1 neuer XML-Import-Command + Importer-Service + Tests.

### Feature: MRIS-Baselines i18n + 11 neue Branchen

19 MRIS-Branchen-Baselines bilingual (DE/EN via `*_en`-Suffix-Felder),
`MrisBaselineService` jetzt locale-aware via `RequestStack`.

Neue Branchen (zu den 8 bestehenden): Pharma, Telekommunikation,
Manufacturing-OT, Logistics, Retail, Education, Legal/Tax, Defense,
MSP, IT-Systemhaus, Software-Developer.

Hilfetext "Was tut eine MRIS-Baseline?" als Collapse-Element auf der
Baselines-Seite (DE/EN).

### Feature: Audit-Certification-Bundle-Export

`CertificationBundleExporter`-Service + Controller exportiert ein
Audit-fertiges Beweis-Bundle (Evidence-Collection inkl. Dokumente,
Audit-Logs, Compliance-Status) für externe Prüfer.

### Feature: Small-Business-Accessibility (<50 FTE)

7 vereinfachte Maßnahmen-Sets für KMUs unter 50 Mitarbeitenden — runtime-
gehärtet gegen `null` Tenant-Settings in `resolveCompanySize`.

### Feature: Onboarding-Journey + Community-Profile

- Unified Guidance-System: ISMS-Journey-Widget + reichere Empty-States
  in allen ISMS-Modulen.
- GitHub-Community-Profile auf 100 % (SECURITY.md, CODE_OF_CONDUCT.md,
  Issue-Forms, PR-Template) — `SECURITY.md` unter `.github/` für
  Auto-Detection.

### Fix: SSO-Hardening (Post-Audit)

- `users.created_at` jetzt in JIT-User-Provision gesetzt (NOT-NULL-
  Constraint hätte `INSERT` zerlegt).
- Anonyme Login-Visitor sehen tenant-scoped IdPs nur über matching
  Email-Domain (kein IdP-Leak); Slug-Resolution fällt auf
  `findOneBySlugAnywhere` zurück.
- BLAKE2b-Key-Derivation korrigiert (CTX-Tag war kürzer als
  16 Bytes — `sodium_crypto_generichash` lehnte ab).

### Fix: Composer-Pin web-token/jwt-library

`web-token/jwt-library` war im `composer.lock` aber nicht in
`composer.json require` → PHPStan/Code-Quality-Job meldete jede
`Jose\Component\*`-Klasse als "not found". Jetzt explizit auf `^4.0`
gepinnt.

### Fix: MRIS-Baseline-Service-Test

`MrisBaselineService::__construct` bekam in 3.2.x einen `RequestStack`-
Parameter vor `$projectDir` — der Test rief das alte Signatur-Layout
auf. Test injiziert jetzt `new RequestStack()` an Position 5.

## [3.2.8] — 2026-04-29

### Fix: PHP 8.4 base image (revert) für Docker-Build

v3.2.7 ist getaggt, hat aber **kein Docker-Image** — Docker-Build brach an
`docker-php-ext-install` (gd/pdo/mysqli-Extension-Chain) auf dem PHP-8.5-
fpm-Base. Fehler: `cp: cannot stat 'modules/*': No such file or directory`.
Upstream-`docker-php-ext` Helper-Skripte unterstützen den 8.5er Module-Build
noch nicht zuverlässig.

Zurück auf `php:8.4-fpm-trixie@sha256:eec2a132…` für jetzt. Tests, Code-Quality,
Security-Checks waren auf v3.2.7 alle grün — der Code selbst läuft mit beiden
PHP-Versionen. v3.2.8 ist v3.2.7 mit funktionierendem Docker-Build.

PHP-8.5-Bump wird in eigenem Sprint nach `php:8.5.5+` Image-Release nachgeholt.

## [3.2.7] — 2026-04-29

### Refactor: Property/Getter-Alignment in 17 Entities

17 latente Landminen behoben — Properties die nicht zum Getter-Namen passten
(z.B. `private $user` mit `getUploadedBy()`). Twig-Magic-Resolution braucht
`entity.foo` → `getFoo()` Korrespondenz; Mismatches werfen "Neither the
property X nor methods getX/isX/hasX exist". Eines davon (`complianceFramework`)
hatte uns zur Laufzeit getroffen, der Audit hat 16 weitere gefunden.

JoinColumn-`name=` ist überall gepinnt → keine DB-Migration nötig.

In drei Tiers gemerged:

* **Tier A** (8 isolierte Entities): RiskAppetite/Document/ManagementReview/
  CorporateGovernance/MappingGapItem/RiskTreatmentPlan/AuditChecklist/
  ComplianceRequirementFulfillment
* **Tier B** (DQL-touched): WorkflowInstance.workflowStep→currentStep,
  ComplianceRequirement.complianceFramework→framework + parent self-ref
* **Tier C** (high-fanout): InternalAudit, Incident.threatIntelligence,
  ThreatIntelligence.user, Risk.user

Folge-Fixes nach erstem CI-Lauf:

* 3 inverse `mappedBy`-Refs auf Owning-Side angepasst
  (ComplianceFramework→requirements, ComplianceMapping→gapItems,
  ThreatIntelligence→resultingIncidents)
* `findBy()`-Criteria-Arrays in src/ + tests/ massweise umbenannt
  (`'complianceRequirement' => …` etc.)
* BsiProfileXmlImporter-Test Bracket-Access aktualisiert

### Fix: Post-v3.2.6 Stabilisierung (PHP 8.5 + Templates + Setup)

13 Folge-Fixes nach dem v3.2.6-Tag, allesamt punktuelle Laufzeit- und
Template-Reparaturen aus den Spezialisten-Reviews. Kein Schema-Bruch,
keine API-Änderungen.

#### PHP 8.5 strict type-coercion (Fortsetzung von v3.2.6)

* **Repository-Scalars an der Quelle gecastet** — Aggregations-Queries
  (`COUNT`, `SUM`, `AVG`) lieferten je nach Treiber `int|string`. Statt an
  jeder Aufrufstelle zu casten, normalisieren die Repositories jetzt direkt
  auf `int`/`float` zurück. Schließt eine Klasse von TypeError-Restbügeln,
  die nur unter PHP 8.5 sichtbar wurden.
* **`SupplierType::finishView` + Repository-Scalars** — analoge
  Coercion-Bügel im Form-Layer (Form-Type liest gecastete Werte direkt).
* **Vier Template-Runtime-Issues aus dev.log** — implizite Float→Int-Casts
  in vier Twig-Aufrufpfaden (Render-Layer, nicht Service-Layer).

#### Templates / UI

* **`fix(charts+batch-analysis)`** — Chart.js 4 Colors-Plugin korrekt
  registriert (war v3-API-Stub). `.d-none`-Visibility für Empty-State auf
  Batch-Analysis-Karten + ein Offset-Off-by-One in der Pagination.
* **`fix(mapping-quality)`** — Twig referenzierte `complianceFramework`,
  Entity-Property heißt seit v3.2.0 nur noch `framework`. Drei Templates
  angepasst.
* **`fix(role-management)`** — `~`-Concat-Operator in Twig ist
  string-only; Array-Merge braucht den `|merge`-Filter.
* **`fix(data-breach)`** — `followUpActions` ist ein strukturiertes
  Array (Action + Owner + Due-Date), Template hatte es als String
  ausgegeben (`Array to string conversion`-Notice).

#### Setup / MRIS

* **`fix(mris+quick-fix)`** — `bc_exercise` heißt im Schema so, nicht
  `bc_exercises`. Der Quick-Fix-Subscriber prüfte den Plural-Tabellennamen
  und leitete deshalb auch auf intakten Schemata zur Quick-Fix-Seite um.
* **`fix(mris)`** — analoger Tabellennamen-Bug für `mfa_tokens` (heißt
  `mfa_token`) und `users` (heißt `user`) in Raw-SQL-Konstanten.

#### Industry Baselines

* **`feat(industry-baseline)`** — One-Click-Seed-Button auf der
  Baseline-Übersicht, wenn der Katalog leer ist. Erspart frischen
  Installationen den Konsolen-Befehl.

#### Repo-Hygiene

* **`chore(git)`** — `node_modules/` in `.gitignore` (war seit
  stylelint-Einführung untracked aber nicht ignored).
* **`chore(deps)`** — `package-lock.json`-Name auf Lower-Case normalisiert
  (npm flippte ihn bei jedem Install hin und her).

### Schema-Reconciliation (post-Migrations-4.0)

`doctrine:schema:validate` zeigte nach dem Bundle-4-Bump drei harmlose
Pre-Existing-Inkonsistenzen — alle in einer reversiblen Migration
behoben:

* `RiskAppetite#reviewBufferMultiplier`: `DECIMAL(4,2)` → `FLOAT`. PHP-Property war `float`, DBAL liefert für `DECIMAL` aber `string` — implizite Casts in Arithmetik. Werte 1.0–3.0 mit 2 Nachkommastellen sind FP32-präzise.
* `incident.severity`: nullable enforce (matched ORM-Mapping).
* DPIA: Index-Rename auf Doctrine-Convention (rein kosmetisch).

`migrations/Version20260429110455.php`. `schema:validate` jetzt grün auf
beiden Sektionen.

### Tests

* **DeploymentWizardControllerTest setUp/tearDown** — Lock-Backup +
  Restore. Vorher entfernte das setUp den `setup_complete.lock` global
  im PHPUnit-Prozess; Folge-Tests in anderen Klassen wurden danach von
  `SetupRequiredSubscriber` zur Setup-Seite redirected (CI: 3 → 193
  Failures-Spike). Backup im setUp, Wiederherstellung im tearDown.
* **CSRF-Token via Session-Save** — `bulk-delete`-Tests brauchen aktive
  Session bevor `getToken()` aufgerufen wird (`security.csrf.token_manager`
  schreibt in die Session, ohne Save-Call greift der Submit-Read den Token
  nicht).

### Skills

* `pentester-specialist`-Skill für OWASP/PTES/NIST-800-115/OSSTMM-aligned
  Security-Reviews. Treiber für PT-001 (MFA-Bypass) und PT-003
  (TOTP-Klartext) während der v3.2.5-Welle.

## [3.2.6] — 2026-04-29

### Fix: PHP 8.5 strict type-coercion auf Dashboard-KPIs

PHP 8.5 enforces stricter type-coercion: `round()` returnt `float`, kann
nicht mehr implizit in einen `int`-typed Parameter gecastet werden. Mit dem
Base-Image-Bump auf `php:8.5-fpm-trixie` in v3.2.5 (Tag-Build wurde wegen
genau dieses Bugs vor Docker-Push gecancelt — **v3.2.5 hat kein
Docker-Image**) brach `DashboardStatisticsService::getStatus()` zur Laufzeit
beim Aufruf mit `round()`-Argumenten:

```
TypeError: getStatus(): Argument #1 ($value) must be of type int, float given
```

Fünf Call-Sites in `DashboardStatisticsService` mit explizitem `(int)` Cast
versehen:

* `$treatmentRate` (Zeile 1134) — Risk-Treatment-Rate-KPI
* `$classificationRate` (Zeile 1240) — Asset-Classification-KPI
* `$biaCoverage` (Zeile 1377) — BIA-Coverage-KPI
* `$completionRate` (Zeile 1444) — Training-Completion-KPI
* `$assessmentRate` (Zeile 1534) — Supplier-Assessment-KPI
* `$reportingCompliance` (Zeile 1718) — Incident-4h-Reporting-KPI

Audit über die gesamte `src/`-Codebase (96 Dateien mit `round(`, 344
Vorkommen): **keine weiteren akuten PHP-8.5-strict-coercion-Bugs**. Die
Codebase nutzt durchgängig diszipliniertes `(int) round(...)`-Pattern an
allen kritischen int-Boundaries (Entity-Setter, Method-Returns mit `: int`,
KPI-Threshold-Vergleiche).

### Hinweis zu v3.2.5

Tag `v3.2.5` existiert auf GitHub aber **wurde nicht released** — der
Docker-Build wurde gecancelt, sobald der Bug auffiel. Kein gepushtes
Docker-Image, keine GitHub-Release-Seite. v3.2.6 enthält alle v3.2.5
Inhalte plus diesen Fix. Der Tag bleibt als historischer Marker bestehen.

Alle v3.2.5-Inhalte (TOTP-Encryption, PHP-8.5, Doctrine-Migrations-4.0,
PHPUnit-13.1, Turbo-8, Chart.js-4, stylelint-17, GitHub-Actions-Bumps,
Aurora-T3-Sprint, Dependabot/Pre-commit/Codecov-Config, Hadolint-Smell-Fixes,
Repo-Cleanup) gelten in v3.2.6 weiterhin.

## [3.2.5] — 2026-04-29

### Security

* **TOTP-Secrets at-rest verschlüsselt** (CVSS 6.5, T1-7) — MFA-Tokens
  speichern Geheimnisse jetzt verschlüsselt in der DB. Alte Plaintext-Secrets
  werden beim ersten Zugriff transparent migriert (Auto-Heal-Pattern, kein
  User-Action nötig). Verhindert Disclosure bei DB-Backup-Diebstahl.
  **Deployment-Hinweis:** Optional `MFA_ENCRYPTION_KEY` in `.env` setzen für
  unabhängige Key-Rotation (Fallback: APP_SECRET). Bulk-Migration aller Secrets:
  `php bin/console app:encrypt-mfa-secrets --dry-run` dann
  `php bin/console app:encrypt-mfa-secrets`.
* **CSRF-Token-Persistierung in Tests** — `generateCsrfToken()` ruft jetzt
  `$session->save()` auf, weshalb die 4 zuvor `SessionNotFoundException`-
  betroffenen `AssetControllerTest::testBulkDelete*` jetzt grün laufen.
  Test-seitig — keine Produktions-Auswirkung.

### Dependencies (Major-Bumps)

Major-Bumps in der Liste — alle CI-validiert (Tests + Code-Quality + Docker):

* **PHP 8.4 → 8.5** Base-Image (`php:8.5-fpm-trixie@sha256:7d1586e8…`).
  Extension-Build-Issues aus früheren 8.5-Versionen sind in 8.5.4+ resolved.
* **Doctrine Migrations Bundle** 3.7 → 4.0
* **PHPUnit** 12.5 → 13.1
* **Hotwired Turbo** 7.3.0 → 8.0.23 (CVE-Fix)
* **Chart.js** 3.9.1 → 4.5.1
* **stylelint** 16.26.1 → 17.9.1
* **stylelint-config-standard** 36.0.1 → 40.0.0
* **GitHub Actions**: docker/setup-qemu 3→4, docker/build-push 5→7,
  actions/cache 4→5, actions/setup-node 4→6, actions/upload-artifact 4→7

### UX (Aurora-Sprint T3)

* `feat(ux)` T3-10: locale-aware date formatting via Twig extension
* `refactor(ux)` T3-2 + T3-6: KPI cards migrated + empty states consolidated
* `refactor(ux)` T3-3: 5 modules standardized on `_search_filter_form`
* `fix(ux)` T3-8: client-side search added to 4 index pages

### CI/CD-Workflow

* **Dependabot** aktiviert (`/.github/dependabot.yml`) — wöchentliche
  Auto-PRs für composer, npm, github-actions, docker. Gruppiert
  symfony/* und doctrine/* zu Sammel-PRs.
* **Pre-commit-Hooks** (`/.pre-commit-config.yaml`) — trailing-whitespace,
  large-file-guard, JSON/YAML-Lint, PHP -l, Hadolint, Symfony Twig-Lint,
  Symfony YAML-Lint, GitLeaks Secret-Scan. Install via
  `pip install pre-commit && pre-commit install`.
* **Codecov-Config** (`/.codecov.yml`) — Coverage-Trend-Range 60-90%, Tests
  + Vendor + Migrations ignored, project + patch status informational.
  Codecov-Action war bereits gewired; jetzt mit Repo-Config-Datei auswertbar.
* **Hadolint Dockerfile-Smells** behoben — DL3059 (consecutive RUN) +
  2× SC2015 (`A && B || C`-Pattern). Lint-clean lokal.
* **Repo-Labels** angelegt (`dependencies`, `composer`, `javascript`,
  `docker`, `github-actions`) — Dependabot kann Labels auf PRs nun setzen
  ohne Fehler-Comment.

### Repository-Cleanup

3 obsolete Branches gelöscht:

* `claude/symfony-best-practices-review-…` (PR #264 längst gemerged)
* `feat/mris-integration` (Integration-Plan-Doc; MRIS-Schema längst in main)
* `feat/phase10-workflows` (10 regulatory Workflows längst in main via
  andere Routen)

## [3.2.4] — 2026-04-29

### Docker-Hardening + Source-Updates

#### Supply-Chain-Transparenz (ISO 27001 A.5.21 / BSI C5 DEV-08)

* **SBOM (SPDX) als OCI-Attestation** — `docker/build-push-action` ruft jetzt mit `sbom: true` ein. Jeder gepushte Image-Tag bringt eine signierte Software-Bill-of-Materials in den Manifest-Index. Audit-Nachweis aller eingebauten Pakete (PHP-Extensions, Debian-Packages, Composer-Deps, NPM-Importmap) ohne `docker run --rm IMAGE list-packages`.
* **SLSA-Build-Provenance** — `provenance: mode=max` erzeugt eine signierte Attestation, die belegt: *welcher* GitHub-Actions-Workflow hat das Image aus *welchem* Commit gebaut. Schließt typische Supply-Chain-Angriffsvektoren (CI-Übernahme, Tag-Hijacking).

#### Build-Performance

* **BuildKit Cache-Mounts** im Dockerfile für `apt-get` (`/var/cache/apt` + `/var/lib/apt`) und `composer install` (`/root/.composer/cache`). Warmer Build: 40-60% schneller. Cache landet nicht im finalen Image-Layer.
* **`# syntax=docker/dockerfile:1.7`** als Frontline-Direktive aktiviert die für Cache-Mounts nötige Frontend-Version.

#### Reproducible Builds

* **Pinned Base-Image-Digest**: `php:8.4-fpm-trixie@sha256:eec2a132…` statt nur Tag. Schützt gegen Tag-Rollover (z.B. wenn Upstream das Tag während eines Builds neu pusht). Kommentar im Dockerfile dokumentiert wie der Digest aktualisiert wird.

#### Code-Quality-Gates

* **Hadolint** als CI-Job — Dockerfile-Linter, der typische Smells fängt (`apt install` ohne `--no-install-recommends`, fehlende Pinning-Versionen, root-as-default-User). Aktuelle Konfiguration: `failure-threshold: error`, `continue-on-error: true` — Warnings werden gemeldet aber blocken Build noch nicht (Soft-Launch). Drei Regeln auf Allowlist (DL3008/DL3015/DL3018) — Stable-Distro-Pakete und Pip-Setup-Pattern den unsere Setup explizit nutzt.

#### Source-Updates

* **PHPStan** 2.1.51 → 2.1.53 (Patch).
* **Bootstrap** 5.3.3 → 5.3.8 (Minor — Bug-Fixes, kein API-Bruch).
* **SortableJS** 1.15.3 → 1.15.7 (Patch).
* **Keine Security-Advisories** im aktuellen Composer-Tree.

#### Bewusst nicht aktualisiert (eigener Sprint nötig)

* **`@hotwired/turbo` 7.3.0 → 8.0.23** — Major-Bump mit potentiellen Stimulus/Turbo-Convention-Änderungen, eigene QA-Phase nötig.
* **`chart.js` 3.9.1 → 4.5.1** — Major-Bump mit substantiellen Konfigurations-API-Änderungen.

Beide für v3.3.0 vorgesehen.

#### Bekannte Test-Failures aus v3.2.3 weiter offen

Die 4 `AssetControllerTest::testBulkDelete*` Errors (`SessionNotFoundException`) sind weiter offen — Test-seitig, nicht produktions-seitig. Wird parallel adressiert.

## [3.2.3] — 2026-04-28

### Quick-Fix-Fallback für Schema-Mismatch nach Composer-Upgrade

Nach `composer install` / `git pull` ohne Container-Neustart konnte ein
fehlendes Schema-Update (`Doctrine\DBAL\Exception\TableNotFoundException`,
`MappingException`, `Unknown column …`) nur einen 500er produzieren — auf
shared-hosting Setups ohne SSH-Zugriff praktisch nicht behebbar ohne
Anleitungen, die User händisch befolgen.

Neuer Fallback:

* **SchemaExceptionSubscriber** (`kernel.exception`, priority 64) fängt
  TableNotFound / Mapping-Exceptions ab und leitet auf `/quick-fix` statt
  500 — locale-prefix-frei, weil der Schema-Fehler den Locale-Resolver
  selbst brechen kann.
* **Quick-Fix-UI** (`/quick-fix`) zeigt minimalen Diagnostic-Output (nur
  Anzahl pending Migrationen, keine Tabellen-/Spalten-Namen) + Button
  "Migrationen jetzt anwenden" → POST `/quick-fix/apply` ruft den
  bestehenden `SchemaMaintenanceService::executePendingMigrations()`. UI
  ist standalone (keine `base.html.twig`-Abhängigkeiten), funktioniert
  auch wenn Sidebar/Locale-Resolver kaputt sind.
* **QuickFixGuard** mit 4 Settings unter `quick_fix.*` Kategorie:
  - `fallback_ui_enabled` (default true) — Master-Schalter, off → Standard-500
  - `require_installer_token` — Token-Match gegen `var/setup-token`
  - `allow_in_dev_only` — nur erreichbar wenn `APP_ENV=dev`
  - `ip_allowlist` — Komma-Liste erlaubter Client-IPs
  Defaults sind Docker-Self-Hosting-tauglich (alle Toggles aus).
  Composer-Installs schreiben automatisch via post-install-cmd ein
  64-Hex-Token nach `var/setup-token` für späteres Aktivieren.
* **Admin-Settings-UI** unter `/admin/quick-fix-settings` (ROLE_ADMIN) +
  Eintrag im Admin-Dashboard-Quick-Actions.
* **Locked-Page** (Token-Mode + Guard-Block) mit Inline-Token-Eingabe-Form
  und Cookie-Persist (sodass POST-Apply nicht erneut Token braucht).

### Aurora-Error-Pages für 429 + 503

Bisher fielen `429 Too Many Requests` und `503 Service Unavailable` auf
das generische `error.html.twig` Template. Jetzt eigene Aurora-Templates
mit `Retry-After`-Anzeige (wenn vom Listener mitgegeben), Alva-Mood
`warning`, Reload + Home-Buttons. Pattern matcht 403/404/500.

### Test-Coverage

* `QuickFixGuardTest` — 10 Tests (default-open, 3 Toggles, fail-closed bei
  fehlender Settings-Tabelle, Token-Cookie + Query-Param)
* `SchemaExceptionSubscriberTest` — 6 Tests (TableNotFound, MappingException,
  Recursion-Guard, Disabled-Mode, Previous-Chain-Unwrap)

### Versionsanzeige

`composer.json` Version-Feld auf `3.2.3` gebumpt — wurde bei v3.2.2 vergessen,
sodass Footer-Branding (`AppVersionExtension`) und Email-Templates noch
v3.2.1 anzeigten.

## [3.2.2] — 2026-04-28

### Patch-Release: Test-Suite grün nach Enum-Migration

v3.2.1 wurde von kaputtem CI-Lauf getaggt — 3 Errors + 4 Failures aus laufender
String→BackedEnum-Migration für `IncidentStatus` / `RiskStatus`. v3.2.2 bringt
genau diese Fixes nach.

#### Enum-Vergleiche in Service-Layer

* `DashboardStatisticsService::computeDashboardStatistics()` — Open-Incident-
  Filter verglich `getStatus() === 'open'`. `Incident::getStatus()` liefert
  jetzt `IncidentStatus`-Enum, nie String → Filter immer false. Ersetzt durch
  `in_array($i->getStatus(), [Reported, InInvestigation, InResolution], true)`.
  Zweite Stelle in der Backlog-Score-Komponente identisch gefixt.
* `ReviewReminderService::getOverdueRiskReviews()` /
  `getUpcomingReviews()` — Closed/Accepted-Ausschluss verglich
  `in_array($risk->getStatus(), ['closed','accepted'], true)`. Risk liefert
  `RiskStatus`-Enum → Vergleich nie wahr → akzeptierte/geschlossene Risiken
  sind als overdue durchgerutscht. Auf `[RiskStatus::Closed,
  RiskStatus::Accepted]` umgestellt.

#### Route-Namen korrigiert

Drei Stellen referenzierten den nicht-existenten Routen-Namen
`app_business_continuity_plan_edit/_show`. BC-Plan-Routen heißen
`app_bc_plan_*`. Betroffen:

* `templates/admin/data_repair/index.html.twig` (Edit-Link in BC-untested-Tabelle)
* `templates/home/_overdue_reviews_widget.html.twig` (Show-Link im Widget)
* `src/Service/ReviewReminderService::generateUpcomingReviewLinks()` (E-Mail-Reminder)

DataRepairController-Test-Render brach an Stelle 1, die anderen warfen erst zur
Laufzeit beim Rendering der jeweiligen View.

#### Integration-Test-Helpers an Enum-Schema angepasst

* `IncidentRepositoryIntegrationTest::createIncidentRaw()` schrieb status via
  raw DBAL-`UPDATE`-Statement mit Legacy-Strings (`'open'`, `'investigating'`,
  `'in_progress'`). Nach Enum-Migration warf `IncidentStatus::from('open')`
  beim Rehydrate `ValueError`. Durch sauberes Mapping legacy → enum-case
  ersetzt — keine raw-DBAL-Update mehr nötig.
* `RiskRepositoryIntegrationTest`: fehlender `use DateTime;` Import.

#### Test-Daten-Bereinigung

* `DashboardStatisticsServiceTest` + `SiemExportServiceTest`:
  `IncidentStatus::tryFrom('open')` (lieferte `null`) → `IncidentStatus::Reported`.

**Lokale Suite:** 4155 Tests, 12185 Assertions, 0 Errors, 0 Failures.

## [3.2.1] — 2026-04-27

### Patch-Release: Sample-Data-Import komplett überarbeitet + kritischer TenantFilter-Bug behoben

v3.2.0 hatte zwei strukturelle Issues, die das Sample-Data-Modul für reale Nutzer
unbenutzbar machten und potentiell andere tenant-gefilterte Bereiche beeinflussten.
v3.2.1 bringt 47 Folge-Commits aus einem ausgedehnten Diagnose- und Fix-Sprint zusammen.

**v3.2.0 ist als kaputt markiert und wurde aus den Releases entfernt.**

#### TenantFilter — kritischer SQL-Filter-Bug (5cd4ab5f)

`Doctrine\ORM\Query\Filter\SQLFilter::getParameter()` liefert den Wert bereits
quotiert vom Connection-`quote()`. Der Sentinel-String `'null'` (vom
`TenantFilterSubscriber` für super-admins ohne Tenant gesetzt) kam darum als
SQL-Fragment `'null'` zurück, nicht als nackter String. Der bisherige Vergleich
`=== 'null'` schlug fehl, der Filter generierte:

```sql
WHERE tenant_id = 'null'
```

Das matcht nie eine Integer-Spalte. Konsequenz: jeder authentifizierte User
ohne explizites Tenant (oder im Default-Tenant-Fallback) bekam Tenant-gefilterte
Tabellen leer zurück — nicht nur Sample-Data, sondern potentiell auch
Risiken/Audits/Schulungen-Listen je nach User-Setup. Im Sample-Data-Index hieß
das: alles als „nicht importiert" markiert, Aktionen-Spalte leer.

Fix: outer Quotes vor der Sentinel-Prüfung trimmen, leeren String als zweite
Bypass-Form akzeptieren. CLI war nie betroffen (kein Subscriber → kein
Parameter → InvalidArgumentException-Branch greift sauber).

#### Sample-Data-Import — vollständig überarbeitet

Der ursprüngliche Import-Service hatte mehrere stille Failure-Modes, die in
unterschiedlichen Kombinationen sichtbar wurden. Alle gefixt:

* **Date-Type-Detection** (55c7cdef): Setter-Reflection allein reicht nicht —
  Doctrine-Column-Type wird jetzt aus `ClassMetadata::fieldMappings` gelesen.
  `DATE_MUTABLE` → `DateTime`, `*_immutable` → `DateTimeImmutable`. Brach
  Sample 8 (Schulungen).
* **Enum-String-Konversion** (71461740): YAML liefert Strings (`'high'`,
  `'critical'`), Setter erwartet `BackedEnum`. Reflection auf Setter, dann
  `$enumClass::tryFrom($value)`. Brach Sample 5 (Incidents).
* **Idempotenz: Merge statt Skip** (4782cca2): Bestehende Entities mit gleichem
  Natural-Key werden jetzt mit YAML-Daten gemerged statt verworfen. Verhindert
  „orphan-with-NULL-asset"-Szenarien aus früher fehlgeschlagenen Imports.
* **Singular-Aliase + Plural→Singular-Map** (b88a56d9): YAML-Top-Level-Keys
  sind plural (`data_breaches`, `people`, `processing_activities`),
  `referenceNaturalKeys()` keys singular. `rtrim('s')` brach bei irregulären
  Plurals → Idempotenz-Check fehlte → Duplicate-Key-Constraint. Brach Sample 11
  (Datenschutzverletzungen) und 20 (Personen).
* **EM-Reset zwischen Samples** (71461740): `ManagerRegistry::resetManager()`
  nach Constraint-Violation, Tenant + User auf frischem EM neu binden.
  Verhindert Cascade-Failure nach einem fehlgeschlagenen Sample.
* **Idempotente Tracking-Rows** (429fe47f): Re-Import des selben Samples
  erzeugt nicht mehr neue Tracking-Records pro Klick (vorher: 130 Rows für
  10 Assets). Lookup vor `persist`.
* **Backfill-Pass für Refs** (cd487aa5): Nach jedem `importSampleData()`
  iteriert alle bisherigen Tracking-Rows, lädt das zugehörige YAML, setzt
  vorher unauflösbare `ref:`-Felder jetzt auf, falls Ziel-Entity inzwischen
  importiert wurde. User kann Samples in beliebiger Reihenfolge importieren
  und Beziehungen werden nachträglich geknüpft.
* **Unresolved-Refs im Flash** (169471c3): Wenn `ref:asset:X` nicht aufgelöst
  werden kann, taucht das jetzt explizit im Result-Message auf statt still
  in der Log-Datei.

#### Sample-Data-Purge — robuste Cleanup-Pipeline

Der Purge-Pfad hatte ähnliche Cascade-Issues + FK-Order-Probleme:

* **Reverse-Index-Reihenfolge** (c7e75a68): BCPlans (Sample 15) vor
  BusinessProcess (Sample 2) löschen, sonst FK-Violation.
* **Per-Entity-Flush + EM-Reset auf Remove** (283e0fad): Single FK-Failure
  reißt nicht mehr alle übrigen Entities mit.
* **Cascade-Delete für Orphan-FK-Blocker** (da465f3d): FK-Violation-Message
  wird geparst, das blockierende Child-Row direkt per raw-SQL gelöscht,
  dann Retry. Mehrstufige FK-Ketten werden in bis zu 3 Iterationen abgebaut.
* **Orphan-Tracking-Cleanup** (ce40005c): Tracking-Rows die auf nicht mehr
  existierende Entities zeigen, werden nach jeder Purge-Pass entfernt.
  Verhindert die Sample 2 = 15-statt-10 Inflation aus mehrfachen Purge-Läufen.
* **Retry-Pass** (94fe6f27): Failed `Class#Id` aus den Per-Sample-Errors
  parsen, am Ende erneut versuchen wenn alle Dependencies durchgelaufen sind.

Neuer Console-Command `app:sample-data:purge` exposiert die komplette Pipeline
mit Dry-Run-Option. Hidden Diagnose-Command `app:debug:sample-data-status`
zeigt die UI-Sicht aus Repository-Perspektive zur Verifikation.

#### TISAX/DORA — Status + UI-Removal

Command-basierte Sample-Loader (`app:load-tisax-requirements`,
`app:load-dora-requirements`) schreiben keine Tracking-Rows. Die UI zeigte
sie darum permanent als „nicht importiert", auch wenn 114 TISAX- + 131
DORA-Anforderungen längst geladen waren.

Fix:

* Status (f24fd051, c1c273b3): Command-Sample → Framework-Code-Lookup
  (`'TISAX'`, `'DORA'`) → ComplianceRequirement-Count → Badge zeigt
  „114 importiert".
* Removal (d19666a1): UI-Entfernen-Button für Command-Samples, Action-Route
  cascade-deletet das Framework (`cascade: ['remove']` auf der OneToMany).

#### UI-Hilfsmittel

* **Select-All-Checkbox** (92686b86): Master-Checkbox in der ersten Spalte
  toggelt alle aktiven Sample-Checkboxen. Eigener Stimulus-Controller
  `select_all_controller.js` mit defensiv resolvierter `this.element`-Referenz
  (umgeht eine Stimulus-Build-Quirk in dieser App).
* **Entfernen-Button bei jedem Sample mit Tracking-Rows** (17592146): Vorher
  nur sichtbar wenn `imported=true` — das versteckte den Button bei
  Status-Drift-Szenarien. Jetzt: Button erscheint sobald `count > 0`.
* **Turbo-Cache disabled** (b30e2d6e): Status-Badges hängen vom Live-DB-Stand
  ab, nicht von Turbo-Snapshot vor dem Import.
* **Defensive Int-vs-String-Lookup** (b71dcb1e): Doppelte Lookup-Tabellen
  für `$importedCounts` (int- und string-keyed) damit DB-Driver-Quirks
  keine UI-„nicht importiert"-Fehlanzeige produzieren.

#### Admin Health-Checks

`b229bc70a` und `fb5cb724` bringen 8 weitere Health-Checks ins Data-Repair-
Modul (Duplicate-Merge, Risk-Health, GDPR/ISO Compliance-Checks). `e2549576`
ergänzt Tier 2+3 Checks und räumt offene TODOs auf.

#### Bug-Fixes (kleinere)

* `5cd4ab5f` TenantFilter: siehe oben (kritisch)
* `c6848b44`, `ee46bd05`, `3dfc40d3`, `f8dcddd6`: temporäre Diagnose-
  error_logs zur Bug-Hunt — wieder entfernt.
* `303347c2`, `6c05ab0e`: zwei i18n-Tippfehler im `admin.de.yaml`,
  YAML-Parse-Fehler verursacht.
* `b0a7a44f`: Patch-Show + Help-Sidebars + RiskStatus-Enum-Cases
  re-apply nach Linter-Revert.
* `d3599cad`: 27 Templates für PHP-Enum-Integration aktualisiert.

#### Verifikation

End-to-End Test gegen frische dev-DB (Mordor Inc.):

| Sample | YAML | DB |
|---|---|---|
| Beispiel-Assets | 10 | 10 ✓ |
| Beispiel-Risiken | 10 | 10 ✓ |
| Beispiel-Geschäftsprozesse | 10 | 10 ✓ |
| TISAX Requirements | — | 114 ✓ |
| DORA Requirements | — | 131 ✓ |
| Beispiel-Incidents | 7 | 7 ✓ |
| Beispiel-Dokumente | 9 | 9 ✓ |
| Beispiel-Schulungen | 8 | 8 ✓ |
| Beispiel-Management-Reviews | 4 | 4 ✓ |
| Beispiel-Verarbeitungstätigkeiten | 8 | 8 ✓ |
| Beispiel-Datenschutzverletzungen | 5 | 5 ✓ |
| Beispiel-Einwilligungen | 6 | 6 ✓ |
| Beispiel-DPIAs | 4 | 4 ✓ |
| Beispiel-Betroffenenanfragen | 6 | 6 ✓ |
| Beispiel-BC-Pläne | 5 | 5 ✓ |
| Beispiel-BC-Übungen | 6 | 6 ✓ |
| Beispiel-Krisenstäbe | 3 | 3 ✓ |
| Beispiel-Lieferanten | 10 | 10 ✓ |
| Beispiel-Standorte | 5 | 5 ✓ |
| Beispiel-Personen | 8 | 8 ✓ |
| Beispiel-Interessierte Parteien | 8 | 8 ✓ |
| Beispiel-ISMS-Ziele | 6 | 6 ✓ |
| Beispiel-Risikoappetit | 4 | 4 ✓ |

Risk → Asset Verknüpfungen: 9/10 (1 Risk ist semantisch person-basiert).

## [3.2.0] — 2026-04-26

### Headline-Feature: MRIS-Integration v1.5 — Gen-AI-Bedrohungslage im ISMS

Out-of-the-box-MRIS-Klassifikation aller 93 ISO-Annex-A-Controls + 13 Mythos-
Härtungs-Controls (MHC) als zweite Control-Schicht im Statement of Applicability.
Macht Gen-AI-getriebene Wirksamkeitsverluste bestehender Controls sichtbar und
schließt sie über einen priorisierten Zusatzkatalog.

**Wirtschaftlicher Hebel** (laut CM- + Senior-Consultant-Persona-Review):
- **Compliance-Manager intern:** ~11 FTE-Tage Quartal-Ersparnis bei 27001+NIS2-Bestand
- **Senior-Berater extern:** 22–34 Tage Ersparnis pro Kundenprojekt
- **Zusätzliche EU-AI-Act-Compliance:** AI-Agent-Inventar erfüllt gleichzeitig
  AI Act Art. 6/9-16 + ISO 42001 + MRIS MHC-13 + ISO 27001 A.5.16/A.8.27
  (eine Datenbasis, vier Frameworks)

### MRIS-Integration v1.5 (CC-BY-4.0-Ableitung Peddi 2026)

Komplette Integration des MRIS-Frameworks (Mythos-resistente Informationssicherheit
v1.5 von Richard Peddi, CC BY 4.0) in 5 Phasen + Plan-Vollerfüllung-Batch +
zusätzliche Erweiterungen.

**Plan-Erweiterungen (vom Ursprungs-Plan ausgenommen, aber priorisiert eingebaut):**

- **Mythos-Resilience-Indikator (MRI)** — aggregierter Score aus 5 gewichteten
  Dimensionen (Standfest 25 % / Reifegrad 30 % / Reibung-Inverse 20 % / Manual-KPIs
  15 % / AI-Doku 10 %). Prominent als „internes Management-Indikator" mit
  Audit-Disclaimer ausgewiesen — MRIS v1.5 selbst definiert kein Aggregat.
  Dekomposition pro Dimension immer sichtbar (kein Black-Box).

- **Auto-Re-Mapping bei MRIS-Versions-Updates** —
  `app:mris:migrate-version --from=v1.5 --to=v1.6 --apply` zeigt Diff
  (added/removed/renamed/maturity_changed), Soft-Delete via `dataSourceMapping`-
  JSON-Marker (`lifecycle_state=deprecated`), Audit-Log via `AuditLogger::logCustom`.
  Dry-Run als Default-Sicherung, `--apply` explizit erforderlich.

- **MRIS-Glossar** unter `/mris/glossar` — lädt `fixtures/mris/help-texts.yaml`
  und zeigt 20 Glossar-Einträge mit Definition + 9001-Analogie + Norm-Quelle.
  Sortier- und filterbar via Stimulus-Controller.

- **3 MRIS-Wizards:**
  - `/mris/wizard/pure-friction` — 5-Schritt-Routine für Reine-Reibung-Controls
  - `/mris/wizard/maturity-evidence` — Evidence-Checklist pro MHC (alle 13)
  - `/mris/wizard/ai-risk-class` — 12-Tools-Tabelle + 4-Step-Decision-Flow

- **AI-Agent-Form-Variante** — `AssetType` um 9 AI-Felder erweitert,
  `assetType=ai_agent` triggert dynamische Sichtbarkeit via
  `conditional_fields_controller`. Stimulus `asset_form_controller.js`
  schlägt Risikoklasse aus 12-Tools-Matrix vor (Provider-Match,
  case-insensitive, nur wenn Klasse leer).

- **Branchen-Baseline-UI** unter `/mris/baselines` — Card-Grid mit Anwenden-Button,
  Dry-Run-Vorschau, ROLE_MANAGER + CSRF.

- **Tenant-Settings-UI** für `mris_kpis_enabled` — Checkbox in
  `admin/tenants/form.html.twig`, persistiert via Settings-Merge.

- **KPI-Trend-Sparklines** — `KpiSnapshotRepository::findRecentByTenant(90)`
  liefert Trend-Daten, Inline-SVG-Polylines an jeder auto-KPI-Tile.

- **Mega-Menu-Erweiterung** — MRIS-KPIs + AI-Agent-Inventar +
  MRIS-Baselines + MRIS-Glossar im Compliance-Panel.



**Neue Module:**

- **MRIS-Library** (Phase 1): ComplianceFramework `MRIS-v1.5` mit 13 MHCs +
  Forward/Reverse-Mappings auf ISO 27001:2022 (44 Pairs je Richtung, 100 % Reciprocity).
- **Annex-A-Klassifikation** (Phase 1): 4 Kategorien (Standfest/Degradiert/Reibung/
  Nicht-betroffen) auf allen 93 ISO-Annex-A-Controls (S=29/T=37/R=4/N=23).
  Schema-Migration + Seed-CSV + Console-Command `app:mris:seed-classification`.
- **Reifegrad-Tracking** (Phase 2): MaturityService mit Soll/Ist-Delta-Berechnung,
  Audit-Log bei jeder Stufen-Änderung. UI: SoA-Filter + MRIS-Spalte + Reibung-
  Warning + MHC-Detail-Page mit Reifegrad-Tabelle + interaktivem Setzen.
- **Mythos-KPI-Block** (Phase 3): 8 KPIs aus MRIS Kap. 10.6 unter `/mris/kpis`.
  3 automatisch berechnet (MTTC, Phishing-MFA, Restore-Test), 5 manuell mit
  Eingabeformular. Tenant-Featureflag `mris_kpis_enabled`.
- **AI-Agent-Inventar** (Phase 4): Asset-Subtyp `ai_agent` mit 9 Pflichtfeldern
  für EU AI Act Art. 6/9-16 + ISO 42001 Annex A + MRIS MHC-13 + ISO 27001
  A.5.16/A.8.27. Inventar-Seite `/ai-agents` mit Compliance-Vollständigkeit
  pro Agent + Hochrisiko-Audit-Helfer.
- **Branchen-Baselines** (Phase 5): 4 vorkonfigurierte Soll-Stufen-Profile
  (KRITIS, Finance/DORA, Automotive/TISAX AL3, SaaS/CRA).
  Console-Command `app:mris:apply-baseline --tenant=X --baseline=NAME`.

**Persona-Reviews & Hilfetexte:**

- Junior-ISB-Persona-Befragung: 20 Verwirrungspunkte + 3 Top-Blocker
  (`docs/MRIS_HELP_TEXTS_JUNIOR_REQUEST.md`)
- Senior-Consultant-Persona lieferte `fixtures/mris/help-texts.yaml`:
  20 Items mit Tooltip + Inline-Help + Glossar (DE+EN, 9001-Analogien)
  + Pure-Friction-Decision-Routine + Reifegrad-Evidence-Checklist pro MHC
  + AI-Risiko-Entscheidungsmatrix für 12 typische Tools
- CM- + Senior-Consultant-Doppelreview als Plan-Validation
  (`docs/MRIS_INTEGRATION_PLAN.md`)

**Schema-Änderungen:**

- `control.mythos_resilience` VARCHAR(20) NULL + `mythos_flanking_mhcs` JSON NULL
  (Migration Version20260426132821)
- `compliance_requirement.maturity_current/target/reviewed_at`
  (Migration Version20260426145831)
- `asset` + 9 nullable AI-Agent-Felder
  (Migration Version20260426153940)
- Tenant-Settings: `settings.mris.kpis_enabled` + `settings.mris.manual_kpis[id]`

**KPI-Trendlinien:** `KpiSnapshotCommand` snapshot't 3 MRIS-auto-KPIs daily —
Trendlinien-Daten für künftige Sparklines.

**SoA-PDF-Export:** Neue Spalte „MRIS" mit Mythos-Kategorie + flankierenden
MHCs + CC-BY-4.0-Quellenangabe.

**Permissions:** ROLE_MANAGER auf Reifegrad-Set-Endpoint + Manual-KPI-Save.

**Navigation:** Mega-Menu-Compliance-Panel zeigt MRIS-KPIs + AI-Agent-Inventar.

**Tests:** 43+ neue PHPUnit-Test-Cases (Maturity 8 + KPI 8 + Classification 9 +
AI-Agent-Inventory 7 + Baseline 13). Alle grün.

**Quellenangabe (CC-BY-4.0) durchgängig:**

  Peddi, R. (2026). MRIS — Mythos-resistente Informationssicherheit, v1.5.
  Lizenz: Creative Commons Attribution 4.0 International (CC BY 4.0).
  Original-Whitepaper: `docs/MRIS- mythos-resistente infosec.pdf`

### Aurora v4 — flächendeckende Migration finalisiert (Wellen 1–8, ~3000 Site-Konvertierungen)

**Audit-Endstand** (gemessen via `scripts/quality/check_aurora_v4.sh`):

| Aurora-Komponente | Verwendungen | Bootstrap-Restbestand | Reduktion |
|---|---:|---:|---:|
| `fa-icon--*` | 729 | bi bi-* = 398 (alles generic UI) | -1700 ISMS-Domain-Icons |
| `fa-cyber-btn` | 356 | btn btn-* = 20 (setup/security/qr) | -658 |
| `fa-status-pill` | 56 | badge bg-* = 51 (Stimulus-controlled BC) | -87 |
| `fa-aurora-surface` | 55 | — | flächendeckend auf `<main>` |
| `fa-section` | 43 | — | via `_card`-Macro + Markup |
| `fa-alert` | 33 | alert alert-* = 15 (Modal-Forms) | -203 |
| `fa-empty-state` | 28 | — | mit Alva-Mood + CTA |
| `fa-rag-card` | 11 | — | Dashboard-RAG-Pattern |
| Hardcoded Hex in CSS | **0** | — | komplett auf Aurora-Tokens |

**Token-Layer komplettiert** (`fairy-aurora.css`):
- Tints: `--success-tint`, `--warning-tint`, `--danger-tint`, `--info-tint` (light + dark)
- RGB-Komponenten: `--primary-rgb`, `--accent-rgb`, `--success-rgb`, `--warning-rgb`, `--danger-rgb` (für rgba()-Komposition)
- Shadows: `--shadow-sm`, `--shadow-md`, `--shadow-lg`, `--shadow-up-sm`, `--shadow-up-md` (light + dark mit primary-Aura)
- Print-Tokens: `--print-fg`, `--print-bg`
- `--surface-translucent` für Overlay-on-Gradient

**Neue Aurora-Komponenten:**
- `.fa-rag-card` mit `--green/--amber/--red` Modifiern für RAG-Status-Kacheln
- `.fa-data-table` Aurora-themed Tabelle (ersetzt `.table.table-bordered`)
- `.fa-issue-list` semantisch statt `<ul><li class="text-warning">`-Pattern
- `.fa-trend` mit `--up/--up-bad/--down/--down-bad/--flat` für KPI-Trends
- `.fa-glyph-size-{sm,md,lg,xl}` Bootstrap-Icon-Größen-Utilities (kein Konflikt mit `.fa-icon` Mask-Base)
- `.progress-h-{4,5,10,18,24,25}` ergänzt (Reihe komplett: 4/5/6/8/10/18/20/24/25/30/40)

**Neue Macros:**
- `_fa_icon.html.twig` (Aurora-Mask-Icons, 77 ISMS-Domain-Icons)
- `_fa_kpi_card.html.twig` (Dashboard-KPI-Tile mit Trend-Indicator)
- `_fa_rag_card.html.twig` (R/A/G-Status-Tile)
- `_fa_btn.html.twig` (Aurora-Native-Button-Macro)
- `_fa_alert.html.twig` (Aurora-Native-Alert-Macro)
- 77 SVG-Icons in `assets/icons/` + `fairy-aurora-icons.css`

**`.fa-cyber-btn` Default-Sizing**: Base-Klasse hat jetzt padding/font-size/border-radius wie `--md` Default, plus `:where()`-Safety-Net (zero-specificity-defaults für variant-lose Buttons).

**TomSelect-Override mit `!important`**: Tom-Select-Lib lädt CSS via Stimulus-Controller-Import (Source-Order-Konflikt). Aurora-Tokens werden durchgesetzt.

**Bug-Fixes während Migration:**
- Twig-3 Macro-Scope (`_fa_empty_state`, `_fa_hero`): file-top `{% import '_alva' as alva %}` propagiert nicht in eigene macros → ersetzt durch `{% include %}`-Pattern + file-body in `_alva.html.twig`.
- Embed-Block-Scope: 50 Sites in 39 Templates wo `_fa_*`-Macro-Calls inside `{% block %}` von `{% embed %}` ohne block-Import → Imports inline ergänzt.
- `_fa_alert.body` mit Twig-im-String-Literal (132 Sites): String-literal Twig wird nicht interpoliert → konvertiert zu `{% embed %}` mit `{% block alert_body %}`.
- `fa-cyber-btn--block` (BS-Naming-Carry-Over) → `fa-cyber-btn--full` (Aurora-Spec-Name).
- 3 fehlende CSS-Klassen ergänzt: `.fa-status-pill--lg`, `.fa-alert--dismissible`, `.fa-alert--with-alva`.
- GDPR-Wizard `.gdpr-wizard .form-check-label`: `var(--text-primary, var(--surface))` (dead-token-fallback → unsichtbar) → `var(--fg)`.
- Aurora-Klassen-Audit-Skript `scripts/quality/check_aurora_v4.sh` als Living-Audit + Stylelint-Hex-Verbot via `declaration-property-value-disallowed-list`.

**Skip-Kategorien (intentional Bootstrap):**
- `templates/setup/`, `templates/setup_wizard/`, `templates/security/` (eigener Style)
- Email/PDF/QR/Print-Templates
- `.btn-close`, `.dropdown-toggle`, `.btn-link`-Patterns wo kein Aurora-Pendant
- Modal-Footer-Buttons in einigen komplexen Stimulus-Containern
- 5 TODO-Kommentare für PHP/JS-driven dynamic icon switches

**Welle-Übersicht:**
- Welle 1-3: Token-Layer + Macro-Bridges + Dashboard-Primitives
- Welle 4: Lead-Pages-Buttons (E4) + Alert-Migration (E5) + Hex-Cleanup (E6)
- Welle 5: Badges (J1) + Detail-Page-Buttons (J2) + Inline-Style-Cleanup (J3)
- Welle 6-7: Admin/Profile-Buttons (K1) + Alert-Round-2 (K2) + _macros/-Library (N1) + Restmodule (N2)
- Welle 8: Final btn-* (P1, 579 Buttons) + bi-* Domain-Audit (P2, 449 Icons)

## [3.1.0] - 2026-04-26

### Mapping-Quality-Library: 24 Files / 314 Pairs / 100% Reciprocity

Cross-Framework-Mapping-Qualität messbar gemacht. Komplette DE/EU-Coverage mit 12 reziproken Mapping-Paaren und CISO-Coverage-View.

**Schema (Migration 20260425145800):**
- `compliance_mapping` erweitert um `lifecycle_state`, `provenance_source/url`, `methodology_type/description`, `relationship` (equivalent/subset/superset/related/partial_overlap), `gap_warning`, `audit_evidence_hint`, `mqs_breakdown` (JSON)

**Services:**
- `MappingQualityScoreService` — MQS (0-100) aus 6 gewichteten Dimensionen: Provenance 25 % / Methodology 20 % / Confidence 15 % / Coverage 15 % / Bidirectional 15 % / Lifecycle 10 %
- `MappingValidatorService` — YAML-Library-Validation (Schema, Provenance-Pflicht, Methodology-Pflicht, Coverage-Warnung, Source/Target-Existenz)
- `MappingLifecycleService` — State-Machine draft → review → approved → published; 4-Augen-Review für approved, ROLE_CISO-Sign-Off für published; Audit-Log pro Transition
- `MappingLibraryLoader` — lädt `fixtures/library/mappings/*.yaml` mit Validation + MQS-Compute
- `ComplianceMappingRepository::coverageBetweenFrameworks()` und `reciprocityCoherence()`

**Console-Commands:**
- `app:mapping:check-reciprocity` — Bidirectional-Coherence-Audit (CI-fähig)
- `app:mapping:library:import` — YAML-Library-Import
- `app:mapping:library:smoke-test` — End-to-End-Test mit Stub-Frameworks und MQS-Übersicht

**Admin-UI `/admin/mapping-quality`:**
- Liste mit Filter (state, min_score), Stats-Cards, Recompute-Button
- Detail mit 6-Dimensionen-Aufschlüsselung
- Lifecycle-Transition-Buttons mit Reason-Feld + 4-Augen/CISO-Berechtigungs-Checks
- Coverage-View `/admin/mapping-quality/coverage/all` (CISO-Aggregat-Tabelle pro Framework-Paar mit Coverage % und Confidence-Verteilung)
- Mega-Menu-Eintrag

**24 Mapping-Library-Files (12 Forward/Reverse-Paare, 314 Pairs total):**

DE national:
- BSI IT-Grundschutz ↔ ISO 27001:2022 (15+15)
- BSI C5:2020 ↔ ISO 27001:2022 (15+15)
- BSI C5:2020 ↔ BSI IT-Grundschutz (15+15)
- BSI IT-Grundschutz ↔ NIS2 Art. 21 (11+10)
- KRITIS-DachG ↔ NIS2-UmsuCG (8+7)

EU regulatorisch:
- ISO 27001:2022 ↔ NIS2 Art. 21 (12+10)
- ISO 27001:2022 ↔ DORA (15+14)
- BAIT ↔ DORA (15+13)
- NIS2 Art. 21 ↔ DORA (10+8)
- ISO 27001:2022 ↔ TISAX VDA-ISA-6 (15+15)
- GDPR ↔ ISO 27701:2025 (16+16, ISO Annex D offiziell)
- EU AI Act ↔ ISO 42001 (10+9, lifecycle review)

**Reciprocity:** 24 von 24 Directions = 100 % Coherence. Forward/Reverse-Paare mirroring jede Source/Target-Beziehung mit invertierten Relationships (subset↔superset, equivalent↔equivalent, partial_overlap↔partial_overlap, related↔related).

**Top-MQS-Scores:** iso27701→gdpr 99.7, tisax→iso 99.0, nis2→bsi 97.3, nis2→dora 97.0, nis2→iso 95.9, iso→bsi 93.0, bsi→bsi-c5 91.7, iso→bsi-c5 91.7.

**Lifecycle-State:** 22× published, 2× review (eu-ai-act ↔ iso42001 noch reifend).

**Tests:** 27 neue Test-Cases (MQS-Service 6 + Validator 7 + Lifecycle 7 + Loader 7).

**Dokumentation:** `LIBRARY_FORMAT_VISION.md` + `MAPPING_QUALITY_VISION.md` + `MAPPING_QUALITY_ANALYSIS.md` + `QUICKSTART_MAPPING_QUALITY.md`.

### Aurora v4.1 Final-Wellen — Sprints D, E1-E6, F, G, H, J1-J3, K1-K3, M1-M2, N1-N2, P1-P2, Q + Icon-System

**v4.1-Compliance app-weit erreicht — null Bootstrap-Color-Utilities, null bi-* Icons, null hardgecodete Hex-Farben außerhalb der Token-SoT.**

Mehrwöchige Mass-Migration aller noch verbliebenen Bootstrap-/Bootstrap-Icons-/Inline-Style-Reste auf Aurora-Native-Komponenten. Über 600 Templates angefasst, 18 Sprints (D bis Q) abgeschlossen. Aurora ist damit nicht mehr „Bridge auf Bootstrap", sondern eigenständige UI-DNA.

**Icon-System (Sprint A + Sprint Q + Folge-Wellen):**

- **89 neue Icons** aus design_system v4.1 in App-Tree gezogen (Sprint Q): `nav/`, `ui/`, `util/` Domains
- **20 weitere Icons** in Folge-Welle: `clock`, `calendar`, `lightbulb`, `flag`, `shield-check`, `arrow-*`, etc. — **186 SVGs total** im Aurora-Icon-Set
- **Mass-Migration `bi-*` → `fa-icon--{nav,ui,util}-*`** über ~380 Sites (Sprint Q + Folge)
- **39 broken Icon-Refs** repariert, mass-mapped auf existierende Klassen
- **21 ungenutzte Compliance-Icons adoptiert** (Δ `bi-*` 431→394) — kein toter Code mehr
- Icon-Size-Utilities (.fa-icon--xs/sm/md/lg/xl) ersetzen alle inline `font-size`-Styles auf `bi-*` Glyphen

**Komponenten-Robustheit (Sprint E + N + P):**

- **`_fa_alert` embed-block-scope-Fix** — 29 Templates: `render(body: '{{ … }}')` funktionierte in Twig 3 nicht zuverlässig, jetzt durchgängig auf `embed` mit `block body` umgestellt
- **`_fa_empty_state` + `_fa_hero` Twig-3 macro-scope-Fix** für Alva-Render — Macro-internes `{% set %}` mit `props is defined`-Guard
- **`fa-cyber-btn` safety-net via `:where()`** — variantless Buttons (ohne `--md`/`--ghost`-Modifier) bekommen jetzt brauchbares Default-Padding/Size, statt unsichtbar zu rendern
- **TomSelect-Override mit `!important`** — Aurora-Tokens schlagen jetzt zuverlässig die Lib-CSS der TomSelect-Library
- **2 unfertige Twig-Conditionals beim P1-Migrate** repariert

**Mass-Migrationen (Sprint K2 + J + M + N + P):**

- **`alert alert-*` → `_fa_alert` / `fa-alert`** über **100 Templates** (Sprint K2)
- **`btn-*` / `btn-outline-*` / `btn-link` → `fa-cyber-btn`** in mehreren Wellen (Sprint J2, K1, M1, M2, P1 final, plus `btn-link` → `fa-cyber-btn--ghost`)
- **`bi-*` → `fa-icon--*`** in Domain-Audits (Sprint E1-E5, P2 final): home/dashboards/admin, asset/incident/document, ISMS-Domain
- **Badge-Mass-Migration** → `_badge` / `fa-status-pill` (Sprint J1)
- **`.kpi-card` / `variant: 'kpi'`** auf CISO-Dashboard durch `_fa_feature_card` mit Icon-Chip ersetzt
- **Inline-Style-Cleanup + Hex-Cleanup Round 2** (Sprint J3, E6) — Hex-Farben außerhalb Token-SoT eliminiert
- **`fa-aurora-surface` flächendeckend** als Opt-in-Page-Atmosphäre (Sprint C)
- **Card-Konsolidierung Round 2 + 3** (Sprint G, K3) — duplizierte Card-Header-Regeln entfernt, Aurora-Spec gewinnt durchgehend per Source-Order

**Governance / CI:**

- **Stylelint-Hex-Verbot in 14 Color-Properties** app-weit (Sprint H, Phase 11) + Audit-Tooling
- **Allow-List**: `fairy-aurora.css` (Token-SoT), `alva.css` (SVG-Brand-Fills), Vendor-Bootstrap.css

### fa-entity-card + fa-entity-badge Komponente — NEU

**Wiederverwendbare Listen-Item-Card für 7 Entity-Types als Aurora-Native-Alternative zu Ad-hoc-Card-Markup.**

Listen-Item-Card-Component mit Entity-Icon (links), Title, Meta-Zeile, Status-Pill (rechts) für die häufigsten Listenseiten. Spezifische Varianten für **finding** / **nonconformity** / **risk** / **control** / **evidence** / **incident** / **audit** mit semantisch passenden Icons + Border-Akzenten. Plus **10 Entity-Badge-Variants** (zusätzlich `asset`, `policy`, `training`).

- **171 CSS-Zeilen** aus Aurora-v4.1-Spec in `fairy-aurora-components.css` portiert
- **2 neue Twig-Macros**: `templates/_components/_fa_entity_card.html.twig` + `_fa_entity_badge.html.twig`
- **Adoption**: `audit_finding/index` + `corrective_action/index` migriert (2 Listen-Pages)
- **Showcase** unter `/dev/design-system` (Live-Preview + Copy-Paste-Snippets)

### Schema- / Migration-Maintenance-UI im Data-Repair — NEU

**Always-on-buttons für Schema-Drift-Recovery aus dem Browser, ohne SSH/CLI.**

Im Data-Repair-Bereich des Admin-Moduls neuer **3-Card-Grid**: Migrations | Schema-Drift | Aktionen. Status-Pills (success / warning / danger) zeigen Drift-Stand auf einen Blick, Buttons sind „always-clickable" auch wenn alles grün ist. Wrappt Doctrine `MigrationStatusCalculator` und reused den existierenden `SchemaHealthService`.

- Neuer **`SchemaMaintenanceService`** wrappt Doctrine + reused `SchemaHealthService`
- **2 neue POST-Routes** (CSRF-guarded, `ROLE_ADMIN`): `app:schema:run-migrations`, `app:schema:reconcile`
- **Destructive-statement-detection** — `DROP TABLE` / `ALTER … DROP COLUMN` werden vor Ausführung markiert und brauchen explizite Bestätigung
- **20 neue Translation-Keys** (DE + EN) im `data_repair`-Domain

### Mapping-Quality-System — NEU

**MQS-Score 0-100, Lifecycle-Tracking, Reciprocity-Check und Provenance-Felder für alle 24 Cross-Framework-Mappings.**

Mapping-Qualität wird ab v3.2.0 nicht mehr „nach Bauchgefühl" bewertet, sondern numerisch über den **Mapping-Quality-Score (MQS)** aus 5 gewichteten Sub-Scores (Coverage, Granularity, Reciprocity, Provenance, Validation). Jedes Mapping hat einen **Lifecycle-State** (draft / review / published / deprecated) und einen Reverse-Mapping-Check, der sicherstellt, dass A→B und B→A sich nicht widersprechen.

- **39 Engineering-Tests grün** (MQS-Service 6 + Validator 7 + Lifecycle 7 + Loader 7 + sonstige)
- **13 Library-Files** mit MQS-Range **71.6–95.9** — NIS2 ↔ ISO **100 % reziprok**
- **Loader-Tests + 3 Reverse-Mappings + CISO-Coverage-View** als ergänzende Wellen
- **Standard-Mappings + 2 weitere Default-Sets** ausgeliefert
- Reciprocity 24/24 = 100 % Coherence (siehe Mapping-Library-Sektion oben)

### 38-Finding UX-Improvement-Sprint

**Sammel-Sprint, der 38 individuelle UX-Findings aus dem Persona-Audit über 27 Module adressiert.**

Findings reichten von „Filter-Chip nicht sichtbar bei aktivem Filter" über „Modal verliert Fokus bei Turbo-Navigation" bis zu „Empty-State zeigt CTA, aber User hat kein Schreibrecht". Commit `b2422287` listet alle 38 Items mit Modul-Bezug.

### Workflow Phase 10 Roadmap

- **14 neue regulatorische Workflows** aus Persona-Audit identifiziert und in `docs/WORKFLOW_REQUIREMENTS.md` Phase 10 dokumentiert
- **Supplier-Workflow auf 5 Steps erweitert** + Reject-Loops zwischen Step 2/3 und Step 4/5

### Compliance-Manager-Audit v2.3 — Score 99/100

**Alle 10 Frameworks erreichen Tool-Status 🟢 (vorher v2.2: 98/100).**

Compliance-Manager-Persona-Audit nach v3.2.0-Featureset re-evaluiert. Fortschritt v2.2 → v2.3:

- **32 FTE-Tage realisiert in 5 Tagen** (durch konsistente Data-Reuse-Architektur über alle neuen Features hinweg)
- **3 genuine Markt-Differenzierung** unter den 10 Frameworks: **EU AI Act**, **ISO 42001**, **MRIS** (kein Wettbewerber hat alle drei out-of-the-box)
- **Top-3 Reuse-Hebel** identifiziert: **MQS** (Mapping-Quality-Score), **MRIS-Reifegrad-Tracking**, **AI-Agent-Inventar** (eine Datenbasis → vier Frameworks)

### Setup-Wizard Performance

**Async-Job-Pattern auf alle Long-Running-Routes ausgeweitet — Wizard fühlt sich auch bei 30s-Schema-Create flüssig an.**

Der Setup-Wizard ist out-of-the-box Erstkontakt mit dem Tool. Lange Spinner ohne Feedback waren ein Ausstiegsfaktor. Mehrere Performance- + UX-Fixes ausgerollt:

- **Async-Job-Pattern** auf allen Long-Running-Routes (`schema-create`, `skip-restore`, `module-save`, etc.) mit Stimulus-Polling-Controller
- **Schema-Create**: transaction-wrap + multi-VALUES-Insert für Migrations-Metadata (~10× schneller bei 80+ Migrations)
- **Bypass Doctrine-Wrapper für DDL** — fixt „no active transaction"-Fehler bei `CREATE TABLE` in MariaDB
- **File-based async-job-status** (Session-Writes nach `fastcgi_finish_request` gehen sonst verloren)
- **`wizard-busy` nutzt `readonly` (nicht `disabled`)** auf Inputs, damit POST-Werte erhalten bleiben
- **Alva-Wait-Animation** auf allen Wizard-Forms (kein blanker Spinner mehr)
- **Stimulus explicit registration** für `async-job` + `wizard-busy` + `alva-dock` (statt nur Auto-Discovery)

### Bug-Fixes

- **`DataIntegrityService`**: `Document.getTitle()` → `getOriginalFilename()` (Title-Property existiert nicht mehr nach Document-Refactor)
- **Sample-Data-YAML-Audit** + snake_case-Resolver für 22 Samples — fixt Inkonsistenz zwischen Fixture-YAML und Entity-Setter-Naming
- **Smart-Setter-Resolver** im Sample-Data-Loader + DateTimeImmutable-Preference (statt mutable `\DateTime`)
- **Compliance-Import**: `form`-Variable an Card-Embed durchgereicht (Twig-Scope-Bug)
- **Docker**: `var/sessions` + `public/uploads` werden jetzt beim Build und Runtime erzeugt — fixt 500er bei frischem Container-Start

## [3.0.0] - 2026-04-25

### Highlights

- FairyAurora v3.0 Design System mit Alva-Charakter (9 Moods)
- **FairyAurora v4.0 Rollout — Aurora-DNA app-weit** (Page-Header, Section, Feature-Card, Empty-State, Hero, Filter-Chip, Alva-Companion-Dock, Form-Theme, Bootstrap-Bridges fuer Buttons/Alerts/Badges/Pagination/Tom-Select)
- 23 Compliance-Frameworks mit Cross-Framework-Mapping und transitiver Compliance
- Konzernstruktur mit Holding/Tochter-Governance und Vererbung
- 171-Begriff ISMS-Glossar mit ISO 9001 Analogien
- OWASP 2025 Final Security Audit (Score: 7.55/10)
- Backup/Restore mit Verschluesselung, Tenant-Scoping, Best-Effort-Mode und Repair-Tool
- 0 fehlende Uebersetzungen in DE und EN (87 Domains)

### FairyAurora v4.0 — Onboarding-DNA app-weit

- 6 neue Aurora-Primitive (Twig-Macros): `fa-page-header`, `fa-section`, `fa-feature-card`, `fa-empty-state`, `fa-hero`, `fa-filter-chip`
- `fa-aurora-surface` Opt-in-Utility bringt die Setup-Wizard-Atmosphaere auf jede Modul-Seite (4 Varianten: default/subtle/hero/dots)
- **Phase-6-Rollout**: 48 Modul-Index-Seiten migriert auf `fa-page-header` + `fa-aurora-surface` Wrapper
- **Alva-Companion-Dock**: site-wide kontextueller Helper via `window.alvaBus` Event-System, 9 Moods, User-Setting fuer on/off/size/position, Hooks auf Upload + Turbo-Submit + Empty-State
- **fa-cyber-input Form-Theme** als Symfony-Default: monospace-uppercase Label ausserhalb Frame, 4-Corner-Tick-Marks, Focus-Glow. Login, Auth und alle FormBuilder-Forms visuell unified.
- **Aurora-Bridges** fuer Bootstrap-Utility-Klassen: `.btn.btn-*` / `.btn-outline-*` → fa-cyber-btn Visual, `.alert.alert-*` → fa-alert, `.badge.bg-*` → fa-status-pill, `.pagination`, `.dropdown-menu`, Tom-Select `.ts-*`. Templates unveraendert, Bootstrap-Klassen bekommen Aurora-Tokens.
- **Legacy-Hex-Cleanup**: 179 Hex-Hardcodes reduziert auf 3 (alle in SVG-Brand-Fills legitim)
- **Stylelint-Hex-Ban**: `npm run stylelint` blockt Hex in 14 Color-Props, Governance-CI-Hook vorbereitet
- **Living-Styleguide** `/dev/design-system` rendert alle 6 fa-* Komponenten + Alva-9-Mood-Matrix + 15 Token-Swatches mit Copy-Paste-Snippets (dev-env only)
- **Legacy-Cleanup**: 487 Zeilen redundante `.btn-*`/`.alert-*`/`.badge-*` Color-Overrides entfernt aus `app.css` / `dark-mode.css` / `components.css`. `dark-mode.css` reduziert auf echte Dark-Effekte (Icon-Glow), keine Color-Swaps mehr
- Neue Design-Tokens: `--pattern-opacity-*`, `--brand-gradient-soft/line`, `--alva-dock-offset-*`, `--alva-z`
- Disaster-Recovery-Runbook (DE) + Backup-Architecture-Reference (EN) in `docs/operations/`

### FairyAurora v3.0 Design System

- Komplett neues Token-basiertes CSS-Design-System (Aurora-Tokens)
- Alva-Charakter mit 9 Stimmungen (idle, thinking, happy, alert, ...)
- Dark Mode: 108+ Templates migriert, alle hardcoded Farben entfernt
- Bootstrap vor Aurora geladen (Cascade-Reihenfolge korrigiert)
- Card-Header-Farben normalisiert (keine kosmetischen bg-primary/success mehr)
- Chart.js Farben auf Aurora-Tokens
- WCAG 2.2 AA Kontraste durchgehend
- Print-Stylesheet mit neutralen Farben
- Responsive Breakpoint-Overrides
- 20+ neue Twig-Macros (Brand, CyberButton, StatusPill, KpiCard, Sparkline, ...)
- 4 neue Stimulus-Controller (aurora_alert, aurora_mode, aurora_banner, typewriter)
- Legacy-Bridge mappt 14 000 bestehende CSS-Zeilen automatisch auf Aurora-Tokens
- Self-hosted Fonts: Inter + JetBrains Mono (SIL OFL)
- Theme-Init 3-State (Light/Dark/System) mit localStorage-Persistenz

### Multi-Framework Compliance

- 23 Compliance-Frameworks im Admin-Katalog
- 8 Cross-Framework Seed-Kataloge (NIS2, DORA, TISAX, BSI, SOC2, C5:2026, GDPR<>ISO27001, GDPR<>ISO27701)
- Transitive Compliance-Berechnung (A->B->C)
- Mapping-Qualitaetsanalyse mit Konfidenzwerten
- Seed-Review-Queue mit Vier-Augen-Prinzip
- CSV-Import mit Dry-Run-Preview
- Mapping-Hub als zentraler Einstieg
- Data-Reuse-Hub mit FTE-Einsparungsberechnung
- Reuse-Heatmap zur Erkennung von Monokultur-Risiken
- Framework-Versions-Migration (z.B. C5:2020 -> C5:2026)
- Gap-Analyse (automatisiert, 5 Lueckentypen)
- Reifegrad-Portfolio (CMMI Level 0-5 pro Framework)
- Compliance-Vererbung mit Review-Queue und 4-Augen-Workflow
- Auto-Mapping-Vorschlaege (Jaccard-Token-Overlap, Klartext-Confidence)
- Audit-Paket-Export als ZIP mit Evidence-Dateien und SHA-256 im Audit-Log
- Bulk-Applicability-Editor mit Begruendungspflicht fuer N/A
- Multi-Framework-Audit (N Frameworks gleichzeitig abdecken)
- InternalAudit-Clone mit Title-Override
- Inverse-Coverage-Widget ("wo wird dieses Dokument referenziert?")
- Reuse-Trend-Chart mit dualer Y-Achse (FTE-Tage + Inheritance-Rate)
- 3-State Applicability-Badge (universal/conditional/voluntary)
- FrameworkApplicabilityService klassifiziert pro Tenant-Kontext

### Konzernstruktur (Holding / Tochtergesellschaften)

- ROLE_GROUP_CISO und ROLE_KONZERN_AUDITOR
- 5 Konfigurationsvererbungs-Resolver (Risk Approval, Incident SLA, KPI Thresholds, Password Policy, E-Mail Branding)
- Holding-Ceiling-Merge und Floor-Merge Strategien
- Konzern-Reports (7 Tabs: Uebersicht, Risk, Compliance, BCM, Incidents, Training, Audits)
- NIS2-Registrierungsmatrix fuer Konzernstruktur
- Compliance-Vererbung mit Review-Queue
- Sichtbarkeit-Steuerung (visibleToHolding)
- Cross-Tenant-Lieferantenverzeichnis mit LEI-Deduplizierung
- Incident-Cross-Posting mit Opt-out (vertrauliche Faelle)
- Holding-Policy-Vererbung (inheritable + overrideAllowed)
- Konzern-Audit-Programm mit Derivation fuer Toechter
- Tenant-NIS2-Felder (Klassifikation, Sektor, NACE, Registrierung)
- Tenant-Hierarchie-Sicherung gegen Zyklen und Self-Reference
- Baseline-Vererbung read-only mit Ahnenketten-Scan
- applyRecursive Propagation fuer Industry-Baselines
- HoldingTreeAccessTrait in 5 Votern (strikt downward-only)

### Glossar und Onboarding

- ISMS-Glossar von 20 auf 171 Begriffe erweitert (8 Kategorien)
- ISO 9001 Analogien fuer Umsteiger
- Suchfunktion und Kategorie-Filter
- Gefuehrte Touren pro Rolle (Junior, ISB, CISO, Auditor, Compliance Manager)
- Per-Step Icons und Resume-after-Navigation
- Hilfe-Menue im Mega-Menu (ISO 9001 Bruecke, Glossar, Tastenkuerzel)
- First-Steps Onboarding-Checkliste auf dem Dashboard
- Tour-Content-Override pro Tenant (4-Augen via SUPER_ADMIN)
- Admin-Report Tour-Completion mit User-Matrix und CSV-Export
- Rollenbasierter Tour-Launcher im User-Dropdown

### Backup und Disaster Recovery

- ZIP-Backup mit Schema-Version und Round-Trip-Test
- AES-256-GCM Verschluesselung mit Key-Derivation
- Tenant-scoped Backup und Restore (Multi-Tenant-Isolation)
- Best-Effort Restore mit Row-Level Failure Tracking
- Backup Repair Command (Salvage-Semantik)
- Backup Prune, Scheduled Create und Notifier Commands
- ManyToMany-Collection-Restore
- Disaster-Recovery-Runbook Dokumentation

### Setup Wizard

- 12-Schritte Wizard (Welcome -> Requirements -> DB -> Restore -> Admin -> Email -> Organisation -> Module -> Compliance -> Base Data -> Sample Data -> Complete)
- Framework-Auswahl mit Pflicht/Empfohlen/Optional-Klassifikation
- Branchen-Baselines (9 Starter-Pakete)
- Alva Busy-Indicator waehrend Datenimport
- Beispieldaten-Modul (Import + Entfernen)
- 8 Bug-Fixes fuer Step 8 Framework-Auswahl

### Incident-Modul

- Status-Filter-Bug behoben (Open-KPI zeigte immer 0)
- 5 Status-Karten statt 4 (alle Entity-Statuses abgedeckt)
- Hardcoded English Strings -> Uebersetzungsschluessel (~20 Strings)
- Emojis durch Bootstrap Icons ersetzt
- Escalation-Preview Stimulus Controller mit i18n
- NIS2 Compliance-Statuses in EN ergaenzt
- Dark-Mode-Support fuer Status-/Severity-Cards

### Internationalisierung

- 0 fehlende Uebersetzungen in DE und EN
- 87 Translation-Domains x 2 Sprachen = 174 YAML-Dateien
- Explizite Domain-Parameter in 7 Templates (~70 |trans Calls)
- Dynamische Translation-Keys gegen YAML verifiziert
- Consent-Enum-Aliases fuer Entity-Werte
- 36 Dashboard-KPI-Labels ergaenzt
- SoA-Message- und Compliance-Industry-Uebersetzungen

### Tenant-Konfiguration

- Risikomatrix-Labels im Translation-System
- Risk-Appetite Review-Buffer-Multiplier konfigurierbar
- Dokument-Klassifizierungs-Default per SystemSetting
- Lieferanten-Kritikalitaetslevel pro Tenant
- Incident-SLAs pro Tenant und Severity
- Genehmigungsschwellwerte pro Tenant
- Audit-Log-Retention editierbar im Admin-Panel
- E-Mail-Branding pro Tenant mit Holding-Fallback

### Security

- OWASP 2025 Final Audit-Script (Score 7.55/10)
- Dual-Report (2021 Legacy + 2025 Primary)
- Cookie samesite auf 'lax' korrigiert
- 11 Security Voters (von 5)
- MFA vollstaendig implementiert (TOTP)
- PasswordPolicyResolver mit Holding-Floor-Merge
- Schema-Reconcile Command fuer fehlgeschlagene Migrationen
- HMAC-SHA256-Chain fuer Audit-Log (NIS2 Art. 21.2 Tamper-Evidence)
- TOTP-Secret Base32-Encoding (RFC 6238, behebt MySQL-Insert-Fehler)

### Datenintegritaet

- Dynamische Orphan-Erkennung fuer alle Tenant-Entities
- Generische Reassign-Route fuer Orphan-Reparatur
- TenantFilter und confirm_hash Fixes
- DataIntegrityService: 15 Entity-Typen, Status-Validierung
- Audit-Freeze mit SHA-256-versiegeltem JSON-Payload (unveraenderlich)
- Schema-Update UI mit 2-Phasen-Flow und Backup-Pflicht-Checkbox

### KPI-System

- ISMS Health Score (Composite: Compliance 40% / Risk 25% / Incidents 20% / Assets 15%)
- Per-Framework Compliance-Prozent
- Risk-Appetite-Compliance, Residual Risk Exposure
- MTTR nach Severity (kritisch/hoch), korrigierter Divisor
- Control-Reuse-Ratio, Days Since Last Management Review
- Gewichtete Control-Compliance (implemented=1.0, partial=0.5)
- KpiThresholdConfig Entity + Admin-UI fuer tenant-spezifische Schwellen
- KpiSnapshot mit taegl. Retention + monatl. Aggregation
- Trend-Pfeile auf allen KPIs
- FTE-saved-KPI als Exec-Summary-Card auf Portfolio-Report

### Compliance-Kataloge

- 3 neue Frameworks: NIS2UmsuCG (15 Req), BDSG (12 Req), EU AI Act (10 Req)
- GDPR +15 Artikel (vollstaendig)
- BSI IT-Grundschutz Kompendium 2023: 1 868 Anforderungen, 121 Bausteine
- BSI Absicherungsstufen-Filter (basis/standard/kern) mit Anforderungstyp
- NIS2 Compliance Dashboard mit 11 Art.-21.2-Letters + Art.-23-Timer
- DORA Register-of-Information-Importer + Sub-Outsourcing-Editor
- TISAX Info-Classification-Schicht + Prototype-Protection-Flow (VDA Kap. 8)
- ISO 27001 Clauses 4-10 als ComplianceRequirements (28 Stueck)
- Industry-Baselines (4 Starter-Pakete: Production, Finance, KRITIS-Health, Generic)
- Seeder-Idempotenz fuer 7 Load-Commands mit --update Flag

### Risk- und Vulnerability-Management

- Incident <> Vulnerability ManyToMany mit idempotenter FK-Migration
- Risk.threatIntelligence und Risk.linkedVulnerability im FormType
- Schutzbedarfsvererbung (BSI 3.6 Maximumprinzip) via Asset.dependsOn
- AssetDependencyService (BFS-Traversierung, zyklensicher)
- RiskAggregationService (Portfolio-View, korrelierte Risiken, Heatmap)
- Incident <> Risk <> Vulnerability 1-Klick-Verknuepfung

### BCM

- BCMService (BIA-Analyse, Plan-Readiness, Exercise-Schedule)
- BC-Plan-Templates-Seeder mit 5 Standard-Szenarien
- BCM-Templates komplett uebersetzt
- Incident <> BusinessProcess Verknuepfung

### Form-UX

- Pattern A: Dual-State Owner fuer 7 Entities (Asset, BC-Plan, BusinessProcess, Control, Incident, Risk, Training)
- Pattern B: TomSelect fuer 6 Native-Multi-Selects
- Pattern C: Help-Texte fuer BCPlanType + 13 DORA/GDPR-Felder
- Pattern D: Progressive Disclosure mit Negation und Select-Trigger
- 90+ Felder mit ISO-Referenz-Help-Texten versehen
- CIA-Skala bei Asset-Labels inline sichtbar
- ISO-Reference-Label-Komponente (Control-ID + Klartext + Tooltip)

### Admin-Panel

- Mega-Menue umstrukturiert: Platform-Admin + Compliance-Admin
- Data-Repair Safety-Banner mit Audit-Log-Hinweis
- Dashboard-KPIs neu kuratiert (Framework-Ladezustand, ungepruefte Seed-Mappings)
- Dynamic Quick Actions (kontextabhaengig)
- Admin-scoped Command Palette (21 neue Commands per Cmd+P)
- Breadcrumb-Konsistenz in 12 Admin-Templates
- Beispieldaten-Modul (Import + Entfernen)
- Loader-Fixer idempotent Pattern
- Compliance-Policy-Einstellungen (13 Laufzeit-Parameter)
- Framework Loader-Fixer UI

### Navigation und UX

- Filter-State in URL (7 Index-Seiten, Links teilbar und bookmarkbar)
- Skeleton-Wrapper fuer Management-KPI-Widget (350 ms Perceived-Performance)
- Cmd+K-Chip im Global-Search-Button ab md-Viewport
- Bulk-Action-Bar konsolidiert
- Breadcrumb Home -> nav.home Translation

### Management-Reports

- Board One-Pager PDF (RAG-Status + Top-Risiken + Framework-Compliance)
- Management-Review-PDF mit Signatur-Block (eIDAS-Hinweis)
- Prototype-Protection PDF-Export (VDA Kap. 8)
- Delta-Assessment-Excel (3-Sheet-Layout)
- Portfolio-Report-Trend mit Drill-Down und echtem Delta

### CSS und Dark Mode

- Alle hardcoded `background: white` durch CSS-Variablen ersetzt (8 Dateien)
- Bootstrap-Subtle-Varianten fuer Alert-Farben
- bg-body / bg-body-secondary statt bg-white
- Fairy-Emoji durch Alva SVG ersetzt

### Dokumentation

- README komplett neu geschrieben (290 Zeilen, alle 23 Frameworks)
- 15 Dokumentationsdateien inhaltlich korrigiert
- ROADMAP-Metriken aktualisiert
- CLAUDE.md Domain-Liste auf 87 erweitert
- Disaster-Recovery-Runbook
- docs/ Cleanup: 115 -> 73 aktive Docs (38 geloescht, 21 archiviert)

### Tests

- 3 919 Tests, 10 827 Assertions, 0 Fehler, 0 Failures
- PHP 8.5 Deprecation-Fixes (failOnDeprecation=true, exit 0)
- Voter-Tests: 6 neue (Document x 3, Incident x 3)
- 21 Unit-Tests fuer Guided Tour (199 Assertions)

### Datenbank

- 47 Doctrine-Migrationen zu einer Squash-Migration konsolidiert
- Idempotente Helpers: safeAddColumn, safeAddFK, safeDropFK, safeModifyColumn
- Legacy-Migrationen archiviert in migrations/legacy/

---

## Fruehere Versionen

### [2.7.0] - 2026-04-17
- Phase 8J: 67+ Massnahmen ueber 7 Sprints (Standards Compliance und UX)
- 3 neue Frameworks (NIS2UmsuCG, BDSG, EU AI Act), GDPR/NIST/GxP erweitert
- DataSubjectRequest Entity (GDPR Art. 15-22), ElementaryThreat (BSI 200-3)
- First Steps Checklist, ISO 9001 Bridge Page, ISMS Glossar (20 Begriffe)
- KPI-Berechnungen korrigiert (MTTR, Control-Compliance, Risk-Treatment-Rate)

### [2.6.0] - 2025-12-20
- PWA Advanced Features: Push Notifications, Background Sync, Share Target API
- Service Worker mit IndexedDB-basierter Offline-Queue
- Web App Manifest mit File/Protocol Handlers

### [2.5.2] - 2025-12-19
- Role Help Component mit visueller Hierarchie-Kette
- Progressive Web App Basis (Manifest, Service Worker, Offline Page)
- Role Tooltips auf User-Form Checkboxen

### [2.5.1] - 2025-12-15
- DateTime/DateTimeImmutable Type-Mismatch in 5 Forms behoben
- PHPStan-Fixes in 6 Console Commands
- ComplianceController Variable-Initialisierung

### [2.5.0] - 2025-12-15
- Phase 7: Management Dashboard und Compliance Wizard
- Compliance-Wizards fuer ISO 27001, TISAX AL2/AL3, BSI IT-Grundschutz
- 8 Management-Reports mit PDF/Excel-Export
- DORA Compliance Dashboard

### [2.2.4] - 2025-12-10
- Internationalisierung: 56 Domain-Korrekturen, 5 Templates uebersetzt
- 21 hardcoded aria-labels durch trans() ersetzt
- Translation-Issues von 215 auf 70 reduziert

### [2.2.3] - 2025-12-09
- PDF/Email/Setup-Templates vollstaendig internationalisiert
- window.translations in base.html.twig fuer JavaScript i18n

### [2.2.2] - 2025-12-08
- CI/CD Pipeline Fixes (PHPUnit, Test-DB, Environment)
- Dependency Updates

### [2.2.1] - 2025-11-29
- ReviewReminderService + SendReviewRemindersCommand
- Risk Slider Component (interaktive 5x5 Matrix)

### [2.2.0] - 2025-11-29
- Automatische Review-Reminders (GDPR Art. 33, ISO 27001 Clause 6.1.3.d, ISO 22301)
- Interaktiver Risk Slider mit Presets und Farbkodierung
- Symfony 7.4 Kompatibilitaets-Fixes

### [2.1.1] - 2025-11-28
- Code Quality (Rector): PHP 8.4 und Symfony 7.4 Best Practices
- Internationalisierung ~95% abgeschlossen (49 Domains x 2 Sprachen)
- Doctrine Entity Mapping Fixes nach Rector-Renames

### [2.1.0] - 2025-11-27
- GDPR Breach Wizard mit 72h-Countdown
- Incident Escalation Workflows mit Auto-Escalation
- Approval Workflows (Risk Treatment Plan, Document)
- Auto-Form Component mit Bootstrap 5.3 Floating Labels

### [2.0.0] - 2025-11-26
- Komplettes UI/UX-Redesign: Mega-Menu, Breadcrumbs, Dark Mode
- 97 Translation-Domains, 3 290+ Keys (DE/EN)
- Bootstrap 5.3 Floating Labels, WCAG 2.1 AA

### [1.10.1] - 2025-11-21
- Hotfix: Admin-Login nach Database-Reset (Tenant-Deadlock behoben)
- CSRF-Token Auto-Clear nach composer update

### [1.10.0] - 2025-11-20
- 6 Risk-Management-Prioritaeten (Owner, Review, Acceptance, GDPR, Guidance, Monitoring)
- ProcessingActivity (VVT/ROPA Art. 30), DPIA, DataBreach (72h)
- Badge-Standardisierung (32 Tabellen), WCAG 2.1 AA Forms

### [1.7.1] - 2025-11-17
- Hotfix: FK-Constraints, Entity-ID-Preservation, DateTime-Fixes beim Restore

### [1.7.0] - 2025-11-17
- Backup/Restore-System Overhaul mit Setup-Wizard-Integration
- ManyToOne Relation Support, Unique-Constraint-Detection, 30+ Entity-Ordering

### [1.6.4] - 2025-11-16
- Compliance Framework CRUD, Workflow Builder (Drag-and-Drop)
- 16 neue Service-Tests (~5 000 Testzeilen)

### [1.6.2] - 2025-11-15
- ARM64/ARM Support (Multi-Architecture Docker Builds)

### [1.6.0] - 2025-11-15
- Multi-Tenancy System mit Corporate Structure
- Unified Admin Panel, MFA/TOTP, 100+ Permissions
- 7 deutsche Compliance-Frameworks (BSI, BaFin, DSGVO, KRITIS, NIS2, TISAX, DORA)

### [1.5.0] - 2025-11-07
- PDF/Excel Reports, REST API (30 Endpoints), Notification Scheduler
- Global Search (Cmd+K), Quick View, Dark Mode, Drag-and-Drop

### [1.4.0] - 2025-11-06
- CRUD und Workflows, Risk Assessment Matrix, 5 FormTypes, 30+ Templates

### [1.3.0] - 2025-11-05
- Authentication (Local, Azure OAuth/SAML), RBAC mit 5 Rollen, Audit Logging

### [1.2.0] - 2025-11-05
- BCM mit BIA, Multi-Framework Compliance (TISAX, DORA), Cross-Framework Mappings

### [1.1.0] - 2025-11-04
- Core ISMS: 9 Entities (Asset, Risk, Control, Incident, Audit, Training, ...)
- 93 ISO 27001:2022 Annex A Controls

### [1.0.0] - 2025-11-01
- Projekt-Initialisierung, Symfony 7.3 Setup
