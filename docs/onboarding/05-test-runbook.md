# 05 — Test Runbook

## Overview

The test suite uses PHPUnit 13.1 with `#[Test]` attributes. Two base classes:

| Base Class | When to use |
|---|---|
| `WebTestCase` | HTTP-layer tests (controller, form submission, redirects) |
| `KernelTestCase` | Service tests (inject and call services directly) |

The `APP_ENV=test` environment is configured in `phpunit.xml.dist`. Tests use
an isolated database (set `DATABASE_URL` in `.env.test.local` or rely on
the in-memory SQLite config in `config/packages/test/`).

---

## Running the Full Suite

```bash
php bin/phpunit
```

Expected run time: 5–10 minutes on a standard developer laptop. On memory-
constrained environments the suite may hang or OOM — see the targeted subsets
section below.

Coverage is measured but warn-only. Current warning threshold: 40%. Target: 60%.

```bash
# With coverage report (HTML output in var/coverage/html/)
php bin/phpunit --coverage-html var/coverage/html
```

---

## Targeted Subsets (Use These During Feature Work)

**Agent work rule:** run only the tests that cover the changed code. Reserve
the full suite for end-of-feature validation.

### By directory (fastest)

```bash
# Service tests only
php bin/phpunit tests/Service/

# Controller tests only
php bin/phpunit tests/Controller/

# Entity tests (constraints, lifecycle)
php bin/phpunit tests/Entity/

# Lifecycle state machine tests
php bin/phpunit tests/Lifecycle/

# Repository tests (DQL + tenant isolation)
php bin/phpunit tests/Repository/

# Workflow engine tests
php bin/phpunit tests/Workflow/

# Security voter tests
php bin/phpunit tests/Security/

# Translation completeness
php bin/phpunit tests/Translation/

# Quality gate PHP tests
php bin/phpunit tests/Quality/
```

### By file (targeted debugging)

```bash
php bin/phpunit tests/Service/RiskServiceTest.php
php bin/phpunit tests/Controller/ComplianceControllerTest.php
php bin/phpunit tests/Lifecycle/DocumentLifecycleTest.php
```

### By PHPUnit filter (single test method)

```bash
php bin/phpunit --filter testRiskCreationRaisesAuditEntry
```

---

## Test Sub-Directories at a Glance

| Directory | What it covers |
|---|---|
| `tests/AlvaHint/` | Alva hint rule evaluation |
| `tests/Command/` | Console command output and side effects |
| `tests/Controller/` | HTTP responses, redirects, CSRF, RBAC |
| `tests/E2e/` | Playwright-driven end-to-end scenarios |
| `tests/Entity/` | Doctrine mapping, constraint validation |
| `tests/Enum/` | PHP enum cases and conversion methods |
| `tests/EventListener/` | Doctrine event listener behaviour |
| `tests/EventSubscriber/` | Symfony event subscriber behaviour |
| `tests/Fixtures/` | Fixture loaders used by multiple test classes |
| `tests/Form/` | FormType field rendering, validation |
| `tests/Functional/` | Multi-step functional flows (e.g. wizard) |
| `tests/Integration/` | Cross-service integration scenarios |
| `tests/Lifecycle/` | State machine transitions, guards, audit |
| `tests/Listener/` | Event listener tests |
| `tests/MessageHandler/` | Messenger handler tests |
| `tests/Migration/` | Migration idempotency smoke tests |
| `tests/PHPStan/` | Custom PHPStan rule self-tests |
| `tests/Quality/` | PHP-side quality gate assertions |
| `tests/Repository/` | DQL correctness, tenant scoping |
| `tests/Risk/` | Risk scoring value-object tests |
| `tests/Security/` | Voter and authenticator tests |
| `tests/Service/` | Service unit and integration tests |
| `tests/System/` | Full system smoke tests |
| `tests/Template/` | Twig extension tests |
| `tests/Translation/` | Missing-key detection, domain correctness |
| `tests/Twig/` | Twig extension function tests |
| `tests/Validator/` | Custom validation constraint tests |
| `tests/Workflow/` | Regulatory workflow engine tests |

---

## Fixtures

Test fixtures are loaded through `tests/Fixtures/`. Fixture classes create
minimal data sets that satisfy FK constraints. To keep tests isolated:

- Each test class creates its own fixtures via `setUp()` teardown via
  `tearDown()` or transaction rollback.
- Use `DatabaseTestService` (injectable) for controlled DB setup in
  `KernelTestCase` tests.

For controller tests requiring an authenticated user:

```php
// CSRF token requires an active session — always do a GET before posting
$client->request('GET', '/en/risk/new');
$token = $client->getContainer()->get('security.csrf.token_manager')
    ->getToken('risk_form')->getValue();

$client->request('POST', '/en/risk/new', [
    'risk_form' => [
        '_token' => $token,
        'title'  => 'Test Risk',
        // ...
    ]
]);
```

Skipping the GET step causes CSRF validation to fail with a 403.

---

## Playwright E2E Screenshots

The project uses Playwright with a YAML-driven persona configuration to
generate screenshots for all persona/theme combinations.

```bash
# Install Playwright (once)
npm install

# Run screenshot capture (requires a running app on localhost:8000)
npm run screenshots
```

Screenshots are written to `var/screenshots/<persona>/<theme>/`.

The persona YAML definitions live in the screenshot configuration. User
accounts are created by `php bin/console app:create-screenshot-user`.

These screenshots serve two purposes:
1. Visual regression reference for design-system changes
2. User documentation illustrations

---

## Writing New Tests

### Service test template

```php
namespace App\Tests\Service;

use App\Service\MyService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use PHPUnit\Framework\Attributes\Test;

class MyServiceTest extends KernelTestCase
{
    private MyService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->service = static::getContainer()->get(MyService::class);
    }

    #[Test]
    public function itDoesSomethingExpected(): void
    {
        // arrange
        // act
        // assert
    }
}
```

### Controller test pattern

```php
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use PHPUnit\Framework\Attributes\Test;

class MyControllerTest extends WebTestCase
{
    #[Test]
    public function pageIsAccessibleToManager(): void
    {
        $client = static::createClient();
        // ... login, request, assert
    }
}
```

---

## CI Integration

The full suite runs on every push via `.github/workflows/ci.yml`. Subset runs
are not triggered separately — all tests run in parallel job shards. The CI
database is MySQL 8.0 matching the production constraint. Local tests may
use MariaDB or SQLite; flag differences if behaviour diverges.
