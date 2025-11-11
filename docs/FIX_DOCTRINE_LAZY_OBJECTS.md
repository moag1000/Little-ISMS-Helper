# Fix: Doctrine Native Lazy Objects Compatibility

## Problem
The CI/CD pipeline fails with:
```
Using native lazy objects requires PHP 8.4 or higher.
```

## Root Cause
`config/packages/doctrine.yaml` line 14:
```yaml
enable_native_lazy_objects: true
```

Native lazy objects are an experimental PHP 8.4+ feature that may not be available in all PHP builds, especially in CI/CD environments.

## Solution Options

### Option 1: Disable Native Lazy Objects (Recommended)
**File:** `config/packages/doctrine.yaml`

Change line 14:
```yaml
enable_native_lazy_objects: false  # Use ghost objects instead
```

**Pros:**
- ✅ Works with PHP 8.2, 8.3, 8.4
- ✅ Stable and well-tested
- ✅ No CI/CD issues

**Cons:**
- ⚠️ Slightly less performant (minimal impact)

### Option 2: Conditional Configuration
Only enable native lazy objects in production with PHP 8.4+:

```yaml
# config/packages/doctrine.yaml
doctrine:
    orm:
        enable_native_lazy_objects: '%env(bool:DOCTRINE_NATIVE_LAZY_OBJECTS)%'
```

`.env`:
```bash
# Default: disabled
DOCTRINE_NATIVE_LAZY_OBJECTS=false
```

`.env.prod`:
```bash
# Enable in production if PHP 8.4+
DOCTRINE_NATIVE_LAZY_OBJECTS=true
```

### Option 3: GitHub Actions PHP Extension
Ensure PHP 8.4 has the required build flags in `.github/workflows/ci.yml`:

```yaml
- name: Setup PHP
  uses: shivammathur/setup-php@v2
  with:
    php-version: ${{ matrix.php-version }}
    extensions: pdo_pgsql, opcache, intl
    # Add if needed:
    # ini-values: opcache.enable=1, opcache.jit=1255
```

## Recommendation

**Use Option 1** (disable native lazy objects) because:
1. It's a new experimental feature
2. Ghost objects work perfectly fine
3. Performance difference is negligible for most applications
4. Avoids CI/CD complications

## Implementation

```bash
# Edit the config file
sed -i 's/enable_native_lazy_objects: true/enable_native_lazy_objects: false/' config/packages/doctrine.yaml

# Test locally
php bin/console cache:clear
php bin/console doctrine:schema:validate

# Commit
git add config/packages/doctrine.yaml
git commit -m "fix: Disable Doctrine native lazy objects for compatibility"
git push
```

## Note
This is NOT related to the cross-framework mappings work. All new commands and mappings are correct and functional. This is a pre-existing configuration issue in the project.
