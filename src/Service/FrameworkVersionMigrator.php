<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Framework-Version-Migration-Assistent (Sprint 2 / B6).
 *
 * Löst das Problem von Framework-Versions-Übergängen (ISO 27001:2013 →
 * 2022, BSI C5:2020 → 2026, NIS2-Überarbeitungen) für den CM:
 * Mappings, Evidence-Verweise und transitive Coverage sollen in der
 * neuen Version **ohne manuellen Neu-Aufbau** verfügbar sein.
 *
 * Strategie:
 *   1. Vorgänger-Nachfolger-Framework-Paar wird über `lifecycleState =
 *      superseded|deprecated` und `successor` identifiziert (oder
 *      explizit als CLI-Argument übergeben).
 *   2. Pro Anforderung im alten Framework wird der Nachfolger im neuen
 *      Framework gesucht:
 *        - Exact-Match auf `requirementId` (Default).
 *        - Optional Title-Exact-Match (Fallback).
 *   3. Für jedes gefundene Paar wird eine "bridge"-ComplianceMapping
 *      angelegt (old.req → new.req, type=full, percentage=100,
 *      confidence=high, verified_by='app:migrate-framework-version').
 *   4. Indirect-Hinweis für den Auditor: die Bridge hat eine klare
 *      `rationale`, sodass transitive Coverage (Service B1) aus dem
 *      alten Framework auch im neuen zählt.
 *
 * Keine bestehende Mapping wird überschrieben. Alle Änderungen sind
 * idempotent: Re-Run legt nur fehlende Bridges an.
 *
 * UI-Wizard folgt im Sprint 3 — diese Service-Ebene liefert das
 * Preview/Accept-Protokoll, das der Wizard dann rendern kann.
 */
final class FrameworkVersionMigrator
{
    public const MATCH_STRATEGY_ID = 'id';
    public const MATCH_STRATEGY_TITLE = 'title';
    public const MATCH_STRATEGY_BOTH = 'both';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly ComplianceMappingRepository $mappingRepository,
    ) {
    }

    /**
     * @return array{
     *     source: array{code: string, name: string},
     *     target: array{code: string, name: string},
     *     matched: list<array{
     *         source_id: string, target_id: string, source_title: string,
     *         target_title: string, via: string
     *     }>,
     *     unmatched: list<array{source_id: string, source_title: string}>,
     *     bridges_created: int,
     *     bridges_skipped_existing: int,
     * }
     */
    public function migrate(
        ComplianceFramework $old,
        ComplianceFramework $new,
        string $strategy = self::MATCH_STRATEGY_ID,
        bool $persist = true,
    ): array {
        $oldReqs = $this->requirementRepository->findByFramework($old);
        $newReqs = $this->requirementRepository->findByFramework($new);

        $newById = [];
        $newByTitle = [];
        foreach ($newReqs as $req) {
            $rid = (string) $req->getRequirementId();
            if ($rid !== '') {
                $newById[$rid] = $req;
            }
            $title = $this->normaliseTitle((string) $req->getTitle());
            if ($title !== '') {
                $newByTitle[$title] = $req;
            }
        }

        $matched = [];
        $unmatched = [];
        $bridgesCreated = 0;
        $bridgesSkippedExisting = 0;

        foreach ($oldReqs as $oldReq) {
            $candidate = null;
            $via = '';

            if ($strategy === self::MATCH_STRATEGY_ID || $strategy === self::MATCH_STRATEGY_BOTH) {
                $rid = (string) $oldReq->getRequirementId();
                if ($rid !== '' && isset($newById[$rid])) {
                    $candidate = $newById[$rid];
                    $via = 'requirement_id';
                }
            }
            if ($candidate === null && ($strategy === self::MATCH_STRATEGY_TITLE || $strategy === self::MATCH_STRATEGY_BOTH)) {
                $title = $this->normaliseTitle((string) $oldReq->getTitle());
                if ($title !== '' && isset($newByTitle[$title])) {
                    $candidate = $newByTitle[$title];
                    $via = 'title_exact';
                }
            }

            if (!$candidate instanceof ComplianceRequirement) {
                $unmatched[] = [
                    'source_id' => (string) $oldReq->getRequirementId(),
                    'source_title' => (string) $oldReq->getTitle(),
                ];
                continue;
            }

            $matched[] = [
                'source_id' => (string) $oldReq->getRequirementId(),
                'target_id' => (string) $candidate->getRequirementId(),
                'source_title' => (string) $oldReq->getTitle(),
                'target_title' => (string) $candidate->getTitle(),
                'via' => $via,
            ];

            $existing = $this->mappingRepository->findOneBy([
                'sourceRequirement' => $oldReq,
                'targetRequirement' => $candidate,
            ]);
            if ($existing instanceof ComplianceMapping) {
                $bridgesSkippedExisting++;
                continue;
            }

            if ($persist) {
                $mapping = new ComplianceMapping();
                $mapping->setSourceRequirement($oldReq);
                $mapping->setTargetRequirement($candidate);
                $mapping->setMappingType('full');
                $mapping->setMappingPercentage(100);
                $mapping->setConfidence('high');
                $mapping->setBidirectional(true);
                $mapping->setMappingRationale(sprintf(
                    'Auto-migration %s → %s (match via %s)',
                    $old->getCode() ?? '',
                    $new->getCode() ?? '',
                    $via
                ));
                $mapping->setVerifiedBy('app:migrate-framework-version');
                $mapping->setVerificationDate(new DateTimeImmutable());
                $this->entityManager->persist($mapping);
            }
            $bridgesCreated++;
        }

        if ($persist) {
            $this->entityManager->flush();
        }

        return [
            'source' => ['code' => (string) $old->getCode(), 'name' => (string) $old->getName()],
            'target' => ['code' => (string) $new->getCode(), 'name' => (string) $new->getName()],
            'matched' => $matched,
            'unmatched' => $unmatched,
            'bridges_created' => $bridgesCreated,
            'bridges_skipped_existing' => $bridgesSkippedExisting,
        ];
    }

    private function normaliseTitle(string $title): string
    {
        $title = mb_strtolower($title, 'UTF-8');
        $title = preg_replace('/\s+/u', ' ', trim($title)) ?? $title;
        return $title;
    }
}
