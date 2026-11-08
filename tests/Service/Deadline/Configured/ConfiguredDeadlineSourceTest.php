<?php

declare(strict_types=1);

namespace App\Tests\Service\Deadline\Configured;

use App\Entity\ComplianceCertificate;
use App\Entity\Consent;
use App\Entity\Control;
use App\Entity\CrisisTeam;
use App\Entity\ISMSContext;
use App\Entity\Patch;
use App\Entity\Person;
use App\Entity\ProcessingActivity;
use App\Entity\Risk;
use App\Entity\User;
use App\Enum\PatchStatus;
use App\Enum\RiskStatus;
use App\Enum\TreatmentStrategy;
use App\Service\Deadline\Configured\ConfiguredDeadlineSource;
use App\Service\Deadline\Configured\ConfiguredDeadlineSources;
use App\Service\Deadline\RecordLink;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of deadline types that were classes before they became entries
 * in config/deadline_types.yaml — the same answers as the former classes.
 */
final class ConfiguredDeadlineSourceTest extends TestCase
{
    private function source(string $slug): ConfiguredDeadlineSource
    {
        $source = ConfiguredDeadlineSources::fromFile($this->createStub(EntityManagerInterface::class))->get($slug);
        self::assertNotNull($source, $slug);

        return $source;
    }

    #[Test]
    public function consentIsDoneWhenRevokedWithdrawnOrNotActive(): void
    {
        $source = $this->source('consent_expiry');
        $consent = (new Consent())->setStatus('active')->setExpiresAt(new DateTimeImmutable('2026-12-01'));
        self::assertFalse($source->isCompleted($consent));
        self::assertEquals(new DateTimeImmutable('2026-12-01'), $source->dueDateOf($consent));

        self::assertTrue($source->isCompleted((clone $consent)->setWithdrawnAt(new DateTimeImmutable())));
        self::assertTrue($source->isCompleted((clone $consent)->setIsRevoked(true)));
        self::assertTrue($source->isCompleted((clone $consent)->setStatus('expired')));
        self::assertSame('privacy', $source->requiredModule());
        self::assertSame(['ROLE_DPO'], $source->responsibleOf($consent)->roles);
    }

    #[Test]
    public function consentTitleNamesTheProcessingNotThePerson(): void
    {
        $source = $this->source('consent_expiry');
        $consent = (new Consent())->setProcessingActivity((new ProcessingActivity())->setName('Newsletter'));
        self::assertSame('Newsletter #', $source->titleOf($consent), 'unsaved record: no id yet');
        self::assertSame('#', $source->titleOf(new Consent()));
    }

    #[Test]
    public function riskReviewEndsWithAClosedRisk(): void
    {
        $source = $this->source('risk_review');
        $risk = (new Risk())->setTitle('Ransomware')->setReviewDate(new DateTimeImmutable('2026-11-20'));
        self::assertFalse($source->isCompleted($risk));
        self::assertTrue($source->isCompleted((clone $risk)->setStatus(RiskStatus::Closed)));
        self::assertSame('Ransomware', $source->titleOf($risk));
        self::assertSame('Risk #', $source->titleOf(new Risk()));
        self::assertSame(['ROLE_RISK_MANAGER'], $source->responsibleOf($risk)->roles);
        self::assertSame(14, $source->profile()->leadDays);
    }

    #[Test]
    public function acceptedRiskWithoutExpiryIsAnOpenDutyWithoutDate(): void
    {
        $source = $this->source('risk_acceptance_no_expiry');
        $owner = new User();
        $accepted = (new Risk())->setTreatmentStrategy(TreatmentStrategy::Accept)->setRiskOwner($owner);

        self::assertFalse($source->isCompleted($accepted));
        self::assertNull($source->dueDateOf($accepted));
        self::assertNull($source->profile()->leadDays);
        self::assertSame([$owner], $source->responsibleOf($accepted)->holders);
        self::assertTrue($source->isCompleted((clone $accepted)->setAcceptanceExpiryDate(new DateTimeImmutable('2027-01-01'))));
        self::assertTrue($source->isCompleted((clone $accepted)->setStatus(RiskStatus::Closed)));
        self::assertFalse($source->isCompleted((new Risk())->setStatus(RiskStatus::Accepted)));
        self::assertTrue($source->isCompleted(new Risk()), 'a risk that is not accepted carries no such duty');
    }

    #[Test]
    public function patchIsDoneWhenDeployedOrNotApplicable(): void
    {
        $source = $this->source('patch_deployment');
        self::assertFalse($source->isCompleted((new Patch())->setStatus('pending')));
        self::assertTrue($source->isCompleted((new Patch())->setStatus(PatchStatus::Deployed)));
        self::assertTrue($source->isCompleted((new Patch())->setStatus(PatchStatus::NotApplicable)));
    }

    #[Test]
    public function wordsJoinsTheNonEmptyParts(): void
    {
        $certificate = (new ComplianceCertificate())->setFrameworkCode('ISO27001')->setCertBody('TÜV');
        self::assertSame('ISO27001 TÜV', $this->source('certificate_expiry')->titleOf($certificate));

        $control = (new Control())->setName('Zugangssteuerung');
        self::assertSame('Zugangssteuerung', $this->source('control_review')->titleOf($control));
        self::assertSame('A.5.15 Zugangssteuerung', $this->source('control_review')->titleOf($control->setControlId('A.5.15')));
    }

    #[Test]
    public function notApplicableControlCarriesNoDuty(): void
    {
        $control = (new Control())->setApplicable(false);
        self::assertTrue($this->source('control_review')->isCompleted($control));
        self::assertTrue($this->source('control_implementation')->isCompleted($control));
        self::assertTrue($this->source('control_implementation')->isCompleted((new Control())->setApplicable(true)->setImplementationStatus('implemented')));
        self::assertFalse($this->source('control_implementation')->isCompleted((new Control())->setApplicable(true)->setImplementationStatus('planned')));
    }

    #[Test]
    public function personWhoseAccessEndsIsInformed(): void
    {
        $source = $this->source('person_access_expiry');
        $person = (new Person())->setFullName('Erika Muster')->setActive(true);
        $responsibility = $source->responsibleOf($person);

        self::assertSame(['ROLE_ADMIN'], $responsibility->roles);
        self::assertSame([$person], $responsibility->informed);
        self::assertSame([], $responsibility->holders);
        self::assertTrue($source->isCompleted((new Person())->setActive(false)));
        self::assertSame('Erika Muster', $source->titleOf($person));
    }

    #[Test]
    public function crisisTeamDeputiesIncludeCollections(): void
    {
        $leader = new User();
        $deputy = new User();
        $deputyPerson = new Person();
        $team = (new CrisisTeam())->setTeamLeader($leader)->setDeputyLeader($deputy)->addTeamLeaderDeputyPerson($deputyPerson);
        $responsibility = $this->source('crisis_team_training')->responsibleOf($team);

        self::assertSame([$leader], $responsibility->holders);
        self::assertSame([$deputy, $deputyPerson], $responsibility->deputies);
        self::assertSame(['ROLE_BCM_OFFICER'], $responsibility->roles);
    }

    #[Test]
    public function linksWithAndWithoutRecordId(): void
    {
        self::assertEquals(new RecordLink('app_context_index', []), $this->source('isms_context_review')->linkOf(new ISMSContext()));
        self::assertEquals(new RecordLink('app_risk_show', ['id' => 0]), $this->source('risk_review')->linkOf(new Risk()));
        self::assertSame('ISMS-Kontext', $this->source('isms_context_review')->titleOf(new ISMSContext()));
        self::assertFalse($this->source('isms_context_review')->isCompleted(new ISMSContext()));
    }

    #[Test]
    public function profileCarriesBucketLeadDaysAndNorm(): void
    {
        $profile = $this->source('certificate_expiry')->profile();
        self::assertSame('certificates_expiring', $profile->bucket);
        self::assertSame(90, $profile->leadDays);
        self::assertSame('ISO/IEC 17021-1 (Rezertifizierung)', $profile->normReference);
        self::assertFalse($profile->decision);
    }
}
