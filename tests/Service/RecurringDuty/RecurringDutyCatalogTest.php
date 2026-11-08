<?php

declare(strict_types=1);

namespace App\Tests\Service\RecurringDuty;

use App\Entity\Tenant;
use App\Service\RecurringDuty\RecurringDutyCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class RecurringDutyCatalogTest extends TestCase
{
    #[Test]
    public function everyDutyHasTitleBodyAndRoleLabelsInBothLanguages(): void
    {
        foreach (['de', 'en'] as $locale) {
            $messages = Yaml::parseFile(dirname(__DIR__, 3) . "/translations/recurring_duty.$locale.yaml")['recurring_duty'];
            foreach (RecurringDutyCatalog::DUTIES as $key => $duty) {
                self::assertNotEmpty($messages['duty'][$key]['title'] ?? null, "$key.title ($locale)");
                self::assertNotEmpty($messages['duty'][$key]['body'] ?? null, "$key.body ($locale)");
                foreach ($duty['roles'] as $role) {
                    self::assertArrayHasKey(strtolower($role), $messages['role'], "role $role ($locale)");
                }
            }
        }
    }

    #[Test]
    public function rolesExistInTheSecurityConfiguration(): void
    {
        $security = (string) file_get_contents(dirname(__DIR__, 3) . '/config/packages/security.yaml');
        foreach (RecurringDutyCatalog::DUTIES as $key => $duty) {
            foreach ($duty['roles'] as $role) {
                self::assertStringContainsString($role, $security, "$key uses unknown role $role");
            }
        }
    }

    #[Test]
    public function nis2AndDoraDutiesNeedTheOrganisationToBeInScope(): void
    {
        $tenant = new Tenant();
        self::assertTrue(RecurringDutyCatalog::inScope('bia_review', $tenant));
        self::assertFalse(RecurringDutyCatalog::inScope('nis2_measures_review', $tenant));
        self::assertFalse(RecurringDutyCatalog::inScope('dora_exit_plans', $tenant));
        self::assertFalse(RecurringDutyCatalog::inScope('unknown', $tenant));

        $tenant->setNis2Classification(Tenant::NIS2_IMPORTANT);
        $tenant->setDoraEntityCategory(Tenant::DORA_FINANCIAL_ENTITY);
        self::assertTrue(RecurringDutyCatalog::inScope('nis2_measures_review', $tenant));
        self::assertTrue(RecurringDutyCatalog::inScope('dora_exit_plans', $tenant));
    }
}
