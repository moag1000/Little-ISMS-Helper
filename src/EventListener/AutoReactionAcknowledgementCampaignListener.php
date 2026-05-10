<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Document;
use App\Entity\PolicyAcknowledgement;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AutoReactionService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

/**
 * Audit V3 C3 — Auto-Acknowledgement-Campaign.
 *
 * Whenever a Document transitions to status='approved' AND
 * requiresAcknowledgement=true, create a PolicyAcknowledgement record
 * (status=pending) for every active user in the tenant — closing
 * ISO 27001 A.6.3 ("policy must be communicated and acknowledged").
 *
 * Audit V3 W2-C4 fix:
 *   - Tenant-scoped active-user query (was: cross-tenant {@see UserRepository::findBy(['isActive'=>true])}).
 *   - Real persistence: rows of {@see PolicyAcknowledgement} with
 *     STATUS_PENDING are now created (was: counter-only, no DB writes
 *     — campaign trigger was a silent no-op).
 *   - Audit-trail snapshot: each pending row captures (tenant, document,
 *     user, version, requestedAt) so an auditor can verify "user X was
 *     in audience at time T" even if X leaves the company before
 *     acknowledging.
 *
 * Notification fan-out is intentionally NOT done here — it is the
 * caller's / W2-H4's responsibility to surface pending rows via the
 * inbox UI / email reminder cron.
 *
 * Toggle: AutoReactionService::KEY_ACK_CAMPAIGN (default true).
 */
#[AsEntityListener(event: Events::postUpdate, entity: Document::class)]
class AutoReactionAcknowledgementCampaignListener
{
    public function __construct(
        private readonly AutoReactionService $reactions,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function postUpdate(Document $document, PostUpdateEventArgs $args): void
    {
        if (!$this->reactions->isEnabled(AutoReactionService::KEY_ACK_CAMPAIGN)) {
            return;
        }

        if ($document->getStatus() !== 'approved') {
            return;
        }
        if (method_exists($document, 'getRequiresAcknowledgement')
            && !$document->getRequiresAcknowledgement()) {
            return;
        }

        try {
            $em = $args->getObjectManager();
            $tenant = method_exists($document, 'getTenant') ? $document->getTenant() : null;
            if (!$tenant) {
                return;
            }

            $version = method_exists($document, 'getVersion') ? (string) ($document->getVersion() ?? '') : '';
            if ($version === '') {
                // Cannot satisfy unique constraint (tenant, doc, user, version) without a version.
                $this->logger->warning('Auto-Acknowledgement-Campaign skipped: document has no version', [
                    'document_id' => $document->getId(),
                ]);
                return;
            }
            $repo = $em->getRepository(PolicyAcknowledgement::class);

            // Audit V3 W2-C4: tenant-scoped active-user query.
            $userRepo = $em->getRepository(User::class);
            $users = $userRepo->findBy([
                'isActive' => true,
                'tenant' => $tenant,
            ]);

            $created = 0;
            foreach ($users as $user) {
                /** @var User $user */
                $existing = $repo->findOneFor($tenant, $document, $user, $version);
                if ($existing instanceof PolicyAcknowledgement) {
                    continue;
                }

                $pending = new PolicyAcknowledgement();
                $pending->setTenant($tenant);
                $pending->setDocument($document);
                $pending->setUser($user);
                $pending->setDocumentVersion($version);
                // Constructor pre-fills both requestedAt + acknowledgedAt;
                // for a pending row we clear acknowledgedAt + method.
                $pending->setStatus(PolicyAcknowledgement::STATUS_PENDING);
                $pending->setAcknowledgedAt(null);
                $pending->setAcknowledgementMethod(null);

                $em->persist($pending);
                $created++;
            }

            if ($created > 0) {
                $em->flush();
                $this->logger->info('Auto-Acknowledgement campaign persisted pending rows', [
                    'document_id' => $document->getId(),
                    'document_version' => $version,
                    'tenant_id' => $tenant->getId(),
                    'pending_user_count' => $created,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Auto-Acknowledgement-Campaign failed', [
                'document_id' => $document->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
