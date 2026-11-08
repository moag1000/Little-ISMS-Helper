<?php

declare(strict_types=1);

namespace App\Tests\Entity\Authority;

use App\Entity\Authority\Nis2RegistrationProfile;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * NIS-2 Art. 3 (4) / BSIG § 33: a change to the reported registration data
 * is reported within two weeks — the profile notes the first change and
 * moves its next due date forward.
 */
final class Nis2RegistrationChangeTest extends TestCase
{
    #[Test]
    public function aChangeAfterTheReportIsDueInTwoWeeks(): void
    {
        $profile = $this->reported();
        $yearly = $profile->getNextDueAt();

        $before = $profile->fingerprint();
        $profile->setOrganizationLegalForm('AG');
        $profile->noteChange($before, new DateTimeImmutable('2026-11-08 10:00'));

        self::assertEquals(new DateTimeImmutable('2026-11-08 10:00'), $profile->getUnreportedChangeAt());
        self::assertEquals(new DateTimeImmutable('2026-11-22 10:00'), $profile->getEffectiveDueAt());
        self::assertSame($yearly, $profile->getNextDueAt(), 'the yearly confirmation stays');

        // A second edit does not restart the two weeks.
        $before = $profile->fingerprint();
        $profile->setAffectedHeadcount(260);
        $profile->noteChange($before, new DateTimeImmutable('2026-11-12'));
        self::assertEquals(new DateTimeImmutable('2026-11-08 10:00'), $profile->getUnreportedChangeAt());
    }

    #[Test]
    public function revertingOrReportingClearsTheChange(): void
    {
        $profile = $this->reported();

        $before = $profile->fingerprint();
        $profile->setOrganizationLegalForm('AG');
        $profile->noteChange($before);
        $profile->setOrganizationLegalForm('GmbH');
        $profile->noteChange($before);
        self::assertNull($profile->getUnreportedChangeAt(), 'back to the reported data');

        $profile->setOrganizationLegalForm('AG');
        $profile->noteChange($before);
        $profile->markDataReported();
        self::assertNull($profile->getUnreportedChangeAt());
        self::assertSame($profile->getNextDueAt(), $profile->getEffectiveDueAt());
    }

    #[Test]
    public function beforeTheFirstReportThereIsNothingToChange(): void
    {
        $profile = (new Nis2RegistrationProfile())->setOrganizationLegalForm('GmbH');

        $before = $profile->fingerprint();
        $profile->setOrganizationLegalForm('AG');
        $profile->noteChange($before);

        self::assertNull($profile->getUnreportedChangeAt());
    }

    #[Test]
    public function profilesReportedBeforeFingerprintsTakeTheStateBeforeTheEdit(): void
    {
        $profile = (new Nis2RegistrationProfile())->setOrganizationLegalForm('GmbH');
        $profile->setLastReportedAt(new DateTimeImmutable('2026-01-10'));
        $profile->setNextDueAt(new DateTimeImmutable('2027-01-10'));

        $before = $profile->fingerprint();
        $profile->setOrganizationLegalForm('AG');
        $profile->noteChange($before);

        self::assertNotNull($profile->getUnreportedChangeAt());
    }

    private function reported(): Nis2RegistrationProfile
    {
        $profile = (new Nis2RegistrationProfile())
            ->setOrganizationLegalName('Muster GmbH')
            ->setOrganizationLegalForm('GmbH');
        $profile->setLastReportedAt(new DateTimeImmutable('2026-03-01'));
        $profile->setNextDueAt(new DateTimeImmutable('2027-03-01'));
        $profile->markDataReported();

        return $profile;
    }
}
