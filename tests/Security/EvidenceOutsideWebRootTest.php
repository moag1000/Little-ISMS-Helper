<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Controller\DeploymentWizardController;
use App\Controller\Setup\ExistingFrameworksController;
use App\Security\SetupAccessChecker;
use App\Tests\Support\PhpSourceScanner;
use PhpParser\Node;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * S7 standing rules, checked over the source so a new code path cannot reopen them:
 *
 * 1. Uploaded evidence (documents, versions, training materials) is never written to or
 *    linked from public/ — only App\Service\Upload\ProtectedUploads knows the folders. PHP is parsed
 *    (string concatenation is folded), Twig, YAML and XML are scanned without comments, config/ included.
 * 2. Every controller with a /setup route (read from the router, not from a source regex) carries the class-level setup gate
 *    (super admin only once the setup is complete), not a per-action guard call.
 */
final class EvidenceOutsideWebRootTest extends KernelTestCase
{
    private const string FOLDERS = 'documents|document_versions|training-materials';

    /** A path below public/ — in code, templates and config. */
    private const string PUBLIC_EVIDENCE = '#public/uploads/(' . self::FOLDERS . ')\b#';

    /** `asset('uploads/…')` in Twig and the Packages::getUrl()/asset() equivalents in PHP. */
    private const string ASSET_EVIDENCE = '#^/?uploads/(' . self::FOLDERS . ')\b#';

    private const string TWIG_ASSET_EVIDENCE = '#\b(?:asset|absolute_url)\(\s*[\'"]/?uploads/(' . self::FOLDERS . ')\b#';

    #[Test]
    public function noCodeOrTemplateUsesThePublicEvidenceFolders(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        foreach (['src', 'templates', 'config'] as $dir) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($files as $file) {
                $path = (string) $file->getPathname();
                if (str_ends_with($path, 'Service/Upload/ProtectedUploads.php')) {
                    continue;
                }
                $source = (string) file_get_contents($path);
                $hit = match (strtolower($file->getExtension())) {
                    'php' => self::phpUsesPublicEvidence($source, $path),
                    'twig' => self::textUsesPublicEvidence(PhpSourceScanner::twigWithoutComments($source)),
                    'yaml', 'yml', 'dist' => self::textUsesPublicEvidence(PhpSourceScanner::yamlWithoutComments($source)),
                    'xml' => self::textUsesPublicEvidence((string) preg_replace('/<!--.*?-->/s', '', $source)),
                    default => false,
                };
                if ($hit) {
                    $offenders[] = substr($path, strlen($root) + 1);
                }
            }
        }

        self::assertSame([], $offenders, 'Use ProtectedUploads::directory()/resolve() — evidence stays outside the web root.');
    }

    #[Test]
    public function theScanSeesEveryWayToPointAtThePublicEvidenceFolders(): void
    {
        $php = [
            'plain path' => '$d = $root . "/public/uploads/documents";',
            'concatenation' => '$d = $root . "/public/" . "uploads/documents";',
            'concatenation in the middle' => '$d = $root . \'/public\' . \'/uploads/\' . \'document_versions\';',
            'asset()' => '$u = asset("uploads/document_versions/x.pdf");',
            'packages' => '$u = $this->packages->getUrl("/uploads/training-materials/x.pdf");',
            'config placeholder' => '$d = "%kernel.project_dir%/public/uploads/training-materials";',
        ];
        foreach ($php as $label => $statement) {
            self::assertTrue(self::phpUsesPublicEvidence('<?php ' . $statement, $label), $label . ' must be flagged');
        }
        self::assertFalse(self::phpUsesPublicEvidence('<?php // public/uploads/documents' . "\n" . '$p = "/uploads/documents/" . $f; $v = "var/uploads/documents";', 'ok'), 'stored values and var/ pass');

        foreach ([
            "{{ asset('uploads/document_versions/x.pdf') }}",
            '{{ asset("/uploads/documents/x.pdf") }}',
            '<img src="/public/uploads/training-materials/a.png">',
            'path: "%kernel.project_dir%/public/uploads/documents"',
        ] as $text) {
            self::assertTrue(self::textUsesPublicEvidence($text), $text . ' must be flagged');
        }
        self::assertFalse(self::textUsesPublicEvidence(PhpSourceScanner::twigWithoutComments('{# asset("uploads/documents/x") #}')));
    }

    #[Test]
    public function everySetupControllerCarriesTheClassLevelGate(): void
    {
        $found = [];
        $ungated = [];
        foreach (self::getContainer()->get('router')->getRouteCollection() as $route) {
            // /setup, /de/setup/…, /{_locale}/setup — the setup wizard under any prefix
            if (preg_match('#^(?:/(?:\{_locale\}|[a-z]{2}))?/setup(?:/|$)#', $route->getPath()) !== 1) {
                continue;
            }
            $controller = (string) $route->getDefault('_controller');
            $class = explode('::', $controller)[0];
            if ($class === '' || !class_exists($class)) {
                continue;
            }
            $class = new \ReflectionClass($class);
            $found[$class->getName()] = true;
            $gates = array_map(
                static fn (\ReflectionAttribute $a): mixed => $a->newInstance()->attribute,
                $class->getAttributes(IsGranted::class),
            );
            if (!in_array(SetupAccessChecker::ACCESS, $gates)) {
                $ungated[$class->getName()] = $class->getName();
            }
        }

        self::assertArrayHasKey(DeploymentWizardController::class, $found);
        self::assertArrayHasKey(ExistingFrameworksController::class, $found);
        self::assertSame([], array_values($ungated));
    }

    private static function phpUsesPublicEvidence(string $source, string $label): bool
    {
        $ast = PhpSourceScanner::parseCode($source, $label);
        foreach (PhpSourceScanner::stringLiterals($ast) as $string) {
            if (preg_match(self::PUBLIC_EVIDENCE, $string) === 1) {
                return true;
            }
        }
        $calls = [
            ...PhpSourceScanner::find($ast, Node\Expr\FuncCall::class),
            ...PhpSourceScanner::find($ast, Node\Expr\MethodCall::class),
        ];
        foreach ($calls as $call) {
            $name = $call->name instanceof Node\Name ? $call->name->getLast() : ($call->name instanceof Node\Identifier ? $call->name->toString() : '');
            if (!in_array(strtolower($name), ['asset', 'geturl']) || !isset($call->args[0]) || !$call->args[0] instanceof Node\Arg) {
                continue;
            }
            $argument = PhpSourceScanner::constantString($call->args[0]->value);
            if ($argument !== null && preg_match(self::ASSET_EVIDENCE, $argument) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function textUsesPublicEvidence(string $text): bool
    {
        return preg_match(self::PUBLIC_EVIDENCE, $text) === 1 || preg_match(self::TWIG_ASSET_EVIDENCE, $text) === 1;
    }
}
