<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\ComplianceCertificate;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/** Zertifikatsablauf: getValidUntil() of ComplianceCertificate. */
final class CertificateExpirySource extends EntityDeadlineSource
{
    protected function entityClass(): string
    {
        return ComplianceCertificate::class;
    }

    public function slug(): string
    {
        return 'certificate_expiry';
    }

    public function label(): string
    {
        return 'Zertifikatsablauf';
    }

    public function requiredModule(): ?string
    {
        return null;
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof ComplianceCertificate);

        return $item->getValidUntil();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof ComplianceCertificate);

        return $this->certificateTitle($item);
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof ComplianceCertificate);

        return $item->getStatus() !== 'active';
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof ComplianceCertificate);

        return Responsibility::roles('ROLE_COMPLIANCE_MANAGER', 'ROLE_ISB', 'ROLE_CISO');
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof ComplianceCertificate);

        return new RecordLink('app_compliance_certificate_index', []);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'certificates_expiring', leadDays: 90, normReference: 'ISO/IEC 17021-1 (Rezertifizierung)');
    }

    private function certificateTitle(ComplianceCertificate $item): string
    {
        return trim(implode(' ', array_filter([$item->getFrameworkCode(), $item->getCertNumber(), $item->getCertBody()])));
    }
}
