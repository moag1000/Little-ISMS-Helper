<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Controller\DeploymentWizardController;
use App\Controller\Setup\ExistingFrameworksController;
use App\Security\SetupAccessChecker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * S7 standing rules, checked over the source so a new code path cannot reopen them:
 *
 * 1. Uploaded evidence (documents, versions, training materials) is never written to or
 *    linked from public/ — only App\Service\Upload\ProtectedUploads knows the folders.
 * 2. Every controller with a /setup route carries the class-level setup gate
 *    (super admin only once the setup is complete), not a per-action guard call.
 */
final class EvidenceOutsideWebRootTest extends TestCase
{
    private const string PUBLIC_EVIDENCE = '#public/uploads/(documents|document_versions|training-materials)'
        . '|asset\(\s*[\'"]/?uploads/(documents|training-materials)#';

    #[Test]
    public function noCodeOrTemplateUsesThePublicEvidenceFolders(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        foreach (['src', 'templates'] as $dir) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($files as $file) {
                $path = (string) $file->getPathname();
                if (!preg_match('/\.(php|twig)$/', $path) || str_ends_with($path, 'Service/Upload/ProtectedUploads.php')) {
                    continue;
                }
                if (preg_match(self::PUBLIC_EVIDENCE, (string) file_get_contents($path)) === 1) {
                    $offenders[] = substr($path, strlen($root) + 1);
                }
            }
        }

        self::assertSame([], $offenders, 'Use ProtectedUploads::directory()/resolve() — evidence stays outside the web root.');
    }

    #[Test]
    public function everySetupControllerCarriesTheClassLevelGate(): void
    {
        $root = dirname(__DIR__, 2);
        $found = [];
        $ungated = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/src/Controller', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            $source = (string) file_get_contents((string) $file->getPathname());
            if (!preg_match("#Route\\(\\s*'/setup#", $source)
                || !preg_match('/^namespace\s+([^;]+);.*?^(?:final\s+)?class\s+(\w+)/ms', $source, $m)) {
                continue;
            }
            $class = new \ReflectionClass($m[1] . '\\' . $m[2]);
            $found[] = $class->getName();
            $gates = array_map(
                static fn (\ReflectionAttribute $a): mixed => $a->newInstance()->attribute,
                $class->getAttributes(IsGranted::class),
            );
            if (!in_array(SetupAccessChecker::ACCESS, $gates, true)) {
                $ungated[] = $class->getName();
            }
        }

        self::assertContains(DeploymentWizardController::class, $found);
        self::assertContains(ExistingFrameworksController::class, $found);
        self::assertSame([], $ungated);
    }
}
