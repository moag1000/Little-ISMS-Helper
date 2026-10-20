<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\ActionItem;
use App\Entity\Asset;
use App\Entity\AuditFinding;
use App\Entity\AuditProgram;
use App\Entity\Authority\Nis2RegistrationProfile;
use App\Entity\BCExercise;
use App\Entity\Control;
use App\Entity\CorrectiveAction;
use App\Entity\CrisisTeam;
use App\Entity\CustomReport;
use App\Entity\DataBreach;
use App\Entity\DataProtectionImpactAssessment;
use App\Entity\DataSubjectRequest;
use App\Entity\Document;
use App\Entity\FourEyesApprovalRequest;
use App\Entity\InternalAudit;
use App\Entity\ManagementReview;
use App\Entity\Person;
use App\Entity\PrototypeProtectionAssessment;
use App\Entity\Risk;
use App\Entity\RiskAppetite;
use App\Entity\RiskTreatmentPlan;
use App\Entity\Team;
use App\Entity\Tenant;
use App\Entity\ThreatIntelligence;
use App\Entity\Training;
use App\Entity\User;
use App\Entity\WorkflowInstance;
use App\Form\ActionItemType;
use App\Form\AssetQuickType;
use App\Form\AuditFindingType;
use App\Form\AuditProgramType;
use App\Form\Authority\Nis2RegistrationProfileType;
use App\Form\BCExerciseType;
use App\Form\ControlType;
use App\Form\CorrectiveActionType;
use App\Form\CrisisTeamType;
use App\Form\CustomReportType;
use App\Form\DataBreachType;
use App\Form\DataProtectionImpactAssessmentType;
use App\Form\DataSubjectRequestType;
use App\Form\DocumentType;
use App\Form\FourEyesApprovalRequestType;
use App\Form\InternalAuditType;
use App\Form\ManagementReviewType;
use App\Form\PersonType;
use App\Form\PrototypeProtectionAssessmentType;
use App\Form\RiskAppetiteType;
use App\Form\RiskQuickType;
use App\Form\RiskTreatmentPlanType;
use App\Form\RiskType;
use App\Form\TeamType;
use App\Form\ThreatIntelligenceType;
use App\Form\TrainingType;
use App\Form\Type\OwnerUserType;
use App\Form\WorkflowInstanceType;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;

/**
 * User-picker tenant sweep: every User choice field of a tenant record offers
 * only users of the RECORD's organisation and rejects a tampered submit of a
 * user of another organisation (OwnerUserType / TenantUserChoiceLoader).
 */
final class UserPickerTenantScopeTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FormFactoryInterface $factory;
    private Tenant $tenantA;
    private Tenant $tenantB;
    private User $userA;
    private User $userB;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->factory = static::getContainer()->get(FormFactoryInterface::class);
        static::getContainer()->get(TenantContext::class)->setCurrentTenant(null);

        $uid = str_replace('.', '', uniqid('ups', true));
        $this->tenantA = (new Tenant())->setName('UPS A ' . $uid)->setCode('upsa_' . $uid);
        $this->tenantB = (new Tenant())->setName('UPS B ' . $uid)->setCode('upsb_' . $uid);
        $this->em->persist($this->tenantA);
        $this->em->persist($this->tenantB);
        $this->userA = $this->makeUser('alice_' . $uid, $this->tenantA);
        $this->userB = $this->makeUser('bob_' . $uid, $this->tenantB);
        $this->em->flush();
    }

    private function makeUser(string $name, ?Tenant $tenant): User
    {
        $user = (new User())
            ->setEmail($name . '@example.com')
            ->setFirstName('First')
            ->setLastName($name)
            ->setRoles(['ROLE_USER'])
            ->setPassword('hashed_password')
            ->setTenant($tenant)
            ->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }

    /**
     * @return iterable<string, array{class-string, class-string, string, bool}>
     *     [FormType, entity, user field, multiple]
     */
    public static function siteProvider(): iterable
    {
        yield 'Training participants' => [TrainingType::class, Training::class, 'participantUsers', true];
        yield 'Training trainer (owner picker)' => [TrainingType::class, Training::class, 'trainerUser', false];
        yield 'WorkflowInstance initiatedBy' => [WorkflowInstanceType::class, WorkflowInstance::class, 'initiatedBy', false];
        yield 'PrototypeProtection assessor' => [PrototypeProtectionAssessmentType::class, PrototypeProtectionAssessment::class, 'assessor', false];
        yield 'AuditFinding ncVerifiedBy' => [AuditFindingType::class, AuditFinding::class, 'ncVerifiedBy', false];
        yield 'AuditFinding assignedTo (owner picker)' => [AuditFindingType::class, AuditFinding::class, 'assignedTo', false];
        yield 'RiskTreatmentPlan responsible' => [RiskTreatmentPlanType::class, RiskTreatmentPlan::class, 'responsiblePersonUser', false];
        yield 'CustomReport owner' => [CustomReportType::class, CustomReport::class, 'owner', false];
        yield 'DPIA DPO' => [DataProtectionImpactAssessmentType::class, DataProtectionImpactAssessment::class, 'dataProtectionOfficer', false];
        yield 'DPIA conductor' => [DataProtectionImpactAssessmentType::class, DataProtectionImpactAssessment::class, 'conductor', false];
        yield 'Risk acceptance approver' => [RiskType::class, Risk::class, 'acceptanceApprovedByUser', false];
        yield 'Risk decision approver' => [RiskType::class, Risk::class, 'decisionApprovedByUser', false];
        yield 'Risk owner (owner picker)' => [RiskType::class, Risk::class, 'riskOwner', false];
        yield 'Person linked user' => [PersonType::class, Person::class, 'linkedUser', false];
        yield 'RiskQuick owner' => [RiskQuickType::class, Risk::class, 'riskOwner', false];
        yield 'FourEyes approver' => [FourEyesApprovalRequestType::class, FourEyesApprovalRequest::class, 'requestedApprover', false];
        yield 'Team lead' => [TeamType::class, Team::class, 'teamLead', false];
        yield 'AssetQuick owner' => [AssetQuickType::class, Asset::class, 'ownerUser', false];
        yield 'DSR assignee' => [DataSubjectRequestType::class, DataSubjectRequest::class, 'assignedTo', false];
        yield 'Document acknowledgement audience' => [DocumentType::class, Document::class, 'acknowledgementAudience', true];
        yield 'RiskAppetite approver' => [RiskAppetiteType::class, RiskAppetite::class, 'approvedBy', false];
        yield 'ActionItem responsible' => [ActionItemType::class, ActionItem::class, 'responsibleUser', false];
        yield 'CorrectiveAction responsible' => [CorrectiveActionType::class, CorrectiveAction::class, 'responsiblePersonUser', false];
        yield 'InternalAudit lead auditor' => [InternalAuditType::class, InternalAudit::class, 'leadAuditorUser', false];
        yield 'DataBreach DPO' => [DataBreachType::class, DataBreach::class, 'dataProtectionOfficer', false];
        yield 'ThreatIntelligence assignee' => [ThreatIntelligenceType::class, ThreatIntelligence::class, 'assignedTo', false];
        yield 'Control responsible' => [ControlType::class, Control::class, 'responsiblePersonUser', false];
        yield 'CrisisTeam leader' => [CrisisTeamType::class, CrisisTeam::class, 'teamLeader', false];
        yield 'CrisisTeam deputy leader' => [CrisisTeamType::class, CrisisTeam::class, 'deputyLeader', false];
        yield 'ManagementReview reviewedBy' => [ManagementReviewType::class, ManagementReview::class, 'reviewedBy', false];
        yield 'ManagementReview participants' => [ManagementReviewType::class, ManagementReview::class, 'participants', true];
        yield 'BCExercise facilitator' => [BCExerciseType::class, BCExercise::class, 'facilitatorUser', false];
        yield 'BCExercise exercise leader' => [BCExerciseType::class, BCExercise::class, 'exerciseLeaderUser', false];
        yield 'AuditProgram owner' => [AuditProgramType::class, AuditProgram::class, 'programmeOwner', false];
        yield 'NIS2 incident contact' => [Nis2RegistrationProfileType::class, Nis2RegistrationProfile::class, 'incidentReportingContact', false];
        yield 'NIS2 security contact' => [Nis2RegistrationProfileType::class, Nis2RegistrationProfile::class, 'securityResponsibleContact', false];
        yield 'NIS2 backup contact' => [Nis2RegistrationProfileType::class, Nis2RegistrationProfile::class, 'backupSecurityContact', false];
    }

    /** @param class-string $entityClass */
    private function record(string $entityClass, ?Tenant $tenant): object
    {
        $record = new $entityClass();
        if (method_exists($record, 'setTenant')) {
            $record->setTenant($tenant);
        } else {
            $record->setTenantId($tenant?->getId()); // CustomReport keeps a plain tenant-id column
        }

        return $record;
    }

    /** @return list<string> */
    private static function choiceValues(FormView $view): array
    {
        $values = [];
        foreach ($view->vars['choices'] as $choice) {
            if (isset($choice->choices)) {
                foreach ($choice->choices as $nested) {
                    $values[] = (string) $nested->value;
                }
                continue;
            }
            $values[] = (string) $choice->value;
        }

        return $values;
    }

    private function field(FormInterface $form, string $field): FormInterface
    {
        self::assertTrue($form->has($field), sprintf('field "%s" not present (module gating in the test env?)', $field));

        return $form->get($field);
    }

    /**
     * @param class-string $type
     * @param class-string $entityClass
     */
    #[Test]
    #[DataProvider('siteProvider')]
    public function onlyUsersOfTheRecordTenantAreOffered(string $type, string $entityClass, string $field, bool $multiple): void
    {
        $form = $this->factory->create($type, $this->record($entityClass, $this->tenantA), ['csrf_protection' => false]);
        $child = $this->field($form, $field);

        self::assertInstanceOf(OwnerUserType::class, $child->getConfig()->getType()->getInnerType());
        self::assertSame($multiple, (bool) $child->getConfig()->getOption('multiple'));

        $values = self::choiceValues($form->createView()[$field]);
        self::assertContains((string) $this->userA->getId(), $values, 'user of the record tenant is offered');
        self::assertNotContains((string) $this->userB->getId(), $values, 'user of another tenant leaked into the picker');
    }

    /**
     * @param class-string $type
     * @param class-string $entityClass
     */
    #[Test]
    #[DataProvider('siteProvider')]
    public function tamperedSubmitOfForeignUserIsRejected(string $type, string $entityClass, string $field, bool $multiple): void
    {
        $record = $this->record($entityClass, $this->tenantA);
        $form = $this->factory->create($type, $record, ['csrf_protection' => false]);
        $foreignId = (string) $this->userB->getId();

        $form->submit([$field => $multiple ? [$foreignId] : $foreignId], false);

        $child = $this->field($form, $field);
        self::assertFalse($child->isValid(), 'foreign user must be an invalid choice');
        $value = $child->getData();
        $stored = is_iterable($value) ? iterator_to_array($value) : [$value];
        foreach ($stored as $user) {
            self::assertNotSame($this->userB, $user, 'foreign user reached the record');
        }
    }

    /**
     * @param class-string $type
     * @param class-string $entityClass
     */
    #[Test]
    #[DataProvider('siteProvider')]
    public function userOfTheRecordTenantIsAccepted(string $type, string $entityClass, string $field, bool $multiple): void
    {
        $form = $this->factory->create($type, $this->record($entityClass, $this->tenantA), ['csrf_protection' => false]);
        $ownId = (string) $this->userA->getId();

        $form->submit([$field => $multiple ? [$ownId] : $ownId], false);

        $child = $this->field($form, $field);
        self::assertNull($child->getTransformationFailure());
        $value = $child->getData();
        $stored = is_iterable($value) ? array_values(iterator_to_array($value)) : [$value];
        self::assertSame([$this->userA->getId()], array_map(static fn (User $u): ?int => $u->getId(), $stored));
    }

    #[Test]
    public function newRecordWithoutTenantFallsBackToTheCurrentTenant(): void
    {
        static::getContainer()->get(TenantContext::class)->setCurrentTenant($this->tenantB);

        $form = $this->factory->create(CrisisTeamType::class, new CrisisTeam(), ['csrf_protection' => false]);
        $values = self::choiceValues($form->createView()['teamLeader']);

        self::assertContains((string) $this->userB->getId(), $values);
        self::assertNotContains((string) $this->userA->getId(), $values);
    }

    #[Test]
    public function withoutAnyTenantTheListIsEmpty(): void
    {
        $form = $this->factory->create(CrisisTeamType::class, new CrisisTeam(), ['csrf_protection' => false]);

        self::assertSame([], self::choiceValues($form->createView()['teamLeader']));
    }

    #[Test]
    public function recordTenantWinsOverTheCurrentTenant(): void
    {
        // e.g. a super-admin whose context points elsewhere edits a tenant-A record
        static::getContainer()->get(TenantContext::class)->setCurrentTenant($this->tenantB);

        $form = $this->factory->create(CrisisTeamType::class, $this->record(CrisisTeam::class, $this->tenantA), ['csrf_protection' => false]);
        $values = self::choiceValues($form->createView()['teamLeader']);

        self::assertContains((string) $this->userA->getId(), $values);
        self::assertNotContains((string) $this->userB->getId(), $values);
    }

    #[Test]
    public function explicitTenantOptionPinsTheScope(): void
    {
        $form = $this->factory->createBuilder(FormType::class, $this->record(CrisisTeam::class, $this->tenantA), [
            'data_class' => CrisisTeam::class,
            'csrf_protection' => false,
        ])->add('teamLeader', OwnerUserType::class, ['tenant' => $this->tenantB, 'required' => false, 'choice_label' => 'email'])->getForm();
        $values = self::choiceValues($form->createView()['teamLeader']);

        self::assertSame([(string) $this->userB->getId()], $values);
    }

    #[Test]
    public function existingOwnerOfTheRecordTenantStaysSelectedOnEdit(): void
    {
        $team = $this->record(CrisisTeam::class, $this->tenantA);
        $team->setTeamLeader($this->userA);

        $form = $this->factory->create(CrisisTeamType::class, $team, ['csrf_protection' => false]);

        self::assertSame((string) $this->userA->getId(), $form->createView()['teamLeader']->vars['value']);
    }

    #[Test]
    public function scopingCannotBeSwitchedOffByOptions(): void
    {
        foreach ([
            'query_builder' => static fn ($repo) => $repo->createQueryBuilder('u'),
            'choice_loader' => new \Symfony\Component\Form\ChoiceList\Loader\CallbackChoiceLoader(
                static fn (): array => [],
            ),
            'choice_lazy' => true,
        ] as $option => $value) {
            try {
                $this->factory->createBuilder(FormType::class, null, ['csrf_protection' => false])
                    ->add('u', OwnerUserType::class, [$option => $value, 'choice_label' => 'email'])
                    ->getForm();
                self::fail(sprintf('option "%s" was accepted', $option));
            } catch (\Symfony\Component\OptionsResolver\Exception\InvalidOptionsException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function fieldsOfOneFormShareTheOrganisationUserList(): void
    {
        $directory = static::getContainer()->get(\App\Form\ChoiceList\TenantUserDirectory::class);
        $first = $directory->usersOf($this->tenantA);

        self::assertSame($first, $directory->usersOf($this->tenantA), 'second lookup must hit the cache');
        self::assertSame([$this->userA->getId()], array_map(static fn (User $u): ?int => $u->getId(), $first));

        // Two user fields of one form render the same (shared) list.
        $form = $this->factory->create(
            CrisisTeamType::class,
            $this->record(CrisisTeam::class, $this->tenantA),
            ['csrf_protection' => false],
        );
        $view = $form->createView();
        self::assertSame(self::choiceValues($view['teamLeader']), self::choiceValues($view['deputyLeader']));

        // A cleared EntityManager detaches the cached users → reloaded, managed again.
        $this->em->clear();
        $reloaded = $directory->usersOf($this->tenantA->getId());
        self::assertTrue($this->em->contains($reloaded[0]));

        $directory->reset();
        self::assertSame([], (fn (): array => $this->byTenant)->call($directory), 'reset() empties the cache');
    }
}
