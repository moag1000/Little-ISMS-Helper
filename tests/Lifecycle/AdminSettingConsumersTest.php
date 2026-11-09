<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Lifecycle\Config\LifecycleOverridePolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Every admin setting has a consumer (S15, lesson 10).
 *
 * The workflow step editor under /admin/workflows saved approver role, approvers, days,
 * auto-progress conditions, reason, four eyes and module per organisation — and nothing
 * read them. This test keeps that from happening again for the two stores admin pages
 * write settings into:
 *
 *  - lifecycle_config: only LifecycleOverridesController creates rows, only with the keys
 *    of {@see LifecycleOverridePolicy::KEYS}, and each key is read by a runtime consumer
 *    (voter, guard or validator) through the resolver;
 *  - system_settings: every `setSetting('<category>', '<key>')` has a reader outside
 *    the controllers and admin templates (`getSetting('<category>', '<key>')`, a loop
 *    `getSetting('<category>', $key)` over a list naming the key, or the dotted
 *    `'<category>.<key>'` a resolver uses). Writers naming the category by a class constant
 *    (`self::CATEGORY`) or looping over a literal key list count too (W8);
 *  - the admin config entities (W8: branding, incident SLA, supplier criticality, notification
 *    rules/channels, planning, the organisation's mail and time zone columns): every stored
 *    field is read outside the entity, its form, repository, the admin pages and the
 *    backup/clone plumbing. Seven dead branding fields, a "deactivated" channel that still
 *    delivered and an SLA nothing measured got past every review that way.
 */
final class AdminSettingConsumersTest extends TestCase
{
    /** Key → class that must read it (through LifecycleConfigResolverInterface). */
    private const array LIFECYCLE_CONSUMERS = [
        'roles' => 'src/Security/Voter/LifecycleVoter.php',
        'reason_required' => 'src/Lifecycle/EventListener/ReasonValidator.php',
        'four_eyes' => 'src/Lifecycle/EventListener/FourEyesValidator.php',
        'module' => 'src/Lifecycle/Guard/ModuleGateGuard.php',
    ];

    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    #[Test]
    public function everyLifecycleOverrideKeyHasAConsumer(): void
    {
        self::assertSame(LifecycleOverridePolicy::KEYS, array_keys(self::LIFECYCLE_CONSUMERS));
        foreach (self::LIFECYCLE_CONSUMERS as $key => $file) {
            $code = (string) file_get_contents(self::root() . '/' . $file);
            self::assertStringContainsString('resolver->', $code, $file . ' reads through the resolver');
            self::assertMatchesRegularExpression(
                '/[\'"]' . preg_quote($key, '/') . '[\'"]/',
                $code,
                sprintf('lifecycle override key "%s" is written by the admin page but %s never reads it', $key, $file),
            );
        }
    }

    #[Test]
    public function onlyTheLifecycleOverridesPageWritesLifecycleConfigRows(): void
    {
        $writers = [];
        foreach ((new Finder())->files()->in(self::root() . '/src')->name('*.php') as $file) {
            if (str_contains($file->getContents(), 'new LifecycleConfig()')) {
                $writers[] = 'src/' . str_replace('\\', '/', $file->getRelativePathname());
            }
        }

        self::assertSame(
            ['src/Controller/Admin/LifecycleOverridesController.php'],
            $writers,
            'A new writer of lifecycle_config needs a consumer of what it writes (see LIFECYCLE_CONSUMERS).',
        );
    }

    #[Test]
    public function theLifecycleOverridesPageWritesOnlyPolicyKeys(): void
    {
        $code = (string) file_get_contents(self::root() . '/src/Controller/Admin/LifecycleOverridesController.php');
        preg_match('/\$values = \[\n(.*?)\n\s*\];/s', $code, $m);
        self::assertNotEmpty($m, 'the controller collects the submitted keys in $values');
        preg_match_all("/^\s*'([a-z_]+)' =>/m", $m[1], $keys);

        self::assertSame(LifecycleOverridePolicy::KEYS, $keys[1]);
    }

    /**
     * Source with `self::CONST` / `static::CONST` replaced by the string the class declares.
     */
    private static function withConstants(string $code): string
    {
        preg_match_all("/const\\s+(?:string\\s+)?([A-Z_]+)\\s*=\\s*'([^']*)'/", $code, $consts, PREG_SET_ORDER);
        foreach ($consts as [, $name, $value]) {
            $code = preg_replace('/(?:self|static)::' . $name . '\\b/', "'" . $value . "'", $code) ?? $code;
        }

        return $code;
    }

    /**
     * (category, key) pairs a file writes: literal keys, and `foreach ($list as $key => …)`
     * loops whose `$list = [ 'key' => … ]` literal names them.
     *
     * @return list<array{string, string}>
     */
    private static function settingWrites(string $code): array
    {
        $code = self::withConstants($code);
        $pairs = [];
        preg_match_all('/setSetting\(\s*(?:category:\s*)?\'([a-z_]+)\',\s*(?:key:\s*)?(?:\'([a-z_]+)\'|\$(\w+))/', $code, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            if (($m[2] ?? '') !== '') {
                $pairs[] = [$m[1], $m[2]];
                continue;
            }
            $keyVar = $m[3];
            self::assertMatchesRegularExpression(
                '/foreach\s*\(\$\w+\s+as\s+\$' . $keyVar . '\s*=>/',
                $code,
                sprintf('setSetting(\'%s\', $%s) writes a key the scan cannot name — loop over a literal list', $m[1], $keyVar),
            );
            preg_match('/foreach\s*\(\$(\w+)\s+as\s+\$' . $keyVar . '\s*=>/', $code, $loop);
            preg_match('/\$' . $loop[1] . '\s*=\s*\[(.*?)\];/s', $code, $list);
            self::assertNotEmpty($list, sprintf('the key list $%s is a literal array', $loop[1]));
            preg_match_all("/^\\s*'([a-z_]+)'\\s*=>/m", $list[1], $keys);
            foreach ($keys[1] as $key) {
                $pairs[] = [$m[1], $key];
            }
        }

        return $pairs;
    }

    /** Admin pages and controllers show and save settings; they are not what makes one work. */
    private static function isAdminSurface(string $path): bool
    {
        return str_contains($path, '/Controller/') || str_contains($path, '/templates/admin/');
    }

    #[Test]
    public function everySystemSettingWrittenHasAReader(): void
    {
        $files = [];
        foreach ((new Finder())->files()->in([self::root() . '/src', self::root() . '/templates'])->name(['*.php', '*.twig']) as $file) {
            $files[$file->getRealPath()] = $file->getContents();
        }
        $readable = array_map(self::withConstants(...), $files);

        $checked = 0;
        foreach ($files as $path => $code) {
            if (!str_contains($code, 'setSetting(')) {
                continue;
            }
            foreach (self::settingWrites($code) as [$category, $key]) {
                ++$checked;
                $read = sprintf(
                    '/getSetting\(\s*\'%1$s\',\s*\'%2$s\'|[\'"]%1$s\.%2$s[\'"]/',
                    preg_quote($category, '/'),
                    preg_quote($key, '/'),
                );
                // A reader that loops over keys (`getSetting('application', $key)`) names the key elsewhere.
                $loopRead = static fn (string $other): bool => str_contains($other, "getSetting('" . $category . "', \$")
                    && str_contains($other, "'" . $key . "'");
                // A service may write and read the same key (AiSettings); a controller may not count.
                $readers = array_filter(
                    $readable,
                    static fn (string $other, string $otherPath): bool => (preg_match($read, $other) === 1 || $loopRead($other))
                        && !self::isAdminSurface($otherPath),
                    ARRAY_FILTER_USE_BOTH,
                );
                self::assertNotSame(
                    [],
                    $readers,
                    sprintf('system setting %s.%s is written in %s but nothing outside controllers reads it', $category, $key, $path),
                );
            }
        }
        self::assertGreaterThan(0, $checked, 'the scan found the settings pages');
    }

    /**
     * Admin-configured entities and the fields they store. Fields left out are listed in
     * NOT_SETTINGS with the reason.
     *
     * @var list<string>
     */
    private const array CONFIG_ENTITIES = [
        'TenantBranding',
        'IncidentSlaConfig',
        'SupplierCriticalityLevel',
        'Notification/NotificationRule',
        'Notification/NotificationChannel',
        'PlanningSettings',
    ];

    /** Columns that are bookkeeping, not a setting. */
    private const array NOT_SETTINGS = ['id', 'tenant', 'createdAt', 'updatedAt', 'createdBy', 'updatedBy', 'updatedByUser', 'lockVersion'];

    /** Fields read another way than a getter, each with where. */
    private const array READ_ELSEWHERE = [
        'SupplierCriticalityLevel::$isDefault' => 'SupplierCriticalityLevelRepository::findDefaultForTenant() → SupplierCriticalityLabels::defaultCode() presets new suppliers',
        'Notification/NotificationRule::$evaluationCount' => 'statistics the evaluator writes, shown on the rule page — not a setting',
        'Notification/NotificationRule::$lastEvaluatedAt' => 'statistics the evaluator writes, shown on the rule page — not a setting',
        'Notification/NotificationChannel::$rules' => 'inverse side of NotificationRule.channels, which the dispatcher reads',
    ];

    /** Tenant columns an admin page sets that have to work somewhere. */
    private const array TENANT_SETTING_COLUMNS = [
        'emailFromName', 'emailFromAddress', 'emailFooterText', 'emailSupportAddress', 'timezone',
    ];

    /** @return array<string, string> path => code of everything that may count as a reader */
    private static function readerFiles(): array
    {
        $files = [];
        foreach ((new Finder())->files()->in([self::root() . '/src', self::root() . '/templates'])->name(['*.php', '*.twig']) as $file) {
            $path = str_replace('\\', '/', (string) $file->getRealPath());
            if (self::isAdminSurface($path)
                || str_contains($path, '/src/Form/')
                || str_contains($path, '/src/Repository/')
                || str_contains($path, '/src/Entity/')
                || preg_match('#/src/(?:Service|Command)/(?:Backup|Restore|Clone|DataIntegrity)|/Backup[^/]*\.php$|/Restore[^/]*\.php$|Cloner\.php$#', $path) === 1
            ) {
                continue;
            }
            $files[$path] = $file->getContents();
        }

        return $files;
    }

    /** @return list<string> */
    private static function columnsOf(string $entityFile): array
    {
        $code = (string) file_get_contents($entityFile);
        preg_match_all('/#\[ORM\\\\(?:Column|ManyToOne|OneToOne|ManyToMany)\b[^\n]*\n(?:\s*#\[[^\n]*\n)*\s*(?:private|protected)\s+[^$]*\$(\w+)/', $code, $m);

        return array_values(array_diff($m[1], self::NOT_SETTINGS));
    }

    private static function isRead(string $field, array $files): bool
    {
        $ucfirst = ucfirst($field);
        $php = '/->(?:get|is|has)' . $ucfirst . '\(/';
        $twig = '/\.(?:' . preg_quote($field, '/') . '|get' . $ucfirst . '|is' . $ucfirst . '|has' . $ucfirst . ')\b/';
        foreach ($files as $path => $code) {
            if (preg_match($php, $code) === 1 || (str_ends_with($path, '.twig') && preg_match($twig, $code) === 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Public methods of the entity that use the field — a view such as getPolicyDocStyleConfig()
     * or getLabel($locale) that bundles several columns for the readers.
     *
     * @return list<string>
     */
    private static function entityViewsOf(string $field, string $entityCode): array
    {
        preg_match_all('/public function (\w+)\([^)]*\)[^{]*\{(.*?)\n    \}/s', $entityCode, $methods, PREG_SET_ORDER);
        $views = [];
        foreach ($methods as [, $name, $body]) {
            if (preg_match('/\$this->' . $field . '\b/', $body) === 1 && preg_match('/^(?:get|is|has)/', $name) === 1
                && strcasecmp($name, 'get' . $field) !== 0 && strcasecmp($name, 'is' . $field) !== 0) {
                $views[] = lcfirst((string) preg_replace('/^(?:get|is|has)/', '', $name));
            }
        }

        return $views;
    }

    #[Test]
    public function everyStoredAdminConfigFieldHasAReader(): void
    {
        $files = self::readerFiles();
        $unread = [];
        foreach (self::CONFIG_ENTITIES as $entity) {
            $entityFile = self::root() . '/src/Entity/' . $entity . '.php';
            $entityCode = (string) file_get_contents($entityFile);
            $columns = self::columnsOf($entityFile);
            self::assertNotSame([], $columns, $entity . ' has columns');
            foreach ($columns as $field) {
                if (isset(self::READ_ELSEWHERE[$entity . '::$' . $field])) {
                    continue;
                }
                $viaView = array_filter(self::entityViewsOf($field, $entityCode), static fn (string $view): bool => self::isRead($view, $files));
                if ($viaView === [] && !self::isRead($field, $files)) {
                    $unread[] = $entity . '::$' . $field;
                }
            }
        }
        foreach (self::TENANT_SETTING_COLUMNS as $field) {
            if (!self::isRead($field, $files)) {
                $unread[] = 'Tenant::$' . $field;
            }
        }

        self::assertSame(
            [],
            $unread,
            'Admin settings stored but never read outside the admin pages — wire them where the norm or persona needs them, or drop the field (migration + UPGRADE.md)',
        );
    }
}
