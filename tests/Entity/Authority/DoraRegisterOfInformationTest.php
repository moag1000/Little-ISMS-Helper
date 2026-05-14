<?php

declare(strict_types=1);

namespace App\Tests\Entity\Authority;

use App\Entity\Authority\DoraRegisterOfInformation;
use App\Entity\Tenant;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DoraRegisterOfInformation entity accessors and helper methods.
 */
final class DoraRegisterOfInformationTest extends TestCase
{
    #[Test]
    public function defaultsAreCorrectOnCreation(): void
    {
        $record = new DoraRegisterOfInformation();

        self::assertNull($record->getId());
        self::assertNull($record->getTenant());
        self::assertNull($record->getReportingDate());
        self::assertSame(DoraRegisterOfInformation::SCOPE_YEARLY_FULL, $record->getReportingScope());
        self::assertNull($record->getSubmittedAt());
        self::assertNull($record->getSubmittedBy());
        self::assertNull($record->getPayloadHash());
        self::assertNull($record->getConfirmationNumber());
        self::assertInstanceOf(DateTimeImmutable::class, $record->getCreatedAt());
    }

    #[Test]
    public function isSubmittedReturnsFalseWhenSubmittedAtIsNull(): void
    {
        $record = new DoraRegisterOfInformation();
        self::assertFalse($record->isSubmitted());
    }

    #[Test]
    public function isSubmittedReturnsTrueWhenSubmittedAtIsSet(): void
    {
        $record = new DoraRegisterOfInformation();
        $record->setSubmittedAt(new DateTimeImmutable());
        self::assertTrue($record->isSubmitted());
    }

    #[Test]
    public function isCurrentYearReturnsTrueForCurrentYearDate(): void
    {
        $record = new DoraRegisterOfInformation();
        $record->setReportingDate(new DateTimeImmutable((new DateTimeImmutable())->format('Y') . '-12-31'));
        self::assertTrue($record->isCurrentYear());
    }

    #[Test]
    public function isCurrentYearReturnsFalseForPastYearDate(): void
    {
        $record = new DoraRegisterOfInformation();
        $record->setReportingDate(new DateTimeImmutable('2020-12-31'));
        self::assertFalse($record->isCurrentYear());
    }

    #[Test]
    public function isCurrentYearReturnsFalseWhenReportingDateIsNull(): void
    {
        $record = new DoraRegisterOfInformation();
        self::assertFalse($record->isCurrentYear());
    }

    #[Test]
    public function setReportingScopeAcceptsValidScope(): void
    {
        $record = new DoraRegisterOfInformation();
        $record->setReportingScope(DoraRegisterOfInformation::SCOPE_SIGNIFICANT_CHANGES);
        self::assertSame(DoraRegisterOfInformation::SCOPE_SIGNIFICANT_CHANGES, $record->getReportingScope());
    }

    #[Test]
    public function setReportingScopeThrowsForInvalidScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $record = new DoraRegisterOfInformation();
        $record->setReportingScope('invalid_scope');
    }

    #[Test]
    public function setTenantAndGetTenant(): void
    {
        $record = new DoraRegisterOfInformation();
        $tenant = new Tenant();
        $record->setTenant($tenant);
        self::assertSame($tenant, $record->getTenant());
    }

    #[Test]
    public function setSubmittedByAndGetSubmittedBy(): void
    {
        $record = new DoraRegisterOfInformation();
        $user = new User();
        $record->setSubmittedBy($user);
        self::assertSame($user, $record->getSubmittedBy());
    }

    #[Test]
    public function payloadHashAndConfirmationNumberAccessors(): void
    {
        $record = new DoraRegisterOfInformation();
        $hash = hash('sha256', 'test-payload');
        $record->setPayloadHash($hash);
        $record->setConfirmationNumber('EBA-2026-DORA-ROI-0001');

        self::assertSame($hash, $record->getPayloadHash());
        self::assertSame('EBA-2026-DORA-ROI-0001', $record->getConfirmationNumber());
    }

    #[Test]
    public function validScopesConstantContainsBothScopes(): void
    {
        self::assertContains(
            DoraRegisterOfInformation::SCOPE_YEARLY_FULL,
            DoraRegisterOfInformation::VALID_SCOPES
        );
        self::assertContains(
            DoraRegisterOfInformation::SCOPE_SIGNIFICANT_CHANGES,
            DoraRegisterOfInformation::VALID_SCOPES
        );
    }
}
