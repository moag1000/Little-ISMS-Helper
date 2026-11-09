<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Service\Pdf\PdfRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * S25: every PDF goes through PdfRenderer, whose Dompdf settings keep record text harmless
 * (no network, no inline PHP, no PDF JavaScript, local files only below public/). Restored
 * branding header/footer HTML and report styles reach Dompdf unescaped — the guarantee
 * rests on these settings, so they are pinned here.
 */
final class DompdfOptionsContractTest extends TestCase
{
    #[Test]
    public function noDompdfOutsideTheRenderer(): void
    {
        $root = \dirname(__DIR__, 2);
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), \strlen($root) + 1);
            if ($relative === 'src/Service/Pdf/PdfRenderer.php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (preg_match('/\bDompdf\\\\(Dompdf|Options)\b|new\s+\\\\?Dompdf\b/', $code) === 1) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, 'Render PDFs through App\Service\Pdf\PdfRenderer, not Dompdf directly:');
    }

    #[Test]
    public function rendererFixesTheSandbox(): void
    {
        $project = \dirname(__DIR__, 2);
        $options = (new PdfRenderer($project))->options('Helvetica', ['isFontSubsettingEnabled' => false]);

        self::assertFalse($options->getIsRemoteEnabled());
        self::assertFalse($options->getIsPhpEnabled());
        self::assertFalse($options->getIsJavascriptEnabled());
        self::assertSame(['data://', 'file://'], array_keys($options->getAllowedProtocols()));
        self::assertSame([realpath($project . '/public')], array_map('realpath', $options->getChroot()));

        self::assertTrue($options->validateLocalUri($project . '/public/index.php')[0], 'files below public/ (logos) stay readable');
        foreach (['/.env', '/composer.json', '/config/packages/security.yaml'] as $secret) {
            if (is_file($project . $secret)) {
                self::assertFalse($options->validateLocalUri($project . $secret)[0], $secret . ' must stay outside the PDF chroot');
            }
        }
        self::assertFalse($options->validateLocalUri('/etc/passwd')[0]);
    }

    #[Test]
    public function callerCannotWidenTheSandbox(): void
    {
        foreach (['isRemoteEnabled', 'isPhpEnabled', 'isJavascriptEnabled', 'chroot', 'allowedProtocols'] as $key) {
            try {
                (new PdfRenderer())->options('Helvetica', [$key => true]);
                self::fail($key . ' must not be settable by a caller');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function inlinePhpAndRemoteImagesAreInert(): void
    {
        $marker = sys_get_temp_dir() . '/dompdf-s25-' . bin2hex(random_bytes(4));
        $html = '<html><body><p>x</p>'
            . '<script type="text/php">file_put_contents(' . var_export($marker, true) . ', "1");</script>'
            . '<img src="http://127.0.0.1:1/probe.png"></body></html>';

        $pdf = (new PdfRenderer())->render($html);

        self::assertStringStartsWith('%PDF', $pdf);
        self::assertFileDoesNotExist($marker);
    }
}
