<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\CustomReport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * S11: report styles land in the PDF's <style> block (dompdf follows url(…) there),
 * so only known keys with their own value shape survive.
 */
final class ReportStylesTest extends TestCase
{
    #[Test]
    public function aColourThatIsNoHexColourFallsBackToTheDefault(): void
    {
        $report = (new CustomReport())->setStyles([
            'primaryColor' => 'red; background: url(http://169.254.169.254/latest/meta-data)',
            'pageOrientation' => 'landscape</style><script>',
            'injected' => 'x',
        ]);

        self::assertSame('#0d6efd', $report->getStyles()['primaryColor']);
        self::assertSame('portrait', $report->getStyles()['pageOrientation']);
        self::assertArrayNotHasKey('injected', $report->getStyles());
    }

    #[Test]
    public function aHexColourIsKept(): void
    {
        $report = (new CustomReport())->setStyles(['primaryColor' => '#1A2b3C', 'pageOrientation' => 'landscape']);

        self::assertSame('#1A2b3C', $report->getStyles()['primaryColor']);
        self::assertSame('landscape', $report->getStyles()['pageOrientation']);
    }
}
