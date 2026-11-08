<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard;

use App\Service\ComplianceWizardService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every `record_completeness` wizard check names an entity and fields that
 * exist: the check builds its query from them, a typo would answer 500 on
 * the wizard page and stop the nightly fulfilment refresh.
 */
final class RecordCompletenessConfigTest extends KernelTestCase
{
    #[Test]
    public function everyRecordCompletenessCheckNamesMappedFields(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $wizards = $container->get(ComplianceWizardService::class);
        $entityManager = $container->get(EntityManagerInterface::class);

        $seen = 0;
        foreach ($wizards->getWizardDefinitions() as $wizardKey => $wizard) {
            foreach ((array) ($wizard['categories'] ?? []) as $categoryKey => $category) {
                foreach ((array) ($category['checks'] ?? []) as $checkKey => $check) {
                    if (!is_array($check) || ($check['type'] ?? null) !== 'record_completeness') {
                        continue;
                    }
                    $seen++;
                    $where = sprintf('%s.%s.%s', $wizardKey, $categoryKey, $checkKey);
                    self::assertTrue(class_exists((string) $check['entity']), $where . ': entity');
                    $metadata = $entityManager->getClassMetadata((string) $check['entity']);
                    self::assertTrue($metadata->hasAssociation('tenant'), $where . ': tenant-owned');
                    $fields = [...$check['fields'], ...array_keys((array) ($check['where'] ?? []))];
                    if (isset($check['since']['field'])) {
                        $fields[] = $check['since']['field'];
                    }
                    foreach ($fields as $field) {
                        self::assertTrue($metadata->hasField($field), sprintf('%s: field %s', $where, $field));
                    }
                }
            }
        }

        self::assertGreaterThanOrEqual(4, $seen, 'DORA Art. 14, 17, 18 and 30 read record fields');
    }
}
