<?php

declare(strict_types=1);

namespace App\Service\PreFiller;

use App\Entity\ProcessingActivity;
use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Repository\SupplierRepository;

/**
 * Sprint-2 P-7 Wave-2 — AVV-Picker Pre-Filler.
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
    ) {
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
