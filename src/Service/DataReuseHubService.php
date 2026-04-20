<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\DocumentRepository;
use App\Repository\SupplierRepository;

/**
 * Data-Reuse-Hub Service (Sprint 4 / R1).
 *
 * Liefert die „Top-Wiederverwendet"-Listen für den neuen `/reuse`-Hub.
 * Dreht die klassische Requirement→Evidence-Sicht um und beantwortet
 * die CM-Frage *"welche Artefakte tragen am meisten Last?"* auf einen
 * Blick — pro Entity-Typ mit Framework-Breite als sekundärer Metrik.
 *
 * Keine Per-Tenant-Filterung in den Queries: die zugrunde liegenden
 * Entities sind in dieser Architektur global (Controls, Requirements),
 * der Tenant-Bezug kommt über Voter/TenantContext auf Request-Ebene.
 * Der Hub ist daher ROLE_MANAGER-gegated aber nicht Tenant-scoped im
 * Reuse-Sinne — was der Realität entspricht: wiederverwendet wird ein
 * Dokument unabhängig davon, wer es gerade anschaut.
 */
final class DataReuseHubService
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly SupplierRepository $supplierRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly InverseCoverageService $inverseCoverageService,
    ) {
    }

    /**
     * Gibt die Top-N Dokumente zurück, sortiert nach Anzahl
     * Requirements in die sie als Evidence referenziert werden.
     *
     * @return list<array{
     *     document: Document,
     *     requirement_count: int,
     *     framework_count: int
     * }>
     */
    public function topDocumentsByReuse(?Tenant $tenant, int $limit = 10): array
    {
        $docs = $tenant !== null
            ? $this->documentRepository->findBy(['tenant' => $tenant])
            : $this->documentRepository->findAll();

        $scored = [];
        foreach ($docs as $doc) {
            $coverage = $this->inverseCoverageService->forDocument($doc);
            if ($coverage['total'] === 0) {
                continue;
            }
            $scored[] = [
                'document' => $doc,
                'requirement_count' => $coverage['total'],
                'framework_count' => count($coverage['frameworks']),
            ];
        }

        usort(
            $scored,
            static fn(array $a, array $b): int => $b['requirement_count'] <=> $a['requirement_count']
        );

        return array_slice($scored, 0, $limit);
    }

    /**
     * Top-N Lieferanten nach adressierenden Requirements (`forSupplier`
     * Heuristik). DORA Art. 28 + 27001 A.5.19–A.5.23 liefern i. d. R.
     * eine Grund-Abdeckung von 4–6 Requirements pro Lieferant; die
     * Reihenfolge zeigt, welche Supplier in den Mappings zusätzlich
     * adressiert werden.
     *
     * @return list<array{
     *     supplier: Supplier,
     *     requirement_count: int,
     *     framework_count: int
     * }>
     */
    public function topSuppliersByReuse(?Tenant $tenant, int $limit = 10): array
    {
        $suppliers = $tenant !== null
            ? $this->supplierRepository->findBy(['tenant' => $tenant])
            : $this->supplierRepository->findAll();

        $scored = [];
        foreach ($suppliers as $supplier) {
            $coverage = $this->inverseCoverageService->forSupplier($supplier);
            if ($coverage['total'] === 0) {
                continue;
            }
            $scored[] = [
                'supplier' => $supplier,
                'requirement_count' => $coverage['total'],
                'framework_count' => count($coverage['frameworks']),
            ];
        }

        usort(
            $scored,
            static fn(array $a, array $b): int => $b['requirement_count'] <=> $a['requirement_count']
        );

        return array_slice($scored, 0, $limit);
    }

    /**
     * Aggregierte Portfolio-Statistik für die Hub-Landing:
     *   - Gesamt-Anzahl Dokumente (tenant-scoped)
     *   - Davon in ≥ 1 Requirement referenziert
     *   - Gesamt-Anzahl Lieferanten
     *   - Davon durch ≥ 1 Requirement adressiert
     *
     * @return array{
     *     documents_total: int, documents_reused: int,
     *     suppliers_total: int, suppliers_reused: int,
     *     requirements_total: int
     * }
     */
    public function portfolioStats(?Tenant $tenant): array
    {
        $docs = $tenant !== null
            ? $this->documentRepository->findBy(['tenant' => $tenant])
            : $this->documentRepository->findAll();
        $suppliers = $tenant !== null
            ? $this->supplierRepository->findBy(['tenant' => $tenant])
            : $this->supplierRepository->findAll();

        $docsReused = 0;
        foreach ($docs as $d) {
            if ($this->inverseCoverageService->forDocument($d)['total'] > 0) {
                $docsReused++;
            }
        }
        $suppliersReused = 0;
        foreach ($suppliers as $s) {
            if ($this->inverseCoverageService->forSupplier($s)['total'] > 0) {
                $suppliersReused++;
            }
        }

        return [
            'documents_total' => count($docs),
            'documents_reused' => $docsReused,
            'suppliers_total' => count($suppliers),
            'suppliers_reused' => $suppliersReused,
            'requirements_total' => count($this->requirementRepository->findAll()),
        ];
    }
}
