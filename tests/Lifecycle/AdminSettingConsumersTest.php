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
 *  - system_settings: every literal `setSetting('<category>', '<key>')` has a reader outside
 *    the controllers (`getSetting('<category>', '<key>')`, a loop `getSetting('<category>', $key)`
 *    over a list naming the key, or the dotted `'<category>.<key>'` a resolver uses).
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

    #[Test]
    public function everySystemSettingWrittenHasAReader(): void
    {
        $files = [];
        foreach ((new Finder())->files()->in([self::root() . '/src', self::root() . '/templates'])->name(['*.php', '*.twig']) as $file) {
            $files[$file->getRealPath()] = $file->getContents();
        }

        $write = '/setSetting\(\s*(?:category:\s*)?\'([a-z_]+)\',\s*(?:key:\s*)?\'([a-z_]+)\'/';
        $checked = 0;
        foreach ($files as $path => $code) {
            if (!preg_match_all($write, $code, $matches, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($matches as [, $category, $key]) {
                ++$checked;
                $read = sprintf(
                    '/getSetting\(\s*\'%1$s\',\s*\'%2$s\'|[\'"]%1$s\.%2$s[\'"]/',
                    preg_quote($category, '/'),
                    preg_quote($key, '/'),
                );
                // A reader that loops over keys (`getSetting('application', $key)`) names the key elsewhere.
                $loopRead = static fn (string $other): bool => str_contains($other, "getSetting('" . $category . "', \$")
                    && str_contains($other, "'" . $key . "'");
                $readers = array_filter(
                    $files,
                    static fn (string $other, string $otherPath): bool => $otherPath !== $path
                        && (preg_match($read, $other) === 1 || $loopRead($other))
                        && !str_contains($otherPath, '/Controller/'),
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
}
