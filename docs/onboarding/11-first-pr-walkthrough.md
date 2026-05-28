# First PR Walkthrough — Ship a Change on Day 1

This guide walks you through contributing a real, mergeable change from zero to open PR.
The example task is: **fix a missing German translation key** — a common, safe, and
self-contained contribution that touches one file and requires no database migration.

---

## Prerequisites

- Local environment running (see [01-environment-setup.md](01-environment-setup.md))
- Git configured with your name and email (`git config --global user.name/user.email`)
- GitHub account with fork/PR access to the repository

---

## Step 1: Fork and Clone

If you are an external contributor (not a direct collaborator):

```bash
# Fork via GitHub UI first, then:
git clone https://github.com/<your-username>/Little-ISMS-Helper.git
cd Little-ISMS-Helper
git remote add upstream https://github.com/moag1000/Little-ISMS-Helper.git
git fetch upstream
```

If you are a collaborator with direct push access:

```bash
git clone https://github.com/moag1000/Little-ISMS-Helper.git
cd Little-ISMS-Helper
```

---

## Step 2: Create a Feature Branch

Always branch from an up-to-date `main`:

```bash
git checkout main
git pull upstream main   # or: git pull origin main for collaborators
git checkout -b fix/missing-translation-my-key
```

Branch naming convention:
- `fix/…` — bug fixes, missing translations, broken links
- `feat/…` — new features
- `chore/…` — maintenance, dependency bumps, CI changes
- `docs/…` — documentation only
- `refactor/…` — code restructure without behaviour change

---

## Step 3: Find the Missing Translation (Worked Example)

### 3a. Identify the key

Symfony's debug:translation command shows all missing keys:

```bash
php bin/console debug:translation de --only-missing
```

You will see output like:
```
+----------+----------------------------+-------------+--------------------+
| State    | Domain                     | Id          | Message Preview    |
+----------+----------------------------+-------------+--------------------+
| missing  | risk                       | risk.foo.bar| n/a                |
+----------+----------------------------+-------------+--------------------+
```

Alternatively, the quality script finds hardcoded strings and missing domain params:

```bash
python3 scripts/quality/check_translation_issues.py
```

### 3b. Locate the translation files

Translation files live in `translations/` named `<domain>.<locale>.yaml`:

```bash
ls translations/risk.*.yaml
# translations/risk.de.yaml
# translations/risk.en.yaml
```

### 3c. Find where the key is used in a template

```bash
grep -r "risk.foo.bar" templates/
# templates/risk/index.html.twig:   {{ 'risk.foo.bar'|trans({}, 'risk') }}
```

### 3d. Look at the context and write a good translation

Read the surrounding template code to understand what the string means.

Open `translations/risk.de.yaml`:

```yaml
# existing keys above...
foo:
    bar: "Risiko-Foo-Bar"   # <-- add this line
```

Open `translations/risk.en.yaml`:

```yaml
foo:
    bar: "Risk Foo Bar"     # <-- add this line
```

**Rules for good translation keys:**
- German is the primary language — German string must be clear and formal (ISO/BSI style)
- English should mirror the German semantic exactly
- Key hierarchy should match the template's semantic context
- Never use placeholder strings like "TODO" or "fix me"

### 3e. Verify the fix

```bash
php bin/console debug:translation de --only-missing 2>&1 | grep "risk.foo.bar"
# Should show nothing (key is now present)

php bin/console debug:translation en --only-missing 2>&1 | grep "risk.foo.bar"
# Same check for English
```

---

## Step 4: Run the Relevant Tests

Do not run the full 2850+ test suite for a translation change. Run targeted:

```bash
# Lint all templates (catches translation key syntax errors)
php bin/console lint:twig templates/

# Translation quality script
python3 scripts/quality/check_translation_issues.py

# If you changed a specific template, run its test
php bin/phpunit tests/Controller/RiskControllerTest.php
```

Full suite is only required immediately before opening a PR:

```bash
php bin/phpunit
```

---

## Step 5: Commit with Conventional Commits

```bash
git add translations/risk.de.yaml translations/risk.en.yaml
git commit -m "fix(translations): add missing risk.foo.bar key in risk domain (DE/EN)"
```

**Conventional commit format:** `<type>(<scope>): <description>`

| Type | When to use |
|---|---|
| `feat` | New feature visible to users |
| `fix` | Bug fix (including missing translations) |
| `chore` | Maintenance, deps, CI |
| `docs` | Documentation only |
| `refactor` | Code restructure, no behaviour change |
| `test` | Tests only |
| `style` | Formatting, whitespace (no logic change) |

Scope is optional but helpful — use the affected area (`risk`, `assets`, `admin`, `ci`, etc.).

**What reviewers look for in commits:**
- One logical change per commit (not a mix of feature + formatting + translation)
- Commit message explains *why*, not just *what* (for non-obvious changes)
- No `WIP`, `tmp`, `fix`, `asdf` commit messages

---

## Step 6: Run Pre-Push CI Gates

Before pushing, run all gates that touch the files you changed:

```bash
# PHP syntax (always)
find src -name "*.php" -print0 | xargs -0 -n1 php -l

# Container wiring (always)
php bin/console lint:container

# Templates (if you touched .html.twig)
php bin/console lint:twig templates/

# Full test suite (required before PR)
php bin/phpunit
```

See [06-quality-gates.md](06-quality-gates.md) for the full gate list.

---

## Step 7: Open the Pull Request

Push your branch:

```bash
git push origin fix/missing-translation-my-key
```

Then open a PR on GitHub. Use the PR template (`.github/PULL_REQUEST_TEMPLATE.md`).
See [10-pr-template-guide.md](10-pr-template-guide.md) for how to fill it in.

**Checklist before submitting:**
- [ ] Branch is up-to-date with `main` (rebase if needed: `git rebase upstream/main`)
- [ ] `php bin/phpunit` passes locally
- [ ] `php bin/console lint:twig templates/` passes
- [ ] Commit messages follow Conventional Commits
- [ ] PR description explains the *why* (even for small fixes)
- [ ] No debug output, no commented-out code left in

---

## Step 8: Respond to Review

A reviewer may:
- **Approve immediately** for straightforward fixes
- **Request changes** — address each comment, then push a new commit (don't force-push unless asked)
- **Ask questions** — answer in the PR thread, not in the code

After approval, the maintainer will squash-merge and delete your branch.

---

## Where to Get Help

- **GitHub Issues** — search for existing issues before opening a new one
- **PR comments** — questions in your PR are visible to all contributors
- **CLAUDE.md** — the authoritative guide for this codebase (checked into repo root)
- **docs/onboarding/** — the full onboarding series (this file is part of it)

For regulatory/compliance questions (ISO 27001 clause interpretation, NIS2 implementation
guidance), see [docs/onboarding/07-personas-and-skills.md](07-personas-and-skills.md) — the
project includes persona-specific skills for ISMS, BSI, DORA, and DPO contexts.

---

## Good First Issues

Look for issues labelled `good first issue` on GitHub. These are typically:
- Missing translation keys (DE or EN)
- Broken links in documentation
- Accessibility improvements (missing `aria-label`, incorrect heading hierarchy)
- Typos in UI strings
- Minor template improvements

Issues labelled `needs-maintainer` or `architecture` require familiarity with the codebase
and are not suitable as first contributions.

---

## Day 1 Checklist Summary

```bash
git checkout -b fix/my-fix
# ... make change ...
php bin/console lint:twig templates/
python3 scripts/quality/check_translation_issues.py
php bin/phpunit
git add <files>
git commit -m "fix(scope): description"
git push origin fix/my-fix
# Open PR on GitHub
```

That's it. Welcome to the project.
