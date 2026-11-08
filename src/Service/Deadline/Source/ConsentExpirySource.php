<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\Consent;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/**
 * Einwilligung läuft ab: getExpiresAt() of an active Consent (DSGVO Art. 7).
 * Processing on that consent ends with it, so the DPO renews or stops it.
 * The title carries no data-subject identifier (it travels in mails).
 */
final class ConsentExpirySource extends EntityDeadlineSource
{
    protected function entityClass(): string
    {
        return Consent::class;
    }

    public function slug(): string
    {
        return 'consent_expiry';
    }

    public function label(): string
    {
        return 'Einwilligung läuft ab';
    }

    public function requiredModule(): string
    {
        return 'privacy';
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof Consent);

        return $item->getExpiresAt();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof Consent);

        return trim(sprintf('%s #%d', (string) $item->getProcessingActivity()?->getName(), (int) $item->getId()));
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof Consent);

        return $item->getStatus() !== 'active' || $item->isRevoked() || $item->getWithdrawnAt() !== null;
    }

    public function responsibleOf(object $item): Responsibility
    {
        return Responsibility::roles('ROLE_DPO');
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof Consent);

        return new RecordLink('app_consent_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'consents_expiring', leadDays: 30, normReference: 'DSGVO Art. 7');
    }
}
