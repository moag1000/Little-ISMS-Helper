# Contributing to Little ISMS Helper

Thank you for your interest in contributing to Little ISMS Helper! This document provides guidelines and instructions for contributing to this project.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [Getting Started](#getting-started)
- [Development Workflow](#development-workflow)
- [Coding Standards](#coding-standards)
- [Commit Guidelines](#commit-guidelines)
- [Pull Request Process](#pull-request-process)
- [Testing Requirements](#testing-requirements)
- [Documentation](#documentation)

---

## Code of Conduct

### Our Pledge

We are committed to providing a welcoming and inspiring community for everyone. Please be respectful and constructive in your interactions.

### Expected Behavior

- Use welcoming and inclusive language
- Be respectful of differing viewpoints
- Accept constructive criticism gracefully
- Focus on what is best for the community
- Show empathy towards other community members

---

## Getting Started

### Prerequisites

- PHP 8.4+ required (8.5 supported and tested in CI)
- Composer 2.x
- PostgreSQL 16+ or MySQL 8.0+ or MariaDB 10.11+
- Git
- Symfony CLI (optional but recommended)

### Initial Setup

1. **Fork the repository**
   ```bash
   # Click "Fork" on GitHub, then clone your fork
   git clone https://github.com/YOUR-USERNAME/Little-ISMS-Helper.git
   cd Little-ISMS-Helper
   ```

2. **Add upstream remote**
   ```bash
   git remote add upstream https://github.com/moag1000/Little-ISMS-Helper.git
   ```

3. **Install dependencies**
   ```bash
   composer install
   php bin/console importmap:install
   ```

4. **Configure environment**
   ```bash
   cp .env .env.local
   # Edit .env.local with your database credentials
   ```

5. **Setup database**
   ```bash
   php bin/console doctrine:database:create
   php bin/console doctrine:migrations:migrate
   php bin/console isms:load-annex-a-controls
   ```

6. **Run development server**
   ```bash
   symfony serve
   # or
   php -S localhost:8000 -t public/
   ```

---

## Development Workflow

### Branch Naming Convention

Use descriptive branch names that indicate the type of work:

- `feature/` - New features (e.g., `feature/document-upload`)
- `bugfix/` - Bug fixes (e.g., `bugfix/risk-calculation`)
- `hotfix/` - Urgent production fixes (e.g., `hotfix/security-patch`)
- `refactor/` - Code refactoring (e.g., `refactor/service-layer`)
- `docs/` - Documentation updates (e.g., `docs/api-guide`)
- `test/` - Test additions/improvements (e.g., `test/audit-coverage`)

**Example:**
```bash
git checkout -b feature/training-certificates
```

### Keeping Your Branch Updated

```bash
# Fetch latest changes from upstream
git fetch upstream

# Rebase your branch on upstream/main
git rebase upstream/main

# Force push to your fork (if already pushed)
git push --force-with-lease origin feature/your-feature
```

---

## Coding Standards

### PHP Standards

We follow **Symfony Best Practices** and **PSR-12** coding standards.

#### Code Style

- **Indentation:** 4 spaces (no tabs)
- **Line length:** Max 120 characters
- **Naming conventions:**
  - Classes: `PascalCase` (e.g., `RiskController`)
  - Methods: `camelCase` (e.g., `calculateRiskScore()`)
  - Constants: `UPPER_SNAKE_CASE` (e.g., `MAX_RISK_LEVEL`)
  - Properties: `camelCase` (e.g., `$riskLevel`)

#### Example

```php
<?php

namespace App\Service;

use App\Entity\Risk;
use Doctrine\ORM\EntityManagerInterface;

class RiskIntelligenceService
{
    private const MAX_RISK_LEVEL = 25;

    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    public function calculateRiskScore(Risk $risk): int
    {
        $likelihood = $risk->getLikelihood();
        $impact = $risk->getImpact();

        return min($likelihood * $impact, self::MAX_RISK_LEVEL);
    }
}
```

### Twig Templates

- **Indentation:** 4 spaces
- **Use semantic HTML5 elements**
- **Include ARIA labels for accessibility**
- **Use Bootstrap 5 classes consistently**

```twig
<div class="card">
    <div class="card-header">
        <h3 class="card-title">{{ 'risk.details'|trans }}</h3>
    </div>
    <div class="card-body">
        {% if risk.isHighRisk %}
            <div class="alert alert-danger" role="alert">
                {{ 'risk.high_risk_warning'|trans }}
            </div>
        {% endif %}
    </div>
</div>
```

### Form Standards (Aurora v4)

The application uses a **single global form-theme** — `templates/form/fa_cyber_input.html.twig` — set in `config/packages/twig.yaml`. All Symfony forms render through this theme automatically.

**MUST do:**
- All `FormType` classes extend `AbstractType` and set `data_class` + `translation_domain` in `configureOptions()`.
- Render forms via `{{ form_start(form) }}` + `{{ form_widget(form) }}` (or `{{ form_row(form.field) }}` for custom layouts) + `{{ form_end(form) }}`. `form_end` auto-renders CSRF.
- For new field types (e.g. `file`, `range`, `date`, `time`, `datetime`), the theme adds `.fa-cyber-input__field--<type>` modifiers — no custom markup needed.

**MUST NOT do:**
- Override `getBlockPrefix()` in a `FormType` — this breaks global theming.
- Use `{% form_theme form '...' %}` to switch to `bootstrap_5_layout.html.twig`. If you need to override one specific field, write a custom theme that `{% use "form/fa_cyber_input.html.twig" %}` and only overrides the specific block (see `templates/supplier/_supplier_form_theme.html.twig` for an example).
- Hand-roll `<input class="form-control">` instead of `form_widget()` inside Symfony forms. Filter forms (GET, stateless) may use raw inputs — they're styled via the Aurora CSS bridge in `fairy-aurora-components.css`.

**Validation + accessibility:**
- The theme renders errors as `.fa-cyber-input__errors[role="alert"]` automatically.
- All inputs receive an associated `<label for="...">` via `form_label`.
- Required fields show `<span class="fa-cyber-input__req" aria-label="form.required">*</span>`.

**CSS bridge:** Raw Bootstrap classes (`.form-control`, `.form-select`) inside `<form method="get">` filter bars are styled with Aurora tokens via `fairy-aurora-components.css` (~line 3751). This is intentional — filter forms don't go through Symfony's form system.

### Module-Gating Pattern

Forms and UI sections that depend on optional compliance modules must use the
module-gating infrastructure introduced in T31 (May 2026).

> Full developer reference: [docs/MODULE_GATING_GUIDE.md](docs/MODULE_GATING_GUIDE.md)

#### FormType: use `ModuleAwareFormTrait`

```php
use App\Form\Trait\ModuleAwareFormTrait;
use App\Service\ModuleConfigurationService;

class MyFormType extends AbstractType
{
    use ModuleAwareFormTrait;

    public function __construct(
        private readonly ModuleConfigurationService $moduleConfiguration,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Core fields — always shown
        $builder->add('title', TextType::class, ['label' => 'my.field.title']);

        // Module-gated section with norm reference
        // GDPR Art. 7(3) — consent withdrawal mandatory when privacy module active
        if ($this->isModuleActive('privacy')) {
            $builder->add('withdrawnAt', DateTimeType::class, [
                'label' => 'my.field.withdrawn_at',
                'required' => false,
            ]);
        }
    }
}
```

Rules:
- Use `private readonly ModuleConfigurationService $moduleConfiguration` (exact property name — the trait uses it).
- Gate entire logical field groups, not individual fields.
- Add a doc comment with the norm reference next to every `if` block.
- Do NOT duplicate the `isModuleActive()` method — use the trait.

#### Controller: use `ModuleGatedControllerTrait`

For whole-module gating (entire controller blocked when module is inactive):

```php
use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Service\ModuleConfigurationService;
use Symfony\Contracts\Translation\TranslatorInterface;

class MyController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly ModuleConfigurationService $moduleService,
        private readonly TranslatorInterface $translator,
    ) {}

    public function index(): Response
    {
        if ($redirect = $this->checkModuleActive('privacy')) return $redirect;
        // ... controller logic
    }
}
```

#### Twig: `is_module_active()` global function

```twig
{% if is_module_active('privacy') %}
    <div class="card">
        {# GDPR-specific content #}
    </div>
{% endif %}
```

#### Stimulus: `data-depends-on` for conditional sub-fields

Within a module-gated section, use `data-depends-on` for field-level conditional
visibility (no round-trip needed):

```php
$builder->add('specialCategoryData', CheckboxType::class, [
    'attr' => [
        'data-depends-on' => 'my_form_involvesPersonalData',
        'data-depends-on-value' => '1',
    ],
]);
```

#### Translation Key Convention

Module-gated fields use the same translation domain as the surrounding FormType.
No separate domain for gated fields. The "module inactive" flash message uses
the `messages` domain key `common.module_not_active`.

#### 21 Module Keys Reference

| Key | Trigger |
|---|---|
| `privacy` | GDPR / ISO 27701 |
| `nis2_dora` | NIS2 + DORA |
| `ai_governance` | EU AI Act + ISO 42001 |
| `cloud_security` | ISO 27017/18, BSI C5 |
| `vulnerability_intel` | Vulnerability + Threat Intelligence |
| `marisk` | MaRisk (DACH banks/insurers) |
| `tisax` | TISAX / VDA ISA |
| `quantitative_risk` | FAIR methodology |
| `bcm` | ISO 22301 BCM |
| `compliance` | Multi-framework compliance |
| `bsi_grundschutz` | BSI IT-Grundschutz |
| `core`, `assets`, `risks`, `controls`, `incidents`, `audits`, `training`, `reviews`, `authentication`, `audit_logging` | Always active |

---

### JavaScript/Stimulus

- **Use modern ES6+ syntax**
- **Follow Stimulus conventions**
- **Use meaningful variable names**

```javascript
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['modal', 'content'];

    connect() {
        this.boundCloseOnEscape = this.closeOnEscape.bind(this);
        document.addEventListener('keydown', this.boundCloseOnEscape);
    }

    disconnect() {
        document.removeEventListener('keydown', this.boundCloseOnEscape);
    }

    closeOnEscape(event) {
        if (event.key === 'Escape' && this.modalTarget.classList.contains('show')) {
            this.close();
        }
    }
}
```

---

## Commit Guidelines

### Commit Message Format

We follow the **Conventional Commits** specification:

```
<type>(<scope>): <subject>

<body>

<footer>
```

#### Types

- `feat:` - New feature
- `fix:` - Bug fix
- `docs:` - Documentation changes
- `style:` - Code style changes (formatting, no logic change)
- `refactor:` - Code refactoring
- `perf:` - Performance improvements
- `test:` - Adding or updating tests
- `build:` - Build system or dependency changes
- `ci:` - CI/CD configuration changes
- `chore:` - Other changes (e.g., updating dependencies)

#### Examples

**Good:**
```
feat(risk): add automatic risk recalculation on asset change

- Add RiskRecalculationService
- Trigger recalculation on asset CIA value updates
- Add unit tests for recalculation logic

Closes #123
```

**Good:**
```
fix(audit): correct date filtering in audit list

The audit list was not filtering correctly by planned date.
Fixed the DQL query to use proper date comparison.

Fixes #456
```

**Bad:**
```
updated stuff
```

**Bad:**
```
fix bug
```

### Commit Best Practices

- **One logical change per commit**
- **Write clear, descriptive commit messages**
- **Reference issue numbers** (e.g., `Fixes #123`, `Closes #456`)
- **Keep commits atomic** - each commit should be self-contained
- **Avoid commits with "WIP" or "temp"** - squash them before submitting PR

### Release Cadence

This project uses **release-please** for automated releases. The flow:

1. Conventional commits land on `main` continuously.
2. release-please opens (and keeps updating) a `chore(main): release X.Y.Z` PR
   that bumps `composer.json` + `CHANGELOG.md` from accumulated commits.
3. **Auto-merge runs every Monday at 09:00 UTC** via `.github/workflows/release-please-auto-merge.yml`.
   Required checks must be green; PR is squash-merged + branch deleted.
4. Merge → tag (`vX.Y.Z`) + GitHub Release auto-created + CI/CD Pipeline
   builds + pushes Docker image (`:vX.Y.Z`, `:X.Y`, `:latest`).

**Skip a weekly release:** add label `release-blocked` or `do-not-merge` to
the open release PR before Monday.

**Force a release outside cadence:** GitHub Actions → "Release Please
Auto-Merge" → Run workflow (workflow_dispatch). Use sparingly — defeats
the cadence-discipline purpose.

Version-bump rules driven by commit `type`:

- `fix:` → patch (e.g. 3.3.2 → 3.3.3)
- `feat:` → minor (e.g. 3.3.2 → 3.4.0)
- `feat!:` / `BREAKING CHANGE:` footer → major (e.g. 3.3.2 → 4.0.0)
- `docs:` / `chore:` / `test:` → no release (hidden in changelog)

Hot-fixes bypass this only when a production-breaking issue is live —
otherwise let the release PR accumulate and ship on cadence.

### Dev / Pre-Release Builds

For Docker test deployments without affecting `:latest`:

- **Manual**: trigger the **Dev Release (manual)** GitHub Action with the
  desired bump (patch / minor / major). It computes the next semver,
  appends `-dev.N` (auto-incremented), tags `main` HEAD, pushes — CI builds
  Docker image with `:vX.Y.Z-dev.N` + `:dev` (rolling). **Never `:latest`,
  never `:X.Y`** (semver minor floating tag).
- **Manual via CLI** (alternative): `git tag v3.4.0-dev.1 && git push origin v3.4.0-dev.1`.
  CI applies the same dev-tag rules based on the `-dev.` segment.

Tag-to-image mapping enforced in `.github/workflows/ci.yml`:

| Tag                | Image tags                          |
|--------------------|-------------------------------------|
| `v3.4.0`           | `:3.4.0`, `:3.4`, `:latest`         |
| `v3.4.0-dev.1`     | `:3.4.0-dev.1`, `:dev`              |
| `v3.4.0-rc.1`      | `:3.4.0-rc.1`, `:rc`                |

Pi / production deployments stay on `:latest`. Test environments pull
`:dev` (or pin to a specific `:vX.Y.Z-dev.N`) for QA cycles.

---

## Pull Request Process

### Before Submitting

1. **Update your branch**
   ```bash
   git fetch upstream
   git rebase upstream/main
   ```

2. **Run tests**
   ```bash
   php bin/phpunit
   ```

3. **Check code style**
   ```bash
   # If you have PHP CS Fixer installed
   vendor/bin/php-cs-fixer fix --dry-run --diff
   ```

4. **Update documentation** if needed

5. **Test manually** - verify your changes work as expected

### Creating the Pull Request

1. **Push to your fork**
   ```bash
   git push origin feature/your-feature
   ```

2. **Create PR on GitHub**
   - Go to https://github.com/moag1000/Little-ISMS-Helper
   - Click "New Pull Request"
   - Select your fork and branch
   - Fill out the PR template

### PR Title Format

Follow the same format as commit messages:

```
feat(risk): Add automatic risk recalculation
```

### PR Description Template

```markdown
## Description
Brief description of changes

## Type of Change
- [ ] Bug fix (non-breaking change which fixes an issue)
- [ ] New feature (non-breaking change which adds functionality)
- [ ] Breaking change (fix or feature that would cause existing functionality to not work as expected)
- [ ] Documentation update

## Related Issues
Closes #123

## Changes Made
- Added RiskRecalculationService
- Updated RiskController to trigger recalculation
- Added unit tests

## Testing
- [ ] Unit tests added/updated
- [ ] Manual testing completed
- [ ] All tests pass

## Screenshots (if applicable)
[Add screenshots here]

## Checklist
- [ ] My code follows the project's coding standards
- [ ] I have performed a self-review of my code
- [ ] I have commented my code, particularly in hard-to-understand areas
- [ ] I have made corresponding changes to the documentation
- [ ] My changes generate no new warnings
- [ ] I have added tests that prove my fix is effective or that my feature works
- [ ] New and existing unit tests pass locally with my changes
```

### Review Process

1. **At least one maintainer must approve** the PR
2. **All CI checks must pass**
3. **Address review comments** promptly
4. **Keep the PR updated** with upstream changes
5. **Squash commits** if requested before merge

---

## Testing Requirements

### Unit Tests

All new features must include unit tests using PHPUnit.

**Location:** `tests/`

**Running tests:**
```bash
php bin/phpunit
```

**Example:**
```php
<?php

namespace App\Tests\Service;

use App\Service\RiskIntelligenceService;
use App\Entity\Risk;
use PHPUnit\Framework\TestCase;

class RiskIntelligenceServiceTest extends TestCase
{
    public function testCalculateRiskScore(): void
    {
        $service = new RiskIntelligenceService(/* dependencies */);

        $risk = new Risk();
        $risk->setLikelihood(5);
        $risk->setImpact(4);

        $score = $service->calculateRiskScore($risk);

        $this->assertEquals(20, $score);
    }
}
```

### Test Coverage

- **Aim for 80%+ code coverage** for new code
- **All business logic must be tested**
- **Edge cases should be covered**

### Manual Testing

Before submitting a PR:
- [ ] Test in fresh database
- [ ] Test with different user roles
- [ ] Test error scenarios
- [ ] Test on different browsers (Chrome, Firefox, Safari)
- [ ] Verify mobile responsiveness

---

## Documentation

### When to Update Documentation

Update documentation when:
- Adding new features
- Changing existing behavior
- Adding new configuration options
- Updating dependencies
- Changing API endpoints

### Documentation Locations

- **README.md** - Project overview, installation, quick start
- **docs/** - Detailed documentation
  - `docs/setup/` - Setup guides
  - `docs/architecture/` - Architecture documentation
  - `docs/api/` - API documentation
- **Code comments** - Complex logic explanation
- **Docblocks** - PHP class/method documentation

### Documentation Style

- **Use clear, concise language**
- **Include code examples**
- **Add screenshots for UI changes**
- **Keep it up-to-date**

---

## Questions or Problems?

### Getting Help

- **GitHub Issues:** Open an issue for bugs or feature requests
- **GitHub Discussions:** Ask questions or discuss ideas
- **Email:** Contact the maintainers (see README.md)

### Reporting Bugs

When reporting bugs, include:
- **Symfony version** (`php bin/console about`)
- **PHP version** (`php -v`)
- **Steps to reproduce**
- **Expected vs. actual behavior**
- **Error messages** (full stack trace if possible)
- **Screenshots** (if UI-related)

### Suggesting Features

When suggesting features:
- **Explain the use case** - why is this needed?
- **Describe the solution** - how should it work?
- **Consider alternatives** - are there other ways to solve this?
- **Check existing issues** - has this been suggested before?

---

## License

By contributing to Little ISMS Helper, you agree that your contributions will be licensed under the same license as the project (see LICENSE file).

---

## Thank You!

Your contributions make Little ISMS Helper better for everyone. We appreciate your time and effort! 🎉

For questions about these guidelines, please open an issue or contact the maintainers.

---

**Last Updated:** 2026-04-29
**Project Version:** 3.2.6
