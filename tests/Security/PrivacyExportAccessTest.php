<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Controller\DPIAController;
use App\Controller\ProcessingActivityController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * All privacy exports (VVT and DPIA) need ROLE_DPO or ROLE_MANAGER, not just
 * a login. ROLE_ADMIN reaches both through the role hierarchy.
 */
final class PrivacyExportAccessTest extends TestCase
{
    private const string EXPECTED = 'is_granted("ROLE_DPO") or is_granted("ROLE_MANAGER")';

    /** @return iterable<string, array{class-string, string}> */
    public static function exportActions(): iterable
    {
        foreach (['exportPdf', 'exportCsvDispatch', 'exportCsvDownload', 'exportCsv', 'bulkExport'] as $m) {
            yield 'vvt ' . $m => [ProcessingActivityController::class, $m];
        }
        foreach (['exportPdf', 'bulkExport'] as $m) {
            yield 'dpia ' . $m => [DPIAController::class, $m];
        }
    }

    /** @param class-string $class */
    #[Test]
    #[DataProvider('exportActions')]
    public function exportActionRequiresDpoOrManager(string $class, string $method): void
    {
        $expressions = [];
        $plainRoles = [];
        foreach ((new \ReflectionMethod($class, $method))->getAttributes(IsGranted::class) as $attribute) {
            $attr = $attribute->newInstance();
            if ($attr->attribute instanceof Expression) {
                $expressions[] = (string) $attr->attribute;
            } else {
                $plainRoles[] = $attr->attribute;
            }
        }

        self::assertContains(self::EXPECTED, $expressions, sprintf('%s::%s lacks the DPO/MANAGER expression', $class, $method));
        self::assertNotContains('ROLE_USER', $plainRoles, sprintf('%s::%s must not stay at ROLE_USER', $class, $method));
    }
}
