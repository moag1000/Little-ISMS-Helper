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

- PHP 8.4 (recommended) or 8.2+
- Composer 2.x
- PostgreSQL 16+ or MySQL 8.0+
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

**Last Updated:** 2025-11-07
**Version:** 1.0.0
