<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Every export or download action names who may run it: an #[IsGranted] (role, voter
 * attribute or Expression) on the action or its controller. Before S16/B1 the
 * ComplianceExportController exports relied on the catch-all access_control rule alone,
 * so one changed rule would have opened twelve export endpoints at once.
 *
 * Public token downloads (no login by design) are listed with their reason.
 */
final class ExportRoutesGatedTest extends TestCase
{
    /** "Class::method" => reason. */
    private const PUBLIC_BY_DESIGN = [];

    /**
     * Actions that rely on the catch-all access_control rule alone. The seven found when the detection was widened to
     * csv/xlsx/pdf/print/report and class prefixes (T3) carry #[IsGranted('ROLE_USER')] now; a new entry needs a
     * reason, a fixed one must be removed. "Class::method" => what the action does today.
     *
     * @var array<string, string>
     */
    private const ACCESS_CONTROL_ONLY = [];

    /** Attributes that every visitor (PUBLIC_ACCESS) or every logged-in user satisfies are no gate. */
    private const NOT_A_GATE = '/^(PUBLIC_ACCESS|IS_AUTHENTICATED(_[A-Z_]+)?|IS_ANONYMOUS|IS_IMPERSONATOR)$/';

    /** Name, path or class prefix of an export/download-like route. */
    private const EXPORT_LIKE = '/export|download|csv|xlsx|pdf|print|report/i';

    #[Test]
    public function everyExportOrDownloadActionCarriesIsGranted(): void
    {
        $root = \dirname(__DIR__, 2);
        $offenders = [];
        $checked = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src/Controller', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $class = 'App\\Controller\\' . str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), \strlen($root . '/src/Controller/')));
            if (!class_exists($class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || $reflection->isTrait()) {
                continue;
            }
            $classGated = $this->hasRealGate($reflection);
            $prefix = '';
            foreach ($reflection->getAttributes(Route::class, \ReflectionAttribute::IS_INSTANCEOF) as $classRoute) {
                $classRouteObject = $classRoute->newInstance();
                $prefix .= ' ' . $classRouteObject->getName() . ' ' . implode(' ', (array) $classRouteObject->getPath());
            }

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(Route::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                    $route = $attribute->newInstance();
                    $paths = (array) $route->getPath();
                    $haystack = $route->getName() . ' ' . implode(' ', $paths) . ' ' . $method->getName() . ' ' . $prefix;
                    if (preg_match(self::EXPORT_LIKE, $haystack) !== 1) {
                        continue;
                    }
                    ++$checked;
                    $key = $class . '::' . $method->getName();
                    $gated = $classGated || $this->hasRealGate($method);
                    if (!$gated && !isset(self::PUBLIC_BY_DESIGN[$key]) && !isset(self::ACCESS_CONTROL_ONLY[$key])) {
                        $offenders[$key] = $key . ' (' . implode(', ', $paths) . ')';
                    }
                }
            }
        }

        $stale = array_keys(array_filter(self::ACCESS_CONTROL_ONLY, static fn (string $reason, string $key): bool => !self::isStillUngated($key), ARRAY_FILTER_USE_BOTH));
        self::assertSame([], $stale, 'ACCESS_CONTROL_ONLY entry that now has a gate or no longer exists: remove it.');
        self::assertGreaterThan(20, $checked, 'the scan found (almost) no export routes — is the discovery broken?');
        self::assertSame([], array_values($offenders), "Export/download actions without #[IsGranted]:\n" . implode("\n", $offenders));
    }

    #[Test]
    public function aGateMustNameARealRoleOrVoterAttribute(): void
    {
        $public = new class () {
            #[IsGranted('PUBLIC_ACCESS')]
            public function a(): void
            {
            }

            #[IsGranted('IS_AUTHENTICATED_FULLY')]
            public function b(): void
            {
            }

            #[IsGranted('ROLE_MANAGER')]
            public function c(): void
            {
            }

            #[IsGranted('EXPORT_RISKS')]
            public function d(): void
            {
            }
        };
        $class = new \ReflectionClass($public);

        self::assertFalse($this->hasRealGate($class->getMethod('a')));
        self::assertFalse($this->hasRealGate($class->getMethod('b')));
        self::assertTrue($this->hasRealGate($class->getMethod('c')));
        self::assertTrue($this->hasRealGate($class->getMethod('d')));
        self::assertSame(1, preg_match(self::EXPORT_LIKE, 'risk_csv /risks.csv'));
        self::assertSame(1, preg_match(self::EXPORT_LIKE, '/admin/export'));
    }

    private static function isStillUngated(string $key): bool
    {
        [$class, $method] = explode('::', $key);
        if (!class_exists($class) || !method_exists($class, $method)) {
            return false;
        }
        $reflection = new \ReflectionClass($class);
        $gated = self::gateOf($reflection) || self::gateOf($reflection->getMethod($method));

        return !$gated;
    }

    private static function gateOf(\ReflectionClass|\ReflectionMethod $subject): bool
    {
        foreach ($subject->getAttributes(IsGranted::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $granted = $attribute->newInstance();
            if (!\is_string($granted->attribute) || preg_match(self::NOT_A_GATE, $granted->attribute) !== 1) {
                return true;
            }
        }

        return false;
    }

    private function hasRealGate(\ReflectionClass|\ReflectionMethod $subject): bool
    {
        return self::gateOf($subject);
    }
}
