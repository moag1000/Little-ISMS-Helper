<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard;

use App\Entity\ComplianceRequirement;
use App\Service\Catalog\FrameworkCode;
use App\Service\Compliance\Fulfillment\RecordCheckRule;
use App\Service\Compliance\FrameworkLoaderRegistry;
use App\Service\ComplianceWizardService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every compliance-wizard data check that names a clause or article answers
 * at least one requirement of the catalogue the framework loader actually
 * loads — otherwise its records feed no fulfilment and nobody notices
 * (NIS-2 Art. 23: the checks said "23(4)(a)", the catalogues "NIS2-23.1" and
 * "Art.23.2"). Matching is {@see RecordCheckRule::matches()}, the rule the
 * fulfilment derivation uses. One-level references ("7", BSI group "GC") and
 * Annex-A themes are themes, not a requirement, and are not checked.
 *
 * Loads each catalogue in a transaction that is rolled back.
 */
final class WizardReferencesHitCatalogueTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    #[Test]
    public function everyReferencedDataCheckHitsARequirementOfTheLoadedCatalogue(): void
    {
        $container = static::getContainer();
        $wizards = $container->get(ComplianceWizardService::class)->getWizardDefinitions();
        $loaders = $container->get(FrameworkLoaderRegistry::class);

        $misses = [];
        $checked = 0;
        foreach ($wizards as $wizardKey => $wizard) {
            $code = (string) ($wizard['code'] ?? '');
            $code = FrameworkCode::canonicalize($code) ?? $code;
            $checks = $this->specificChecks((string) $wizardKey, (array) ($wizard['categories'] ?? []));
            if ($checks === []) {
                continue;
            }
            self::assertTrue($loaders->has($code), "wizard $wizardKey names clauses, but no loader is registered for $code");
            $ids = $this->requirementIds($code);
            if ($ids === []) {
                $loaders->load($code);
                $this->em->clear();
                $ids = $this->requirementIds($code);
            }
            self::assertNotSame([], $ids, "loader for $code loads no requirement");

            foreach ($checks as $key => $refs) {
                $checked++;
                if (!$this->hitsOne($refs, $code, $ids)) {
                    $misses[] = sprintf('%s → %s (%s)', $key, implode(', ', $refs), $code);
                }
            }
        }

        self::assertGreaterThan(50, $checked, 'ISO 27001, NIS-2 and DORA alone have more referenced checks');
        self::assertSame([], $misses, "Wizard checks whose clause/article matches no requirement of the loaded catalogue.\n"
            . "Fix the reference, or name the catalogue rows in the check's `requirements`:\n" . implode("\n", $misses));
    }

    /**
     * A check type the dispatcher does not know runs as "no result" and the
     * check silently counts as manual (BSI C5:2026 `incident_coverage`).
     */
    #[Test]
    public function everyCheckTypeHasAHandler(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Service/ComplianceWizardService.php');
        self::assertSame(1, preg_match('/function dispatchCheck\(.*?match \(.*?\{(.*?)\n\s*\};/s', $source, $match));
        preg_match_all("/'([a-z_0-9]+)'\s*(?:,|=>)/", $match[1], $types);
        $handled = array_flip($types[1]);

        $unknown = [];
        foreach (static::getContainer()->get(ComplianceWizardService::class)->getWizardDefinitions() as $wizardKey => $wizard) {
            foreach ((array) ($wizard['categories'] ?? []) as $categoryKey => $category) {
                foreach ((array) ($category['checks'] ?? []) as $checkKey => $check) {
                    $type = is_array($check) ? (string) ($check['type'] ?? 'manual') : 'manual';
                    if ($type !== 'manual' && !isset($handled[$type])) {
                        $unknown[] = "$wizardKey.$categoryKey.$checkKey: $type";
                    }
                }
            }
        }

        self::assertSame([], $unknown, 'Wizard check types without a handler in ComplianceWizardService::dispatchCheck()');
    }

    /**
     * Data checks with at least one reference naming a requirement (two or more levels, or `requirements`).
     *
     * @param array<string, mixed> $categories
     * @return array<string, list<string>> check key → references
     */
    private function specificChecks(string $wizardKey, array $categories): array
    {
        $checks = [];
        foreach ($categories as $categoryKey => $category) {
            $categoryRef = (string) ($category['clause'] ?? $category['article'] ?? '');
            foreach ((array) ($category['checks'] ?? []) as $checkKey => $check) {
                if (!is_array($check) || ($check['type'] ?? 'manual') === 'manual') {
                    continue;
                }
                $refs = array_values(array_filter(
                    RecordCheckRule::referencesOfCheck($check, $categoryRef),
                    static fn (string $ref): bool => str_starts_with($ref, RecordCheckRule::REQUIREMENT_ID_PREFIX) || str_contains($ref, '.'),
                ));
                if ($refs !== []) {
                    $checks[$wizardKey . '.' . $categoryKey . '.' . $checkKey] = $refs;
                }
            }
        }

        return $checks;
    }

    /** @return list<string> */
    private function requirementIds(string $code): array
    {
        return array_map('strval', $this->em->createQueryBuilder()
            ->select('r.requirementId')
            ->from(ComplianceRequirement::class, 'r')
            ->join('r.framework', 'f')
            ->where('f.code = :code')
            ->setParameter('code', $code)
            ->getQuery()
            ->getSingleColumnResult());
    }

    /**
     * @param list<string> $refs
     * @param list<string> $ids
     */
    private function hitsOne(array $refs, string $code, array $ids): bool
    {
        foreach ($refs as $ref) {
            foreach ($ids as $id) {
                if (RecordCheckRule::matches($ref, $code, $id)) {
                    return true;
                }
            }
        }

        return false;
    }
}
