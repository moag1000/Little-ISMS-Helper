---
name: Keine fremde Arbeit anfassen
description: Bei dirty working tree mit WIP-Files anderer Agents nicht parken/verschieben/stashen — eigene Tests in Isolation prüfen oder ohne fremde Files arbeiten.
type: feedback
originSessionId: 0e01194e-c97f-4c98-916e-8dd416392614
---
Wenn der Working-Tree mit unvollständiger WIP-Arbeit anderer Agents (z.B. broken Entity-Mappings, Stub-Repositories, halb-fertige Controller) verunreinigt ist, dann **nicht** mit `git stash`, `mv` oder Datei-Verschieben in `/tmp` herumdoktorn um die eigenen Tests zum Laufen zu bringen.

**Why:** Direkt vom User gefordert ("fummel nicht in der fremden arbeit rum bitte"). Beim Migrations-Bundle-Bump und beim GSTOOL-Phase-3+4-Test habe ich `User.php`, `IdentityProvider.php`, `SsoProviderController.php` etc. in `/tmp/lih-wip/` verschoben, um Tests zum Laufen zu bekommen — das führt zu fragilen Zwischenständen und kann fremde Arbeit zerstören wenn man sie nicht korrekt zurückbringt.

**How to apply:**
- Wenn fremde WIP-Files den Test-Boot blockieren: stattdessen Tests akzeptieren dass sie lokal nicht laufen, in CI grün werden lassen
- ODER PHP-Lint + statische Verification statt Runtime-Tests nutzen (`php -l`, `lint:container --no-debug` falls möglich)
- ODER explizit beim User nachfragen ob/wann fremde WIP weg darf
- NIEMALS `git stash`/`rm`/`mv` auf Files die nicht aus der eigenen Sitzung stammen
- Eigene Files separat committen + pushen — CI schaltet sie auf clean state und meldet ob es echt passt
