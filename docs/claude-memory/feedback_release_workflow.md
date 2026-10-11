---
name: Release-Workflow Helper-Projekt
description: Releases im Little-ISMS-Helper laufen IMMER ueber release-please + dev/rc-Tags. Nie ad-hoc taggen. Form (stable/dev/rc) immer aktiv erfragen.
type: feedback
originSessionId: f9f6b4bc-9a59-429d-a457-d9b45d40d0f5
---
Im Little-ISMS-Helper-Repo NIE direkt `git tag vX.Y.Z` + `git push`.
Stattdessen ausschliesslich:

- **Stable**: release-please-PR (`chore(main): release X.Y.Z`) wird **jeden
  Montag 09:00 UTC automatisch gemerged** via `release-please-auto-merge.yml`
  → Tag + GitHub Release + Docker `:vX.Y.Z` `:X.Y` `:latest`. Skip-Mechanismus:
  Label `release-blocked` oder `do-not-merge` an PR. Ad-hoc-Trigger:
  workflow_dispatch in Actions-UI.
- **Dev**: GitHub Action **"Dev Release (manual)"** mit bump-Input
  (patch/minor/major) → Tag `vX.Y.Z-dev.N` → Docker `:vX.Y.Z-dev.N` + `:dev`
  (kein `:latest`, kein `:X.Y`).
- **RC**: manuell `git tag vX.Y.Z-rc.N` → Docker `:vX.Y.Z-rc.N` + `:rc`.

**Why:** Setup vom 2026-04-30. User wollte „professionelleren Auftritt" statt
„maschinengewehrartiger" Releases. release-please bündelt Conventional-Commits,
Cadence-Disziplin (Mo/Do o.ä.) statt Tag-pro-Fix. Pi-Container auf `:latest`
duerfen nicht versehentlich auf Dev-Builds landen → strikte Tag-Trennung in
`.github/workflows/ci.yml`.

**How to apply:** Wenn User „Release machen" / „veröffentlichen" / „taggen"
sagt OHNE Form zu nennen, IMMER zurueckfragen: *„Stable (auf naechsten Mo
warten oder workflow_dispatch erzwingen) oder Dev oder RC?"*. Nicht raten.
Nicht direkt taggen. Bei Stable: PR ist eh offen + auto-merged Mo. User
muss nichts tun ausser ggf. PR labeln (`release-blocked`) wenn Skip noetig.
Bei Dev: erinnern an Bump-Input (patch/minor/major).

CHANGELOG.md wird von release-please verwaltet — manuelle Edits nur fuer
Backfill alter Versionen, sonst Conventional-Commit-Messages reichen.

Doku in `CONTRIBUTING.md` § *Release Cadence* + § *Dev / Pre-Release Builds*.
