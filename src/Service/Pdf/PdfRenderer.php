<?php

declare(strict_types=1);

namespace App\Service\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The one place that turns HTML into a PDF (S25). Every PDF of the tool is rendered
 * here, so the Dompdf settings that keep record text harmless hold for all of them:
 *
 *  - no remote fetches (`isRemoteEnabled` off, only `data:` and `file:` URIs) — a URL in a
 *    report style, a footer or a restored branding field never reaches the network (SSRF);
 *  - no inline PHP (`<script type="text/php">`) and no PDF JavaScript;
 *  - local files only below `public/` (chroot), where logos live — never `.env.local`,
 *    `var/` or uploaded evidence.
 *
 * Callers may only pick the layout options in ALLOWED_EXTRA; anything else throws.
 * `tests/Security/DompdfOptionsContractTest` fails on any `new Dompdf` / `new Options`
 * outside this class.
 */
final class PdfRenderer
{
    /** Options a caller may set; the security options above are fixed. */
    public const array ALLOWED_EXTRA = ['isHtml5ParserEnabled', 'isFontSubsettingEnabled', 'dpi', 'fontHeightRatio'];

    private readonly string $publicDir;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        ?string $projectDir = null,
    ) {
        $this->publicDir = rtrim($projectDir ?? dirname(__DIR__, 3), '/') . '/public';
    }

    /**
     * @param array<string, bool|int|float|string> $extra layout options from ALLOWED_EXTRA
     */
    public function render(
        string $html,
        string $paper = 'A4',
        string $orientation = 'portrait',
        string $defaultFont = 'DejaVu Sans',
        array $extra = [],
    ): string {
        $dompdf = new Dompdf($this->options($defaultFont, $extra));
        // Relative image paths (uploads/tenants/logo.png) resolve below public/.
        $dompdf->setProtocol('file://');
        $dompdf->setBaseHost('');
        $dompdf->setBasePath($this->publicDir . '/');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper($paper, $orientation);
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * @param array<string, bool|int|float|string> $extra
     */
    public function options(string $defaultFont = 'DejaVu Sans', array $extra = []): Options
    {
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        foreach ($extra as $key => $value) {
            if (!in_array($key, self::ALLOWED_EXTRA, true)) {
                // @intentional-assertion: a caller widening the PDF sandbox is a programming error
                throw new \InvalidArgumentException(sprintf('PDF option "%s" may not be set by a caller.', $key));
            }
            $options->set($key, $value);
        }
        $options->set('defaultFont', $defaultFont);
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setAllowedProtocols(['data://', 'file://']);
        $options->setChroot([$this->publicDir]);

        return $options;
    }
}
