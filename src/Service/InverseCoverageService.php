<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Asset;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Control;
use App\Entity\Document;
use App\Entity\Risk;
use App\Entity\Supplier;
use App\Repository\ComplianceRequirementRepository;

/**
 * Inverse-Coverage Service (Sprint 3 / A1).
 *
 * Dreht die Standard-Sicht *"Requirement → Evidence"* um. Der CM öffnet
 * ein Document/Asset/Supplier und sieht sofort *"wo wird das eigentlich
 * noch genutzt?"* — in **welchen Requirements** über **welche Frameworks**
 * hinweg. Evidence-Bubble-Up aus dem CM-Plan.
 *
 * Scope:
 *  - Document:  direktes M:M-Reverse-Lookup über
 *               `ComplianceRequirement.evidenceDocuments`.
 *  - Supplier:  Requirements mit `dataSourceMapping.entity = 'Supplier'`
 *               + Requirements, deren gemappte Controls Lieferanten
 *               direkt adressieren (z. B. via `mappedControls` mit
 *               Supplier-Context). Reine Heuristik — für präzise
 *               Beziehung muss der CM im Zweifelsfall das Mapping
 *               manuell prüfen.
 *
 * Alle Methoden liefern Requirements gruppiert nach Framework, damit
 * die UI die Reihenfolge *"N Requirements in M Frameworks"* ohne
 * Nested-Loops in Twig rendern kann.
 */
final class InverseCoverageService
{
    public function __construct(
        private readonly ComplianceRequirementRepository $requirementRepository,
    ) {
    }

    /**
     * Alle Requirements, bei denen dieses Document als Evidence hinterlegt ist.
     *
     * @return array{total: int, frameworks: array<string, array{
     *     framework: ComplianceFramework,
     *     requirements: list<ComplianceRequirement>
     * }>}
     */
    public function forDocument(Document $document): array
    {
        $qb = $this->requirementRepository->createQueryBuilder('r')
            ->innerJoin('r.evidenceDocuments', 'd')
            ->innerJoin('r.framework', 'f')
            ->andWhere('d.id = :docId')
            ->setParameter('docId', $document->getId())
            ->orderBy('f.code', 'ASC')
            ->addOrderBy('r.requirementId', 'ASC');

        return $this->groupByFramework($qb->getQuery()->getResult());
    }

    /**
     * Alle Requirements, die einen Lieferanten-Kontext haben — entweder
     * über `dataSourceMapping.entity='Supplier'` oder über gemappte
     * Controls, die diesen Lieferanten adressieren.
     *
     * @return array{total: int, frameworks: array<string, array{
     *     framework: ComplianceFramework,
     *     requirements: list<ComplianceRequirement>
     * }>}
     */
    public function forSupplier(Supplier $supplier): array
    {
        $hits = [];
        $seen = [];

        foreach ($this->requirementRepository->findAll() as $req) {
            $id = $req->getId();
            if ($id === null) {
                continue;
            }
            if ($this->requirementTouchesSupplier($req, $supplier)) {
                $seen[$id] = true;
                $hits[] = $req;
            }
        }

        return $this->groupByFramework($hits);
    }

    /**
     * @param list<ComplianceRequirement> $requirements
     * @return array{total: int, frameworks: array<string, array{
     *     framework: ComplianceFramework,
     *     requirements: list<ComplianceRequirement>
     * }>}
     */
    private function groupByFramework(array $requirements): array
    {
        $grouped = [];
        foreach ($requirements as $req) {
            $fw = $req->getFramework();
            if (!$fw instanceof ComplianceFramework) {
                continue;
            }
            $code = (string) $fw->getCode();
            if ($code === '') {
                continue;
            }
            if (!isset($grouped[$code])) {
                $grouped[$code] = [
                    'framework' => $fw,
                    'requirements' => [],
                ];
            }
            $grouped[$code]['requirements'][] = $req;
        }
        ksort($grouped);
        return [
            'total' => count($requirements),
            'frameworks' => $grouped,
        ];
    }

    private function requirementTouchesSupplier(ComplianceRequirement $req, Supplier $supplier): bool
    {
        $mapping = $req->getDataSourceMapping();
        if (is_array($mapping)) {
            if (isset($mapping['entity']) && strtolower((string) $mapping['entity']) === 'supplier') {
                return true;
            }
            // Allow category-list form: ['entities' => ['Supplier', 'Asset']]
            if (isset($mapping['entities']) && is_array($mapping['entities'])) {
                foreach ($mapping['entities'] as $entity) {
                    if (is_string($entity) && strtolower($entity) === 'supplier') {
                        return true;
                    }
                }
            }
        }

        foreach ($req->getMappedControls() as $control) {
            if ($this->controlTouchesSupplier($control, $supplier)) {
                return true;
            }
        }
        return false;
    }

    private function controlTouchesSupplier(Control $control, Supplier $supplier): bool
    {
        $category = (string) $control->getCategory();
        if ($category !== '' && stripos($category, 'supplier') !== false) {
            return true;
        }
        $controlId = (string) $control->getControlId();
        // ISO 27001:2022 supplier-relationship controls
        if (in_array($controlId, ['A.5.19', 'A.5.20', 'A.5.21', 'A.5.22', 'A.5.23'], true)) {
            return true;
        }
        return false;
    }

    /**
     * V3 B6 / EF-4: Inverse-Coverage für ein Control.
     *
     * Liefert alle Requirements (in welchen Frameworks), die dieses Control
     * als gemapptes Control referenzieren — d.h. wenn dieses Control geändert
     * oder deaktiviert wird, brechen diese Frameworks an dieser Stelle.
     *
     * @return array{total: int, frameworks: array<string, array{framework: ComplianceFramework, requirements: list<ComplianceRequirement>}>}
     */
    public function forControl(Control $control): array
    {
        $qb = $this->requirementRepository->createQueryBuilder('r')
            ->innerJoin('r.mappedControls', 'c')
            ->innerJoin('r.framework', 'f')
            ->andWhere('c.id = :ctlId')
            ->setParameter('ctlId', $control->getId())
            ->orderBy('f.code', 'ASC')
            ->addOrderBy('r.requirementId', 'ASC');

        return $this->groupByFramework($qb->getQuery()->getResult());
    }

    /**
     * V3 B6 / EF-4: Inverse-Coverage für ein Asset.
     *
     * Liefert Requirements, die dieses Asset entweder direkt via Asset-Bezug
     * (dataSourceMapping.entity='Asset' / .entities-Liste enthält 'Asset')
     * adressieren, oder indirekt über Controls die dieses Asset als
     * relevantes Asset referenzieren (z.B. ISO 27001 A.5.9 Inventar-Controls).
     *
     * @return array{total: int, frameworks: array<string, array{framework: ComplianceFramework, requirements: list<ComplianceRequirement>}>}
     */
    public function forAsset(Asset $asset): array
    {
        $hits = [];
        $seen = [];
        foreach ($this->requirementRepository->findAll() as $req) {
            $id = $req->getId();
            if ($id === null || isset($seen[$id])) {
                continue;
            }
            if ($this->requirementTouchesAsset($req, $asset)) {
                $seen[$id] = true;
                $hits[] = $req;
            }
        }
        return $this->groupByFramework($hits);
    }

    /**
     * V3 B6 / EF-4: Inverse-Coverage für ein Risk.
     *
     * Liefert Requirements die dieses Risk via mappedRisks (falls vorhanden) oder
     * via mappedControls die das Risk in ihrer Relevanz haben referenzieren.
     * Heuristik: Risk → linkedAssets / linkedControls → Requirements.
     *
     * @return array{total: int, frameworks: array<string, array{framework: ComplianceFramework, requirements: list<ComplianceRequirement>}>}
     */
    public function forRisk(Risk $risk): array
    {
        $hits = [];
        $seen = [];

        // Approach: collect all controls related to this risk (via Risk.mitigatingControls
        // or Asset-derived). Then collect requirements mapping to those controls.
        $relatedControlIds = [];
        if (method_exists($risk, 'getMitigatingControls')) {
            foreach ($risk->getMitigatingControls() as $ctl) {
                if ($ctl instanceof Control && $ctl->getId() !== null) {
                    $relatedControlIds[$ctl->getId()] = true;
                }
            }
        }
        if (method_exists($risk, 'getControls')) {
            foreach ($risk->getControls() as $ctl) {
                if ($ctl instanceof Control && $ctl->getId() !== null) {
                    $relatedControlIds[$ctl->getId()] = true;
                }
            }
        }

        if ($relatedControlIds !== []) {
            $qb = $this->requirementRepository->createQueryBuilder('r')
                ->innerJoin('r.mappedControls', 'c')
                ->andWhere('c.id IN (:ids)')
                ->setParameter('ids', array_keys($relatedControlIds));
            foreach ($qb->getQuery()->getResult() as $req) {
                if ($req instanceof ComplianceRequirement && $req->getId() !== null) {
                    $seen[$req->getId()] = true;
                    $hits[] = $req;
                }
            }
        }

        // Also include Risk-tagged requirements via dataSourceMapping
        foreach ($this->requirementRepository->findAll() as $req) {
            $id = $req->getId();
            if ($id === null || isset($seen[$id])) {
                continue;
            }
            $mapping = $req->getDataSourceMapping();
            if (!is_array($mapping)) {
                continue;
            }
            $entityHit = false;
            if (isset($mapping['entity']) && strtolower((string) $mapping['entity']) === 'risk') {
                $entityHit = true;
            }
            if (!$entityHit && isset($mapping['entities']) && is_array($mapping['entities'])) {
                foreach ($mapping['entities'] as $e) {
                    if (is_string($e) && strtolower($e) === 'risk') {
                        $entityHit = true;
                        break;
                    }
                }
            }
            if ($entityHit) {
                $seen[$id] = true;
                $hits[] = $req;
            }
        }

        return $this->groupByFramework($hits);
    }

    private function requirementTouchesAsset(ComplianceRequirement $req, Asset $asset): bool
    {
        $mapping = $req->getDataSourceMapping();
        if (is_array($mapping)) {
            if (isset($mapping['entity']) && strtolower((string) $mapping['entity']) === 'asset') {
                return true;
            }
            if (isset($mapping['entities']) && is_array($mapping['entities'])) {
                foreach ($mapping['entities'] as $entity) {
                    if (is_string($entity) && strtolower($entity) === 'asset') {
                        return true;
                    }
                }
            }
        }
        foreach ($req->getMappedControls() as $control) {
            if ($this->controlTouchesAsset($control, $asset)) {
                return true;
            }
        }
        return false;
    }

    private function controlTouchesAsset(Control $control, Asset $asset): bool
    {
        $controlId = (string) $control->getControlId();
        // ISO 27001:2022 asset-management controls
        if (in_array($controlId, ['A.5.9', 'A.5.10', 'A.5.11', 'A.5.12', 'A.5.13', 'A.5.14', 'A.7.10', 'A.8.1', 'A.8.10'], true)) {
            return true;
        }
        $category = (string) $control->getCategory();
        if ($category !== '' && stripos($category, 'asset') !== false) {
            return true;
        }
        return false;
    }
}
