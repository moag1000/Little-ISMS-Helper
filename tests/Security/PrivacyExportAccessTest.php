<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Controller\Authority\VvtExportController;
use App\Controller\ConsentController;
use App\Controller\DataBreachController;
use App\Controller\DataSubjectRequestController;
use App\Controller\DPIAController;
use App\Controller\ProcessingActivityController;
use App\Controller\TransferImpactAssessmentController;
use App\Security\PrivacyExportAccess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One rule for every privacy export (W3): ROLE_DPO or ROLE_MANAGER
 * ({@see PrivacyExportAccess::RULE}) — VVT incl. the BfDI format, DPIA, data
 * breach PDF and list, data subject requests, consents. Export actions are
 * discovered from the routes of the privacy controllers, so a new one is
 * covered without a list entry. ROLE_ADMIN reaches the rule via the hierarchy.
 */
final class PrivacyExportAccessTest extends TestCase
{
    private const array PRIVACY_CONTROLLERS = [
        ProcessingActivityController::class,
        VvtExportController::class,
        DPIAController::class,
        DataBreachController::class,
        DataSubjectRequestController::class,
        ConsentController::class,
        TransferImpactAssessmentController::class,
    ];

    /** Not a register export: the Art. 15 answer to one data subject, acted on by the request's handler. */
    private const array NOT_A_REGISTER_EXPORT = ['export_personal_data'];

    #[Test]
    public function everyPrivacyExportFollowsTheOneRule(): void
    {
        $found = 0;
        $problems = [];
        foreach (self::PRIVACY_CONTROLLERS as $class) {
            $ref = new \ReflectionClass($class);
            $classRule = $this->hasRule($ref->getAttributes(IsGranted::class));
            foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(Route::class) as $routeAttr) {
                    $route = $routeAttr->newInstance();
                    $name = (string) $route->getName();
                    $path = (string) $route->getPath();
                    if (preg_match('/export|pdf|xlsx|csv/i', $name . ' ' . $path) !== 1 || in_array($name, self::NOT_A_REGISTER_EXPORT, true)) {
                        continue;
                    }
                    ++$found;
                    if (!$classRule && !$this->hasRule($method->getAttributes(IsGranted::class))) {
                        $problems[] = $class . '::' . $method->getName();
                    }
                    foreach ($method->getAttributes(IsGranted::class) as $attribute) {
                        if (in_array($attribute->newInstance()->attribute, ['ROLE_USER', 'ROLE_AUDITOR'], true)) {
                            $problems[] = $class . '::' . $method->getName() . ' opens the export to ' . $attribute->newInstance()->attribute;
                        }
                    }
                }
            }
        }

        self::assertGreaterThanOrEqual(14, $found, 'the export actions were not discovered');
        self::assertSame([], $problems, 'Privacy exports need #[IsGranted(new Expression(PrivacyExportAccess::RULE))]: ' . implode(', ', $problems));
    }

    /** @param list<\ReflectionAttribute<IsGranted>> $attributes */
    private function hasRule(array $attributes): bool
    {
        foreach ($attributes as $attribute) {
            $attr = $attribute->newInstance()->attribute;
            if ($attr instanceof Expression && (string) $attr === PrivacyExportAccess::RULE) {
                return true;
            }
        }

        return false;
    }
}
