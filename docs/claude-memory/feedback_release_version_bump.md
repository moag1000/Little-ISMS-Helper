---
name: Release-Checklist Version-Bump
description: composer.json-version-Feld muss zur Tag-Version passen. Seit 2026-04-30 erledigt release-please das automatisch via release-please-config.json extra-files. Manuelle Bumps nur fuer Hotfix-Edge-Cases.
type: feedback
originSessionId: cc59742f-0b08-48e9-a9a8-ab2c4560d848
---
**Update 2026-04-30:** release-please verwaltet `composer.json.version` jetzt
automatisch (siehe `release-please-config.json` → `extra-files` jsonpath).
Bei Stable-Releases (PR-Merge) wird das Feld korrekt gesetzt — kein manueller
Eingriff noetig. Hauptmemory zum Release-Workflow: `feedback_release_workflow.md`.

Bei Dev-Releases (`workflow_dispatch` aus `dev-release.yml`) wird composer.json
NICHT gebumped — der Pre-Release-Tag ist `vX.Y.Z-dev.N` aber composer.json
behaelt die letzte Stable-Version. Bewusst so: Dev-Builds sollen nicht die
sichtbare App-Version aendern.

---

Originaltext (manueller Workflow vor release-please):

Vor jedem `git tag vX.Y.Z` MUSS das `version`-Feld in `composer.json` auf den neuen Tag gesetzt werden.

**Why:** Das `version`-Feld in `composer.json` ist die Single-Source-of-Truth für die `app_version`-Twig-Global, die `src/Twig/AppVersionExtension.php` aus `composer.json` liest. Wenn das Feld nicht mitgewartet wird, zeigen Login-Footer, Email-Header, Setup-Wizard-Footer und Brand-Component die alte Version — obwohl der GitHub-Release-Tag schon weiter ist. Bei v3.3.0 + v3.3.1 zuerst übersehen, User musste explizit nachfragen.

**How to apply:**
- Pre-Release-Checklist erweitern: composer.json `version` als ersten Schritt updaten, danach erst CHANGELOG, Commit, Tag.
- Sanity-Check vor `git tag`: `grep -E '"version":\s*"' composer.json` muss den frisch geplanten Tag zeigen.
- Importmap-`version`-Felder (`@hotwired/stimulus`, etc.) sind Lib-Versionen, NICHT die App-Version — nicht anfassen.
- Keine weiteren Stellen aktiv pflegen — `_brand.html.twig`, `_layout.html.twig`, `emails/base.html.twig` lesen alle `app_version` indirekt.
