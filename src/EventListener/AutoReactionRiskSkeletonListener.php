<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Risk;
use App\Entity\Vulnerability;
use App\Repository\UserRepository;
use App\Service\AutoReactionService;
use App\Service\EmailNotificationService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

/**
 * Audit V3 C3 / V4-LB-3 — Auto-Risk-Skeleton on high-CVSS Vulnerability.
 *
 * Whenever a Vulnerability with CVSS >= 7.0 is persisted/updated and no
 * Risk is yet linked, create a Risk skeleton with title "Vulnerability {CVE}"
 * and inherent probability/impact derived from CVSS via heuristic.
 *
 * Idempotency (V4-LB-3): uses Risk.linkedVulnerability FK lookup instead of
 * title-string match, which was brittle against title renames and false-positives
 * with similarly-named vulnerabilities.
 *
 * Toggle: AutoReactionService::KEY_RISK_SKELETON (default true).
 */
#[AsEntityListener(event: Events::postPersist, entity: Vulnerability::class)]
#[AsEntityListener(event: Events::postUpdate, entity: Vulnerability::class)]
final class AutoReactionRiskSkeletonListener
{
    public function __construct(
        private readonly AutoReactionService $reactions,
        private readonly LoggerInterface $logger,
        private readonly ?EmailNotificationService $emailNotifier = null,
        private readonly ?UserRepository $userRepository = null,
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

        try {
            $em = $args->getObjectManager();

            // V4-LB-3: Idempotency via FK, not title-string match.
            // Title-match is brittle: renames break it; similar CVE patterns
            // (e.g. CVE-2026-1 vs CVE-2026-10) cause false-positives.
            $existing = $em->getRepository(Risk::class)->findOneBy([
                'linkedVulnerability' => $vuln,
            ]);
            if ($existing instanceof Risk) {
                return;
            }

            $expectedTitle = 'Vulnerability ' . $cve;

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
            // V4-LB-3: Link Risk→Vulnerability via FK for reliable future idempotency checks.
            $risk->setLinkedVulnerability($vuln);

            $em->persist($risk);
            $em->flush();

            $this->logger->info('Auto-Risk-Skeleton created for vulnerability', [
                'vulnerability_id' => $vuln->getId(),
                'cve' => $cve,
                'cvss' => $cvss,
                'risk_id' => $risk->getId(),
            ]);

            // V3 W2-H4 (ISO 27001 Cl.7.4): Notify Risk-Owner candidates so the
            // skeleton lands in someone's inbox instead of waiting silently.
            $this->notifyRiskOwner($risk, $vuln, $cve, $cvss);
        } catch (\Throwable $e) {
            $this->logger->warning('Auto-Risk-Skeleton failed', [
                'vulnerability_id' => $vuln->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * V3 W2-H4 — Notify Risk-Owner / Risk-Manager about the auto-created
     * skeleton. The skeleton has no owner yet, so we fall back to ROLE_RISK_MANAGER
     * then ROLE_CISO (tenant-scoped). Best-effort.
     */
    private function notifyRiskOwner(Risk $risk, Vulnerability $vuln, string $cve, float $cvss): void
    {
        if ($this->emailNotifier === null || $this->userRepository === null) {
            return;
        }
        try {
            $tenant = method_exists($risk, 'getTenant') ? $risk->getTenant() : null;
            $recipients = $this->userRepository->findByRoleInTenant('ROLE_RISK_MANAGER', $tenant);
            if (empty($recipients)) {
                $recipients = $this->userRepository->findByRoleInTenant('ROLE_CISO', $tenant);
            }
            if (empty($recipients)) {
                $recipients = $this->userRepository->findByRoleInTenant('ROLE_MANAGER', $tenant);
            }
            if (empty($recipients)) {
                return;
            }
            $this->emailNotifier->sendGenericNotification(
                sprintf('Auto-Risk skeleton created for vulnerability %s (CVSS %.1f)', $cve, $cvss),
                'emails/auto_reaction_risk_skeleton.html.twig',
                [
                    'risk' => $risk,
                    'vulnerability' => $vuln,
                    'cve' => $cve,
                    'cvss' => $cvss,
                ],
                $recipients
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Auto-Risk-Skeleton notification failed', [
                'risk_id' => $risk->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
