<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Doctrine\PostFlushWriter;
use App\Entity\Document;
use App\Enum\DocumentStatus;
use App\Service\AutoReactionService;
use App\Service\PolicyWizard\PolicyAcknowledgementService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

/**
 * Auto-Acknowledgement-Campaign.
 *
 * Whenever a Document transitions to status='approved' AND
 * requiresAcknowledgement=true, create a PolicyAcknowledgement record
 * (status=pending) for every active user in the tenant — closing
 * ISO 27001 A.6.3 ("policy must be communicated and acknowledged").
 *
 * Audience and version come from {@see PolicyAcknowledgementService} (picked audience,
 * Policy-Wizard override, topic, else every active user; template version or file hash), the
 * same the acknowledgement uses — a campaign row stored under another version never closed (W8).
 * People who join later and new versions are picked up by the nightly
 * `app:policy-wizard:send-ack-requests`.
 *
 * fix:
 *   - Real persistence: rows of {@see PolicyAcknowledgement} with
 *     STATUS_PENDING are now created (was: counter-only, no DB writes
 *     — campaign trigger was a silent no-op).
 *   - Audit-trail snapshot: each pending row captures (tenant, document,
 *     user, version, requestedAt) so an auditor can verify "user X was
 *     in audience at time T" even if X leaves the company before
 *     acknowledging.
 *
 * Notification fan-out is intentionally NOT done here — it is the
 * caller's responsibility to surface pending rows via the
 * inbox UI / email reminder cron.
 *
 * Derived rows are written after the flush via PostFlushWriter (no flush inside the listener).
 *
 * Toggle: AutoReactionService::KEY_ACK_CAMPAIGN (default true).
 */
#[AsEntityListener(event: Events::postUpdate, entity: Document::class)]
final class AutoReactionAcknowledgementCampaignListener
{
    public function __construct(
        private readonly AutoReactionService $reactions,
        private readonly LoggerInterface $logger,
        private readonly PostFlushWriter $postFlushWriter,
        private readonly PolicyAcknowledgementService $acknowledgements,
    ) {
    }

    public function postUpdate(Document $document, PostUpdateEventArgs $args): void
    {
        if (!$this->reactions->isEnabled(AutoReactionService::KEY_ACK_CAMPAIGN)) {
            return;
        }

        if ($document->getStatus() !== DocumentStatus::Approved->value) {
            return;
        }
        if (!PolicyAcknowledgementService::needsAcknowledgement($document)) {
            return;
        }

        try {
            // One version function and one audience for the campaign, the acknowledgement and the
            // nightly sweep (PolicyAcknowledgementService) — a pending row is the one the click closes.
            $created = 0;
            foreach ($this->acknowledgements->missingRequests($document) as $pending) {
                $this->postFlushWriter->persist($pending);
                $created++;
            }

            if ($created > 0) {
                $this->logger->info('Auto-Acknowledgement campaign persisted pending rows', [
                    'document_id' => $document->getId(),
                    'document_version' => PolicyAcknowledgementService::versionOf($document),
                    'tenant_id' => $document->getTenant()?->getId(),
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
