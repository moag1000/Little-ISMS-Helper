<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The security configuration as Symfony resolves it, per environment (T3, 2026-11).
 *
 * Tests that read config/packages/security.yaml with Yaml::parseFile() missed a firewall or authenticator under
 * `when@prod:` and the singular `custom_authenticator:` key that Symfony maps to the list. This helper loads the
 * kernel's configuration for an environment exactly as the container does (every config file, `when@<env>`
 * blocks, imports) and runs it through the security bundle's own configuration tree, so what the tests see is
 * what the firewall map is built from.
 */
final class ResolvedSecurityConfig
{
    /** @var array<string, array<string, mixed>> */
    private static array $resolved = [];

    /**
     * Environments the configuration knows: prod, dev, test and every `when@<env>` block under config/.
     *
     * @return list<string>
     */
    public static function environments(): array
    {
        $envs = ['prod', 'dev', 'test'];
        $config = \dirname(__DIR__, 2) . '/config';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($config, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && \in_array($file->getExtension(), ['yaml', 'yml'], true)
                && preg_match_all('/^when@(\w+)\s*:/m', (string) file_get_contents($file->getPathname()), $m) > 0) {
                $envs = [...$envs, ...$m[1]];
            }
        }

        return array_values(array_unique($envs));
    }

    /** @return array<string, mixed> the processed `security:` configuration of $env */
    public static function security(string $env): array
    {
        if (!isset(self::$resolved[$env])) {
            $kernel = new Kernel($env, false);
            $builder = (function (): ContainerBuilder {
                /** @var Kernel $this */
                $this->initializeBundles();

                return $this->buildContainer();
            })->call($kernel);
            $configs = $builder->getExtensionConfig('security');
            $configuration = $builder->getExtension('security')->getConfiguration($configs, $builder);
            \assert($configuration instanceof ConfigurationInterface);
            self::$resolved[$env] = (new Processor())->processConfiguration($configuration, $configs);
        }

        return self::$resolved[$env];
    }

    /** @return array<string, array<string, mixed>> firewall name => processed firewall config */
    public static function firewalls(string $env): array
    {
        return self::security($env)['firewalls'] ?? [];
    }

    /**
     * Authenticators a firewall config logs people in with: built-in keys that are configured (form_login, ...)
     * and the custom authenticator classes.
     *
     * @param array<string, mixed> $firewall
     * @param list<string> $builtIn
     * @return list<string>
     */
    public static function authenticators(array $firewall, array $builtIn): array
    {
        $found = [];
        foreach ($builtIn as $key) {
            if (isset($firewall[$key]) && $firewall[$key] !== false) {
                $found[] = $key;
            }
        }

        return [...$found, ...array_values((array) ($firewall['custom_authenticators'] ?? []))];
    }
}
