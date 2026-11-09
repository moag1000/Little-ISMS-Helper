# Setup Validation

Prüfen, ob eine Installation vollständig ist:

| Was | Wie |
|---|---|
| Voraussetzungen, `.env.local`, Migrationen (vor dem ersten Start) | `scripts/setup/validate-setup.sh` — Beschreibung der Checks in [SETUP_TOOLS.md](SETUP_TOOLS.md#1-validate-setupsh) |
| System-Anforderungen im Browser | Setup-Wizard, Schritt 1 (`/setup`) |
| Ausstehende Migrationen, Schema-Drift | `/quick-fix` ([QUICK_FIX.md](../user-guide/QUICK_FIX.md)) |
| Service-Verdrahtung, Templates | `php bin/console lint:container`, `php bin/console lint:twig templates/` |
| Schema gegen Entities | `php bin/console doctrine:schema:validate` |
| Worker / geplante Aufgaben | `/admin/queue-status`, `php bin/console debug:scheduler` ([HOSTING_WORKER.md](../user-guide/HOSTING_WORKER.md)) |
| Erreichbarkeit | `curl https://<host>/health` |

Voraussetzungen der Anwendung: PHP 8.4+ (8.5 getestet), MySQL 8 / MariaDB 10.6+, Composer 2.
Installationsweg: [QUICKSTART.md](../QUICKSTART.md).
