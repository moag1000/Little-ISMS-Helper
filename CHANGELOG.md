# Changelog

Alle wesentlichen Aenderungen an diesem Projekt werden in dieser Datei dokumentiert.
Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/).

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
