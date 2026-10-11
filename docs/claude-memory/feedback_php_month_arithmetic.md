---
name: feedback_php_month_arithmetic
description: "PHP \"-N months\" laeuft am Monatsende ueber (31.08. minus 6 Monate = 03.03.) — Zeitfenster immer auf den Monatsersten ankern."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 8e0e42f3-414a-4eb2-8290-c650a1a95dad
  modified: 2026-08-31T16:23:51.916Z
---

`new DateTimeImmutable("-6 months")` am **31.08.2026** ergibt rechnerisch den
31.02., den PHP auf den **03.03.** normalisiert. Ein Datensatz vom 01.03. faellt
damit aus dem Fenster.

Realer Defekt (gefunden 2026-08-31, PR #1032):
`KpiSnapshotRepository::findMonthlySnapshots()` berechnete so sein Fenster —
`/analytics/api/trends` verlor dadurch still seinen aeltesten Monat, sobald der
Aufruf auf den 29.–31. eines Monats nach einem kuerzeren fiel. Ergebnis: 5 statt
6 Datenpunkte, ohne Fehlermeldung.

**Richtig:** auf den Monatsanfang ankern, nicht auf den Tagesstand:
```php
$now->modify('first day of this month')
    ->modify('-' . max(0, $months - 1) . ' months')
    ->setTime(0, 0);
```
Das ergibt an JEDEM Tag des Jahres exakt N Kalendermonate.

**Why:** Der Defekt lag monatelang in einer Suite mit 11.060 gruenen Tests.
Gefunden hat ihn nicht die Abdeckung, sondern der Kalender — der erste CI-Lauf,
der auf einen 31. fiel, kippte `main`. Datumsabhaengige Tests sind nur an
wenigen Tagen im Jahr aussagekraeftig.

**How to apply:** Jede Zeitfenster-Berechnung mit `modify('-N months')` ist
verdaechtig. Grenze in eine statische Methode mit **injizierbarer Uhr** ziehen
und mit Stichtagen testen (Monatsenden, 29. Februar, months=0/1), statt zu
hoffen, dass die Suite am richtigen Tag laeuft — siehe
`tests/Repository/KpiSnapshotWindowTest.php`. Gleiches gilt fuer
`-N years` (29.02.) und `last day of`. Siehe [[feedback_run_it_dont_assume]].
