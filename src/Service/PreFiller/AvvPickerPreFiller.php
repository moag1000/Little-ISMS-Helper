<?php

declare(strict_types=1);

namespace App\Service\PreFiller;

use App\Entity\ProcessingActivity;
use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Repository\SupplierRepository;

/**
 * AVV-Picker Pre-Filler.
 *
 * Suggests Supplier candidates for the AVV (Auftragsverarbeitungs-
 * Vertrag) FK relation on a ProcessingActivity when the rule
 * {@see App\AlvaHint\Rule\ProcessingActivity\InvolvesProcessorsWithoutAvvRule}
 * has fired.
 *
 * Heuristic source-of-candidates:
 *   1. If the PA carries a legacy `processors` JSON blob, match each
 *      processor's "name" field against Supplier.name (case-insensitive).
 *      Recovers the AVV link when migrating from free-text to FK.
 *   2. Suppliers in the same tenant flagged as `criticality = high`
 *      OR known to handle PII — best-effort fallback list.
 *
 * Tenant isolation is enforced by SupplierRepository::findByTenant().
 */
final readonly class AvvPickerPreFiller
{
    public function __construct(
        private SupplierRepository $supplierRepository,
        private ?\Doctrine\ORM\EntityManagerInterface $entityManager = null,
        private ?\App\Service\AuditLogger $auditLogger = null,
    ) {
    }

    /**
     * Save the picked processors (Art. 28 GDPR) — suppliers of $tenant only —
     * and audit-log the change: a collection change is no field change, the
     * Doctrine audit subscriber does not see it (Art. 30 trail).
     *
     * @param list<int> $supplierIds
     */
    public function assign(ProcessingActivity $activity, array $supplierIds, Tenant $tenant): void
    {
        $ids = static fn (): array => array_values(array_map(static fn (Supplier $s): ?int => $s->getId(), $activity->getProcessorSuppliers()->toArray()));
        $before = $ids();
        foreach ($activity->getProcessorSuppliers() as $existing) {
            $activity->removeProcessorSupplier($existing);
        }
        foreach ($supplierIds as $id) {
            $supplier = $this->supplierRepository->find($id);
            if ($supplier instanceof Supplier && $supplier->getTenant() === $tenant) {
                $activity->addProcessorSupplier($supplier);
            }
        }
        $this->entityManager?->flush();
        $this->auditLogger?->logUpdate('ProcessingActivity', $activity->getId(), ['processorSuppliers' => $before], ['processorSuppliers' => $ids()], 'AVV processors changed');
    }

    /**
     * @return list<Supplier> Candidate suppliers ordered: name-matches first, then rest.
     */
    public function candidatesFor(ProcessingActivity $activity, Tenant $tenant): array
    {
        $allSuppliers = $this->supplierRepository->findBy(['tenant' => $tenant]);
        if ($allSuppliers === []) {
            return [];
        }

        $legacyNames = $this->extractLegacyProcessorNames($activity);
        if ($legacyNames === []) {
            return $allSuppliers;
        }

        $matches = [];
        $rest = [];
        foreach ($allSuppliers as $supplier) {
            $supplierName = strtolower((string) $supplier->getName());
            $matched = false;
            foreach ($legacyNames as $legacyName) {
                if ($supplierName !== '' && str_contains($supplierName, $legacyName)) {
                    $matched = true;
                    break;
                }
            }
            if ($matched) {
                $matches[] = $supplier;
            } else {
                $rest[] = $supplier;
            }
        }

        return array_merge($matches, $rest);
    }

    /**
     * @return list<string> lower-cased name needles from the legacy JSON blob
     */
    private function extractLegacyProcessorNames(ProcessingActivity $activity): array
    {
        $processors = $activity->getProcessors() ?? [];
        $names = [];
        foreach ($processors as $proc) {
            if (!is_array($proc)) {
                continue;
            }
            $name = isset($proc['name']) && is_string($proc['name']) ? trim($proc['name']) : '';
            if ($name === '') {
                continue;
            }
            $names[] = strtolower($name);
        }
        return $names;
    }
}
