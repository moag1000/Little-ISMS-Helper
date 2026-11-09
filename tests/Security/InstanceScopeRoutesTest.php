<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\EventSubscriber\GlobalCatalogueWriteSubscriber;
use App\Security\Voter\GlobalCatalogueVoter;
use App\Security\Voter\PermissionVoter;
use App\Security\Voter\TenantScopedAdminVoter;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * S13: an organisation admin on an instance with several organisations must not act for
 * all of them. The test finds the routes itself:
 *
 *  1. Instance operations — every route whose controller uses a service that changes the
 *     instance (schema of the shared database, composer, cache, logs) or reads the logs of
 *     every organisation, and every route under /admin/monitoring except the organisation's
 *     audit log, is gated by an instance attribute: a PermissionVoter instance permission,
 *     ADMIN_GLOBAL_OP or ROLE_SUPER_ADMIN.
 *  2. Shared catalogue — every state-changing route of a controller that creates global
 *     catalogue rows (directly or through a service that does) is guarded by
 *     GlobalCatalogueVoter::WRITE, ADMIN_GLOBAL_OP or ROLE_SUPER_ADMIN, either as attribute or
 *     through the route list of GlobalCatalogueWriteSubscriber.
 *
 * A route that checks inside its action (or writes only rows of the organisation) is listed
 * below with the reason. Whatever slips through anyway, the onFlush backstop
 * GlobalMappingWriteGuard refuses — but a new `bin/console` process does not pass it.
 */
final class InstanceScopeRoutesTest extends KernelTestCase
{
    /** Services that change the whole instance or read its logs. */
    private const array INSTANCE_SERVICES = [
        \App\Service\HealthAutoFixService::class,
        \App\Service\SchemaHealthService::class,
    ];

    /** Instance services in controllers with their own operator guard. */
    private const array INSTANCE_ALLOWED = [
        // Quick-Fix: QuickFixGuard::mayRunPrivileged() (super admin or installer token, #1124).
        \App\Controller\QuickFixController::class => true,
    ];

    /** Catalogue writers that are not found by the `new <Entity>(` scan. */
    private const array EXTRA_CATALOGUE_SERVICES = [
        \App\Service\ComplianceLoaderFixerService::class,
        \App\Service\Compliance\MappingSeedService::class,
        \App\Service\Compliance\FrameworkLoaderRegistry::class,
        \App\Service\DataImportService::class,
    ];

    private const string CATALOGUE_ENTITIES = 'ComplianceMapping|ComplianceFramework|ComplianceRequirement|MappingGapItem';

    /** controller => reason. */
    private const array CATALOGUE_ALLOWED_CONTROLLERS = [
        // Setup wizard: before setup, or afterwards only the super admin (SetupAccessChecker::ACCESS).
        \App\Controller\DeploymentWizardController::class => true,
    ];

    /** route => reason. */
    private const array CATALOGUE_ALLOWED = [
        'admin_modules_activate' => 'module switch of the organisation; DataImportService only injected for import-data',
        'admin_modules_deactivate' => 'module switch of the organisation; DataImportService only injected for import-data',
        'admin_compliance_import_cancel' => 'drops the preview from the session, writes nothing',
        'admin_sample_data_import' => 'catalogue commands skipped by DataImportService unless GlobalCatalogueVoter::userMayWrite()',
        'admin_sample_data_remove' => 'framework removal checks ADMIN_GLOBAL_OP inside; file samples are the organisation\'s',
        'admin_modules_import_data' => 'catalogue commands skipped by DataImportService unless GlobalCatalogueVoter::userMayWrite()',
        'app_admin_industry_preset_apply' => 'framework activation only with GlobalCatalogueVoter::WRITE; modules/controls/documents are the organisation\'s',
        'app_compliance_requirement_edit' => 'assertCanEditRequirement(): shared rows ADMIN_GLOBAL_OP, own uploaded rows ROLE_MANAGER',
        'app_compliance_requirement_delete' => 'assertCanEditRequirement(): shared rows ADMIN_GLOBAL_OP',
        'app_compliance_requirement_quick_update' => 'writes the organisation\'s fulfilment, not the requirement',
        'app_compliance_requirement_mris_maturity' => 'maturity on the requirement row — moved to the fulfilment by S18',
        'app_compliance_mapping_new' => 'GlobalMappingWriteGuard refuses a global mapping; mappings of own uploaded requirements pass',
        'app_compliance_mapping_bulk_status_change' => 'GlobalMappingWriteGuard refuses global mappings; own mappings pass',
        'app_compliance_mapping_wizard' => 'GlobalMappingWriteGuard refuses global mappings; own mappings pass',
        'app_compliance_framework_migrate' => 'listed in GlobalCatalogueWriteSubscriber; GET preview + ADMIN_GLOBAL_OP inside',
        'app_mapping_quality_gap_update' => 'listed in GlobalCatalogueWriteSubscriber (gap route, subject = its mapping)',
        'app_tisax_import_disclaimer' => 'session only',
        'app_tisax_import_upload' => 'session only',
        'app_tisax_import_validate' => 'session only',
        'app_tisax_import_preview' => 'session only',
        'app_tisax_import_commit' => 'requirements carry the organisation as uploadTenant; framework upsert is caught by the backstop when it changes',
        'app_tisax_import_assess' => 'maturity of the organisation\'s own uploaded requirements',
        'app_compliance_wizard_confirm_manual' => 'organisation\'s wizard confirmation',
        'app_compliance_wizard_override_effort' => 'organisation\'s fulfilment',
        'app_compliance_wizard_snapshot' => 'organisation\'s snapshot',
    ];

    #[Test]
    public function instanceOperationsNeedTheSuperAdmin(): void
    {
        $failures = [];
        foreach ($this->routes() as $name => [$route, $class, $method]) {
            $usesInstanceService = $this->uses($class, $method, self::INSTANCE_SERVICES);
            $isMonitoring = str_starts_with($route->getPath(), '/admin/monitoring')
                || str_contains($route->getPath(), '/admin/monitoring');
            if ($isMonitoring && $name === 'monitoring_audit_log') {
                continue; // the organisation's own audit trail (AUDIT_VIEW, tenant-filtered)
            }
            if (!$usesInstanceService && !$isMonitoring) {
                continue;
            }
            if (isset(self::INSTANCE_ALLOWED[$class])) {
                continue;
            }
            $instanceAttributes = [
                ...PermissionVoter::instancePermissions(),
                TenantScopedAdminVoter::ADMIN_GLOBAL_OP,
                'ROLE_SUPER_ADMIN',
            ];
            if (!$this->gatedBy($class, $method, $instanceAttributes)) {
                $failures[] = $name . ' (' . $class . '::' . $method . ')';
            }
        }

        self::assertSame([], $failures, "Instance operations reachable for an organisation admin:\n" . implode("\n", $failures));
    }

    #[Test]
    public function sharedCatalogueWritesNeedTheCatalogueRight(): void
    {
        $writers = $this->catalogueWriters();
        $guarded = GlobalCatalogueWriteSubscriber::guardedRoutes();
        $failures = [];
        $checked = 0;
        foreach ($this->routes() as $name => [$route, $class, $method]) {
            $methods = $route->getMethods();
            if ($methods !== [] && array_diff($methods, ['GET', 'HEAD']) === []) {
                continue; // read-only
            }
            if (!$this->writesCatalogue($class, $method, $writers)) {
                continue;
            }
            ++$checked;
            if (in_array($name, $guarded, true) || isset(self::CATALOGUE_ALLOWED[$name])
                || isset(self::CATALOGUE_ALLOWED_CONTROLLERS[$class])
            ) {
                continue;
            }
            if (!$this->gatedBy($class, $method, [GlobalCatalogueVoter::WRITE, TenantScopedAdminVoter::ADMIN_GLOBAL_OP, 'ROLE_SUPER_ADMIN'])) {
                $failures[] = $name . ' (' . $class . '::' . $method . ')';
            }
        }

        self::assertGreaterThan(10, $checked, 'the discovery found almost no catalogue writers — scan broken?');
        self::assertSame([], $failures, "Shared catalogue writable by an organisation admin:\n" . implode("\n", $failures));
    }

    /** route => reason; actions that start a console command and are fine for an organisation admin. */
    private const array CONSOLE_ALLOWED = [
        'admin_queue_process_now' => 'drains the shared queue; each job runs in the organisation of its dispatcher (JobTenantScope)',
    ];

    /**
     * A console command started from a request runs as the instance operator: a separate
     * `bin/console` process passes no Doctrine guard at all. Such an action is gated like an
     * instance or catalogue operation, or listed with its reason.
     */
    #[Test]
    public function consoleCommandsFromARequestAreGated(): void
    {
        $guarded = GlobalCatalogueWriteSubscriber::guardedRoutes();
        $failures = [];
        foreach ($this->routes() as $name => [, $class, $method]) {
            if (preg_match('/new [\\\\\w]*\b(?:Process|Application)\(/', $this->body($class, $method)) !== 1) {
                continue;
            }
            if (in_array($name, $guarded, true) || isset(self::CONSOLE_ALLOWED[$name])
                || isset(self::INSTANCE_ALLOWED[$class]) || isset(self::CATALOGUE_ALLOWED_CONTROLLERS[$class])
            ) {
                continue;
            }
            $attributes = [
                ...PermissionVoter::instancePermissions(),
                GlobalCatalogueVoter::WRITE,
                TenantScopedAdminVoter::ADMIN_GLOBAL_OP,
                'ROLE_SUPER_ADMIN',
            ];
            if (!$this->gatedBy($class, $method, $attributes)) {
                $failures[] = $name . ' (' . $class . '::' . $method . ')';
            }
        }

        self::assertSame([], $failures, "Console commands reachable for an organisation admin:\n" . implode("\n", $failures));
    }

    #[Test]
    public function everyAllowListEntryIsARoute(): void
    {
        $routes = $this->routes();
        foreach ([...array_keys(self::CATALOGUE_ALLOWED), ...array_keys(self::CONSOLE_ALLOWED)] as $name) {
            self::assertArrayHasKey($name, $routes, sprintf('Allow-list entry "%s" is no route any more.', $name));
        }
    }

    /** @return array<string, array{Route, class-string, string}> */
    private function routes(): array
    {
        self::bootKernel();
        $out = [];
        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection() as $name => $route) {
            $controller = $route->getDefault('_controller');
            if (!is_string($controller) || !str_contains($controller, '::') || !str_starts_with($controller, 'App\\')) {
                continue;
            }
            [$class, $method] = explode('::', $controller, 2);
            if (!method_exists($class, $method)) {
                continue;
            }
            $out[(string) preg_replace('/\.(de|en)$/', '', $name)] = [$route, $class, $method];
        }

        return $out;
    }

    /** @param list<class-string> $services */
    private function uses(string $class, string $method, array $services): bool
    {
        $types = $this->parameterTypes(new ReflectionMethod($class, $method));
        $ctor = (new ReflectionClass($class))->getConstructor();
        if ($ctor !== null) {
            $types = [...$types, ...$this->parameterTypes($ctor)];
        }

        return array_intersect($types, $services) !== [];
    }

    /** @param list<class-string> $writers */
    private function writesCatalogue(string $class, string $method, array $writers): bool
    {
        if ($this->uses($class, $method, $writers)) {
            return true;
        }
        $body = $this->body($class, $method);

        return preg_match('/new \\\\?(?:App\\\\Entity\\\\)?(?:' . self::CATALOGUE_ENTITIES . ')\(/', $body) === 1;
    }

    private function body(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $lines = file((string) $reflection->getFileName()) ?: [];

        return implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
    }

    /** @return list<class-string> services whose code creates catalogue rows */
    private function catalogueWriters(): array
    {
        $writers = self::EXTRA_CATALOGUE_SERVICES;
        $root = dirname(__DIR__, 2) . '/src/Service';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (preg_match('/new \\\\?(?:App\\\\Entity\\\\)?(?:' . self::CATALOGUE_ENTITIES . ')\(/', $code) !== 1) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $writers[] = 'App\\Service\\' . str_replace('/', '\\', $relative);
        }

        return array_values(array_unique($writers));
    }

    /** @return list<string> */
    private function parameterTypes(ReflectionMethod $method): array
    {
        $types = [];
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $types[] = $type->getName();
            }
        }

        return $types;
    }

    /** @param list<string> $attributes */
    private function gatedBy(string $class, string $method, array $attributes): bool
    {
        $isGranted = [
            ...(new ReflectionClass($class))->getAttributes(IsGranted::class),
            ...(new ReflectionMethod($class, $method))->getAttributes(IsGranted::class),
        ];
        foreach ($isGranted as $attribute) {
            $value = $attribute->newInstance()->attribute;
            $text = is_string($value) ? $value : (string) $value;
            foreach ($attributes as $wanted) {
                if ($text === $wanted || (!is_string($value) && str_contains($text, $wanted))) {
                    return true;
                }
            }
        }

        return false;
    }
}
