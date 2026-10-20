<?php

declare(strict_types=1);

namespace App\Tests\Validator;

use App\Entity\ActionItem;
use App\Entity\Asset;
use App\Entity\AuditFinding;
use App\Entity\AuditProgram;
use App\Entity\Authority\Nis2RegistrationProfile;
use App\Entity\BCExercise;
use App\Entity\BusinessContinuityPlan;
use App\Entity\BusinessProcess;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Control;
use App\Entity\CorrectiveAction;
use App\Entity\CrisisTeam;
use App\Entity\CustomReport;
use App\Entity\DataBreach;
use App\Entity\DataProtectionImpactAssessment;
use App\Entity\DataSubjectRequest;
use App\Entity\Document;
use App\Entity\FourEyesApprovalRequest;
use App\Entity\ISMSObjective;
use App\Entity\Incident;
use App\Entity\InterestedParty;
use App\Entity\InternalAudit;
use App\Entity\Location;
use App\Entity\ManagementReview;
use App\Entity\Patch;
use App\Entity\Person;
use App\Entity\PhysicalAccessLog;
use App\Entity\ProcessingActivity;
use App\Entity\PrototypeProtectionAssessment;
use App\Entity\Risk;
use App\Entity\RiskAppetite;
use App\Entity\RiskTreatmentPlan;
use App\Entity\Supplier;
use App\Entity\Team;
use App\Entity\Tenant;
use App\Entity\ThreatIntelligence;
use App\Entity\Training;
use App\Entity\User;
use App\Entity\Vulnerability;
use App\Entity\WorkflowInstance;
use App\Service\TenantContext;
use App\Validator\Constraint\SameTenantOwner;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * User-picker tenant sweep, server side: every owner / assignee / approver /
 * participant User (and Person) relation of the swept entities rejects a
 * related record of another organisation — the guard for API Platform writes
 * and tampered posts that bypass the picker. Collections check every element.
 */
final class SameTenantOwnerSweepTest extends KernelTestCase
{
    private const string MESSAGE = 'tenant.validation.owner_other_tenant';

    /**
     * @return iterable<string, array{class-string, string, class-string, bool}>
     *     [entity, property, related class, collection]
     */
    public static function relationProvider(): iterable
    {
        $rows = [
            [Training::class, 'participantUsers', User::class, true],
            [Training::class, 'trainerUser', User::class, false],
            [Training::class, 'trainerPerson', Person::class, false],
            [WorkflowInstance::class, 'initiatedBy', User::class, false],
            [PrototypeProtectionAssessment::class, 'assessor', User::class, false],
            [PrototypeProtectionAssessment::class, 'assessorPerson', Person::class, false],
            [AuditFinding::class, 'ncVerifiedBy', User::class, false],
            [AuditFinding::class, 'assignedTo', User::class, false],
            [AuditFinding::class, 'assignedPerson', Person::class, false],
            [RiskTreatmentPlan::class, 'responsiblePersonUser', User::class, false],
            [RiskTreatmentPlan::class, 'responsiblePerson', Person::class, false],
            [CustomReport::class, 'owner', User::class, false],
            [CustomReport::class, 'ownerPerson', Person::class, false],
            [DataProtectionImpactAssessment::class, 'dataProtectionOfficer', User::class, false],
            [DataProtectionImpactAssessment::class, 'dataProtectionOfficerPerson', Person::class, false],
            [DataProtectionImpactAssessment::class, 'conductor', User::class, false],
            [DataProtectionImpactAssessment::class, 'conductorPerson', Person::class, false],
            [Risk::class, 'riskOwner', User::class, false],
            [Risk::class, 'riskOwnerPerson', Person::class, false],
            [Risk::class, 'acceptanceApprovedByUser', User::class, false],
            [Risk::class, 'decisionApprovedByUser', User::class, false],
            [Person::class, 'linkedUser', User::class, false],
            [FourEyesApprovalRequest::class, 'requestedApprover', User::class, false],
            [FourEyesApprovalRequest::class, 'requestedApproverPerson', Person::class, false],
            [Team::class, 'teamLead', User::class, false],
            [Team::class, 'teamLeadPerson', Person::class, false],
            [Asset::class, 'ownerUser', User::class, false],
            [Asset::class, 'ownerPerson', Person::class, false],
            [DataSubjectRequest::class, 'assignedTo', User::class, false],
            [DataSubjectRequest::class, 'assignedPerson', Person::class, false],
            [Document::class, 'acknowledgementAudience', User::class, true],
            [RiskAppetite::class, 'approvedBy', User::class, false],
            [ActionItem::class, 'responsibleUser', User::class, false],
            [ActionItem::class, 'responsiblePerson', Person::class, false],
            [CorrectiveAction::class, 'responsiblePersonUser', User::class, false],
            [CorrectiveAction::class, 'responsiblePerson', Person::class, false],
            [InternalAudit::class, 'leadAuditorUser', User::class, false],
            [InternalAudit::class, 'leadAuditorPerson', Person::class, false],
            [DataBreach::class, 'dataProtectionOfficer', User::class, false],
            [DataBreach::class, 'dataProtectionOfficerPerson', Person::class, false],
            [ThreatIntelligence::class, 'assignedTo', User::class, false],
            [ThreatIntelligence::class, 'assignedPerson', Person::class, false],
            [Control::class, 'responsiblePersonUser', User::class, false],
            [Control::class, 'responsiblePersonRef', Person::class, false],
            [CrisisTeam::class, 'teamLeader', User::class, false],
            [CrisisTeam::class, 'teamLeaderPerson', Person::class, false],
            [CrisisTeam::class, 'deputyLeader', User::class, false],
            [CrisisTeam::class, 'deputyLeaderPerson', Person::class, false],
            [ManagementReview::class, 'participants', User::class, true],
            [ManagementReview::class, 'reviewedBy', User::class, false],
            [ManagementReview::class, 'reviewedByPerson', Person::class, false],
            [BCExercise::class, 'facilitatorUser', User::class, false],
            [BCExercise::class, 'facilitatorPerson', Person::class, false],
            [BCExercise::class, 'exerciseLeaderUser', User::class, false],
            [BCExercise::class, 'exerciseLeaderPerson', Person::class, false],
            [AuditProgram::class, 'programmeOwner', User::class, false],
            [Nis2RegistrationProfile::class, 'incidentReportingContact', User::class, false],
            [Nis2RegistrationProfile::class, 'securityResponsibleContact', User::class, false],
            [Nis2RegistrationProfile::class, 'backupSecurityContact', User::class, false],
            [BusinessProcess::class, 'processOwnerUser', User::class, false],
            [BusinessProcess::class, 'processOwnerPerson', Person::class, false],
            [BusinessContinuityPlan::class, 'planOwnerUser', User::class, false],
            [BusinessContinuityPlan::class, 'planOwnerPerson', Person::class, false],
            [Incident::class, 'reportedByUser', User::class, false],
            [Incident::class, 'reportedByPerson', Person::class, false],
            [ProcessingActivity::class, 'contactPersonUser', User::class, false],
            [ProcessingActivity::class, 'contactPerson', Person::class, false],
            [ProcessingActivity::class, 'dataProtectionOfficer', User::class, false],
            [ProcessingActivity::class, 'dataProtectionOfficerPerson', Person::class, false],
            // Fix round 2 (M-4 / M-5): Person collections + remaining Person / User FKs
            [ComplianceRequirementFulfillment::class, 'responsiblePersonUser', User::class, false],
            [ComplianceRequirementFulfillment::class, 'responsiblePerson', Person::class, false],
            [ComplianceRequirementFulfillment::class, 'responsibleDeputyPersons', Person::class, true],
            [Incident::class, 'responsiblePerson', Person::class, false],
            [Incident::class, 'reportedByDeputyPersons', Person::class, true],
            [PhysicalAccessLog::class, 'person', Person::class, false],
            [InternalAudit::class, 'auditTeamMembers', Person::class, true],
            [BCExercise::class, 'participantPersons', Person::class, true],
            [BCExercise::class, 'observerPersons', Person::class, true],
            [ManagementReview::class, 'personParticipants', Person::class, true],
            [ManagementReview::class, 'reviewedByDeputyPersons', Person::class, true],
            [CrisisTeam::class, 'personMembers', Person::class, true],
            [CrisisTeam::class, 'teamLeaderDeputyPersons', Person::class, true],
            [CrisisTeam::class, 'deputyLeaderDeputyPersons', Person::class, true],
            [Team::class, 'members', Person::class, true],
            [ISMSObjective::class, 'responsibleDeputyPersons', Person::class, true],
            [InterestedParty::class, 'contactDeputyPersons', Person::class, true],
            [Location::class, 'responsibleDeputyPersons', Person::class, true],
            [Patch::class, 'responsibleDeputyPersons', Person::class, true],
            [Vulnerability::class, 'responsibleDeputyPersons', Person::class, true],
            [Supplier::class, 'contactDeputyPersons', Person::class, true],
            [ProcessingActivity::class, 'contactDeputyPersons', Person::class, true],
            [ProcessingActivity::class, 'dataProtectionOfficerDeputyPersons', Person::class, true],
            [ThreatIntelligence::class, 'assignedDeputyPersons', Person::class, true],
            [DataBreach::class, 'assessorDeputyPersons', Person::class, true],
            [DataBreach::class, 'dataProtectionOfficerDeputyPersons', Person::class, true],
            [PrototypeProtectionAssessment::class, 'assessorDeputyPersons', Person::class, true],
            [DataProtectionImpactAssessment::class, 'dataProtectionOfficerDeputyPersons', Person::class, true],
            [DataProtectionImpactAssessment::class, 'approverDeputyPersons', Person::class, true],
            [DataProtectionImpactAssessment::class, 'conductorDeputyPersons', Person::class, true],
            [CorrectiveAction::class, 'responsibleDeputyPersons', Person::class, true],
            [FourEyesApprovalRequest::class, 'requestedApproverDeputyPersons', Person::class, true],
            [DataSubjectRequest::class, 'assignedDeputyPersons', Person::class, true],
            [Training::class, 'trainerDeputyPersons', Person::class, true],
            [RiskTreatmentPlan::class, 'responsibleDeputyPersons', Person::class, true],
            [AuditFinding::class, 'reportedByDeputyPersons', Person::class, true],
            [AuditFinding::class, 'assignedDeputyPersons', Person::class, true],
            [CustomReport::class, 'ownerDeputyPersons', Person::class, true],
            [Asset::class, 'ownerDeputyPersons', Person::class, true],
            [Risk::class, 'riskOwnerDeputyPersons', Person::class, true],
            [Control::class, 'responsibleDeputyPersons', Person::class, true],
            [BusinessProcess::class, 'processOwnerDeputyPersons', Person::class, true],
            [BusinessContinuityPlan::class, 'planOwnerDeputyPersons', Person::class, true],
        ];
        foreach ($rows as $row) {
            $short = substr((string) strrchr($row[0], '\\'), 1);
            yield $short . '.' . $row[1] => $row;
        }
    }

    private static function tenant(int $id): Tenant
    {
        $t = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($t, $id);

        return $t;
    }

    private static function validator(): ValidatorInterface
    {
        self::bootKernel();
        static::getContainer()->get(TenantContext::class)->setCurrentTenant(null);

        return static::getContainer()->get(ValidatorInterface::class);
    }

    /** @param class-string $class */
    private static function record(string $class, Tenant $tenant): object
    {
        $record = new $class();
        if (method_exists($record, 'setTenant')) {
            $record->setTenant($tenant);
        } else {
            $record->setTenantId($tenant->getId());
        }

        return $record;
    }

    /** @param class-string $relatedClass */
    private static function related(string $relatedClass, Tenant $tenant): object
    {
        $related = new $relatedClass();
        $related->setTenant($tenant);

        return $related;
    }

    private static function assign(object $record, string $property, object $related, bool $collection): void
    {
        $ref = new \ReflectionProperty($record, $property);
        $ref->setValue($record, $collection ? new ArrayCollection([$related]) : $related);
    }

    /** @return list<string> */
    private static function messages(object $record, string $property): array
    {
        $out = [];
        foreach (self::validator()->validateProperty($record, $property) as $violation) {
            $out[] = (string) $violation->getMessageTemplate();
        }

        return $out;
    }

    /**
     * @param class-string $class
     * @param class-string $relatedClass
     */
    #[Test]
    #[DataProvider('relationProvider')]
    public function relationCarriesTheConstraint(string $class, string $property, string $relatedClass, bool $collection): void
    {
        $attributes = (new \ReflectionProperty($class, $property))->getAttributes(SameTenantOwner::class);

        self::assertCount(1, $attributes, sprintf('%s::$%s lacks #[SameTenantOwner]', $class, $property));
    }

    /**
     * @param class-string $class
     * @param class-string $relatedClass
     */
    #[Test]
    #[DataProvider('relationProvider')]
    public function relatedRecordOfAnotherTenantIsRejected(string $class, string $property, string $relatedClass, bool $collection): void
    {
        $record = self::record($class, self::tenant(1));
        self::assign($record, $property, self::related($relatedClass, self::tenant(2)), $collection);

        self::assertContains(self::MESSAGE, self::messages($record, $property));
    }

    /**
     * @param class-string $class
     * @param class-string $relatedClass
     */
    #[Test]
    #[DataProvider('relationProvider')]
    public function relatedRecordOfTheSameTenantIsAccepted(string $class, string $property, string $relatedClass, bool $collection): void
    {
        $record = self::record($class, self::tenant(1));
        self::assign($record, $property, self::related($relatedClass, self::tenant(1)), $collection);

        self::assertNotContains(self::MESSAGE, self::messages($record, $property));
    }

    #[Test]
    public function collectionWithOneForeignElementAmongValidOnesIsRejectedOnce(): void
    {
        $review = self::record(ManagementReview::class, self::tenant(1));
        $own = self::related(User::class, self::tenant(1));
        $foreign = self::related(User::class, self::tenant(2));
        (new \ReflectionProperty($review, 'participants'))->setValue($review, new ArrayCollection([$own, $foreign, $foreign]));

        $messages = self::messages($review, 'participants');

        self::assertSame([self::MESSAGE], array_values(array_filter($messages, static fn (string $m): bool => $m === self::MESSAGE)));
    }

    #[Test]
    public function emptyCollectionAndNullAreAccepted(): void
    {
        $review = self::record(ManagementReview::class, self::tenant(1));
        (new \ReflectionProperty($review, 'participants'))->setValue($review, new ArrayCollection());
        self::assertNotContains(self::MESSAGE, self::messages($review, 'participants'));

        $team = self::record(CrisisTeam::class, self::tenant(1));
        self::assertNotContains(self::MESSAGE, self::messages($team, 'teamLeader'));
    }

    #[Test]
    public function userWithoutTenantIsRejectedForATenantRecord(): void
    {
        $team = self::record(CrisisTeam::class, self::tenant(1));
        $team->setTeamLeader(new User());

        self::assertContains(self::MESSAGE, self::messages($team, 'teamLeader'));
    }

    #[Test]
    public function recordWithoutTenantFallsBackToTheCurrentTenant(): void
    {
        $validator = self::validator();
        static::getContainer()->get(TenantContext::class)->setCurrentTenant(self::tenant(1));

        $team = new CrisisTeam();
        $team->setTeamLeader(self::related(User::class, self::tenant(2)));
        $templates = array_map(
            static fn ($v): string => (string) $v->getMessageTemplate(),
            iterator_to_array($validator->validateProperty($team, 'teamLeader')),
        );

        self::assertContains(self::MESSAGE, $templates);
    }
}
