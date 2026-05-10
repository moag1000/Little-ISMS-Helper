<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Risk;
use App\Entity\Vulnerability;
use App\Service\AutoReactionService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

/**
 * Audit V3 C3 — Auto-Risk-Skeleton on high-CVSS Vulnerability.
 *
 * Whenever a Vulnerability with CVSS >= 7.0 is persisted/updated and no
 * Risk is yet linked, create a Risk skeleton with title "Vulnerability {CVE}"
 * and inherent probability/impact derived from CVSS via heuristic.
 *
 * Toggle: AutoReactionService::KEY_RISK_SKELETON (default true).
 */
#[AsEntityListener(event: Events::postPersist, entity: Vulnerability::class)]
#[AsEntityListener(event: Events::postUpdate, entity: Vulnerability::class)]
class AutoReactionRiskSkeletonListener
{
    public function __construct(
        private readonly AutoReactionService $reactions,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function postPersist(Vulnerability $vuln, PostPersistEventArgs $args): void
    {
        $this->maybeCreateRisk($vuln, $args);
    }

    public function postUpdate(Vulnerability $vuln, PostUpdateEventArgs $args): void
    {
        $this->maybeCreateRisk($vuln, $args);
    }

    private function maybeCreateRisk(Vulnerability $vuln, mixed $args): void
    {
        if (!$this->reactions->isEnabled(AutoReactionService::KEY_RISK_SKELETON)) {
            return;
        }

        $cvss = (float) ($vuln->getCvssScore() ?? 0);
        if ($cvss < 7.0) {
            return;
        }

        $cve = $vuln->getCveId() ?? ('VULN-' . ($vuln->getId() ?? '?'));
        $expectedTitle = 'Vulnerability ' . $cve;

        try {
            $em = $args->getObjectManager();

            // Already linked Risk?
            $existing = $em->getRepository(Risk::class)->findOneBy([
                'title' => $expectedTitle,
                'tenant' => method_exists($vuln, 'getTenant') ? $vuln->getTenant() : null,
            ]);
            if ($existing instanceof Risk) {
                return;
            }

            // Heuristic mapping: CVSS 7.0-7.9 => p3/i3; 8.0-8.9 => p4/i4; 9.0+ => p5/i5
            [$prob, $imp] = match (true) {
                $cvss >= 9.0 => [5, 5],
                $cvss >= 8.0 => [4, 4],
                default      => [3, 3],
            };

            $risk = new Risk();
            $risk->setTenant(method_exists($vuln, 'getTenant') ? $vuln->getTenant() : null);
            $risk->setTitle($expectedTitle);
            if (method_exists($risk, 'setDescription')) {
                $risk->setDescription(sprintf(
                    'Auto-generated risk skeleton from Vulnerability %s (CVSS %.1f). Description: %s',
                    $cve,
                    $cvss,
                    $vuln->getDescription() ?? ''
                ));
            }
            if (method_exists($risk, 'setProbability')) {
                $risk->setProbability($prob);
            }
            if (method_exists($risk, 'setImpact')) {
                $risk->setImpact($imp);
            }

            $em->persist($risk);
            $em->flush();

            $this->logger->info('Auto-Risk-Skeleton created for vulnerability', [
                'vulnerability_id' => $vuln->getId(),
                'cve' => $cve,
                'cvss' => $cvss,
                'risk_id' => $risk->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Auto-Risk-Skeleton failed', [
                'vulnerability_id' => $vuln->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
