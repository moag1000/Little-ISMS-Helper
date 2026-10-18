<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Asset;
use App\Entity\Authority\DoraRegisterOfInformation;
use App\Entity\Authority\Nis2RegistrationProfile;
use App\Entity\Bsi2004ExerciseLog;
use App\Entity\Control;
use App\Entity\Document;
use App\Entity\FulfillmentInheritanceLog;
use App\Entity\Incident;
use App\Entity\Notification\NotificationChannel;
use App\Entity\Notification\NotificationRule;
use App\Entity\Risk;
use App\Entity\RiskIncidentLink;
use App\Entity\Supplier;
use App\Entity\Tag;
use App\Entity\Tenant;
use App\Entity\Training;
use App\Entity\User;
use App\Security\Voter\ApiTenantVoter;
use App\Security\Voter\AssetVoter;
use App\Security\Voter\Authority\DoraRoiVoter;
use App\Security\Voter\Authority\Nis2RegistrationProfileVoter;
use App\Security\Voter\Bsi2004ExerciseLogVoter;
use App\Security\Voter\ComplianceInheritanceVoter;
use App\Security\Voter\ControlVoter;
use App\Security\Voter\DocumentVoter;
use App\Security\Voter\EntityVoter;
use App\Security\Voter\IncidentVoter;
use App\Security\Voter\Notification\NotificationChannelVoter;
use App\Security\Voter\Notification\NotificationRuleVoter;
use App\Security\Voter\RiskIncidentLinkVoter;
use App\Security\Voter\RiskVoter;
use App\Security\Voter\TagVoter;
use App\Security\Voter\TrainingVoter;
use App\Security\Voter\UserVoter;
use App\Service\InitialAdminService;
use App\Service\ModuleConfigurationService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Regression matrix for the shared tenant-scope guard
 * ({@see \App\Security\Voter\HoldingTreeAccessTrait::decideTenantScope()}).
 *
 * ROLE_ADMIN is per organisation; only ROLE_SUPER_ADMIN is instance-wide.
 * Before the fix every voter below returned true for ROLE_ADMIN (or had no
 * tenant check at all), so an organisation admin could act on records of
 * any other tenant.
 */
#[AllowMockObjectsWithoutExpectations]
final class TenantScopeVoterMatrixTest extends TestCase
{
    private Tenant $holding;
    private Tenant $subsidiary;
    private Tenant $foreign;

    protected function setUp(): void
    {
        $this->holding = $this->tenant(1);
        $this->subsidiary = $this->tenant(2);
        $this->subsidiary->setParent($this->holding);
        $this->foreign = $this->tenant(3);
    }

    /**
     * voter key => [subject class, read attribute|null, write attribute].
     *
     * @return array<string, array{string, ?string, string}>
     */
    public static function voters(): array
    {
        return [
            'RiskVoter' => [Risk::class, RiskVoter::VIEW, RiskVoter::EDIT],
            'AssetVoter' => [Asset::class, AssetVoter::VIEW, AssetVoter::EDIT],
            'ControlVoter' => [Control::class, ControlVoter::VIEW, ControlVoter::DELETE],
            'IncidentVoter' => [Incident::class, IncidentVoter::VIEW, IncidentVoter::EDIT],
            'DocumentVoter' => [Document::class, DocumentVoter::VIEW, DocumentVoter::DELETE],
            'TrainingVoter' => [Training::class, TrainingVoter::VIEW, TrainingVoter::EDIT],
            'ApiTenantVoter' => [Supplier::class, ApiTenantVoter::API_VIEW, ApiTenantVoter::API_DELETE],
            'Bsi2004ExerciseLogVoter' => [
                Bsi2004ExerciseLog::class, Bsi2004ExerciseLogVoter::VIEW, Bsi2004ExerciseLogVoter::EDIT,
            ],
            'RiskIncidentLinkVoter' => [
                RiskIncidentLink::class, RiskIncidentLinkVoter::VIEW, RiskIncidentLinkVoter::DELETE,
            ],
            'UserVoter' => [User::class, UserVoter::VIEW, UserVoter::EDIT],
            'NotificationChannelVoter' => [
                NotificationChannel::class, NotificationChannelVoter::VIEW, NotificationChannelVoter::EDIT,
            ],
            'NotificationRuleVoter' => [
                NotificationRule::class, NotificationRuleVoter::VIEW, NotificationRuleVoter::DELETE,
            ],
            'EntityVoter' => [Supplier::class, EntityVoter::VIEW, EntityVoter::EDIT],
            'DoraRoiVoter' => [DoraRegisterOfInformation::class, DoraRoiVoter::VIEW, DoraRoiVoter::MARK_SUBMITTED],
            'Nis2RegistrationProfileVoter' => [
                Nis2RegistrationProfile::class,
                Nis2RegistrationProfileVoter::VIEW,
                Nis2RegistrationProfileVoter::MARK_REPORTED,
            ],
            'ComplianceInheritanceVoter' => [
                FulfillmentInheritanceLog::class, ComplianceInheritanceVoter::VIEW, ComplianceInheritanceVoter::CONFIRM,
            ],
            'TagVoter' => [Tag::class, null, TagVoter::TAG_MANAGE],
        ];
    }

    #[Test]
    #[DataProvider('voters')]
    public function crossTenantAdminIsDenied(string $subjectClass, ?string $read, string $write): void
    {
        $voter = $this->voter($this->dataName());
        $admin = $this->actor(['ROLE_ADMIN'], $this->foreign);
        $subject = $this->subject($subjectClass, $this->subsidiary);

        foreach (array_filter([$read, $write]) as $attribute) {
            self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, $admin, $subject, $attribute), $attribute);
        }
    }

    #[Test]
    #[DataProvider('voters')]
    public function sameTenantAdminIsGranted(string $subjectClass, ?string $read, string $write): void
    {
        $voter = $this->voter($this->dataName());
        $admin = $this->actor(['ROLE_ADMIN'], $this->subsidiary);
        $subject = $this->subject($subjectClass, $this->subsidiary);

        foreach (array_filter([$read, $write]) as $attribute) {
            self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($voter, $admin, $subject, $attribute), $attribute);
        }
    }

    #[Test]
    #[DataProvider('voters')]
    public function superAdminIsGrantedEverywhere(string $subjectClass, ?string $read, string $write): void
    {
        $voter = $this->voter($this->dataName());
        $superAdmin = $this->actor(['ROLE_SUPER_ADMIN'], null);
        $subject = $this->subject($subjectClass, $this->foreign);

        foreach (array_filter([$read, $write]) as $attribute) {
            self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($voter, $superAdmin, $subject, $attribute), $attribute);
        }
    }

    #[Test]
    #[DataProvider('voters')]
    public function holdingRolesReadDescendantsButNeverWrite(string $subjectClass, ?string $read, string $write): void
    {
        $voter = $this->voter($this->dataName());
        $subject = $this->subject($subjectClass, $this->subsidiary);

        foreach (['ROLE_GROUP_CISO', 'ROLE_KONZERN_AUDITOR'] as $holdingRole) {
            // The holding user is even ROLE_ADMIN of the parent — still no write below.
            $holdingUser = $this->actor(['ROLE_ADMIN', $holdingRole], $this->holding);
            if ($read !== null) {
                self::assertSame(
                    VoterInterface::ACCESS_GRANTED,
                    $this->vote($voter, $holdingUser, $subject, $read),
                    $holdingRole . ' ' . $read,
                );
            }
            self::assertSame(
                VoterInterface::ACCESS_DENIED,
                $this->vote($voter, $holdingUser, $subject, $write),
                $holdingRole . ' ' . $write,
            );
        }

        // Holding read never goes up or sideways.
        $subsidiaryAuditor = $this->actor(['ROLE_ADMIN', 'ROLE_KONZERN_AUDITOR'], $this->subsidiary);
        $parentRecord = $this->subject($subjectClass, $this->holding);
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote($voter, $subsidiaryAuditor, $parentRecord, $read ?? $write),
        );
    }

    #[Test]
    #[DataProvider('voters')]
    public function adminWithoutTenantIsDenied(string $subjectClass, ?string $read, string $write): void
    {
        $voter = $this->voter($this->dataName());
        $admin = $this->actor(['ROLE_ADMIN'], null);
        $subject = $this->subject($subjectClass, $this->subsidiary);

        foreach (array_filter([$read, $write]) as $attribute) {
            self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, $admin, $subject, $attribute), $attribute);
        }
    }

    #[Test]
    public function confidentialIncidentStaysHiddenFromHoldingAdmin(): void
    {
        $voter = $this->voter('IncidentVoter');
        $incident = $this->createMock(Incident::class);
        $incident->method('getTenant')->willReturn($this->subsidiary);
        $incident->method('isVisibleToHolding')->willReturn(false);

        $holdingAdmin = $this->actor(['ROLE_ADMIN', 'ROLE_GROUP_CISO'], $this->holding);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, $holdingAdmin, $incident, IncidentVoter::VIEW));
    }

    #[Test]
    public function globalTagIsUsableByEveryTenantButManagedOnlyBySuperAdmin(): void
    {
        $voter = $this->voter('TagVoter');
        $globalTag = $this->subject(Tag::class, null);

        $manager = $this->actor(['ROLE_MANAGER'], $this->foreign);
        $admin = $this->actor(['ROLE_ADMIN'], $this->foreign);
        $superAdmin = $this->actor(['ROLE_SUPER_ADMIN'], null);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($voter, $manager, $globalTag, TagVoter::TAG_APPLY));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, $admin, $globalTag, TagVoter::TAG_MANAGE));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($voter, $superAdmin, $globalTag, TagVoter::TAG_MANAGE));

        // A foreign tenant's tag cannot even be applied.
        $foreignTag = $this->subject(Tag::class, $this->subsidiary);
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, $manager, $foreignTag, TagVoter::TAG_APPLY));
    }

    #[Test]
    public function notificationVoterNoLongerMatchesNullAgainstNull(): void
    {
        $voter = $this->voter('NotificationRuleVoter');
        $rule = $this->subject(NotificationRule::class, null);
        $manager = $this->actor(['ROLE_MANAGER'], null);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, $manager, $rule, NotificationRuleVoter::VIEW));
    }

    private function voter(string $key): VoterInterface
    {
        $hierarchy = VoterTestHelper::createRoleHierarchy();
        $modules = $this->createMock(ModuleConfigurationService::class);
        $modules->method('isModuleActive')->willReturn(true);

        return match ($key) {
            'RiskVoter' => new RiskVoter($hierarchy),
            'AssetVoter' => new AssetVoter($hierarchy),
            'ControlVoter' => new ControlVoter($hierarchy),
            'IncidentVoter' => new IncidentVoter($hierarchy),
            'DocumentVoter' => new DocumentVoter($hierarchy),
            'TrainingVoter' => new TrainingVoter($hierarchy),
            'ApiTenantVoter' => new ApiTenantVoter($hierarchy),
            'Bsi2004ExerciseLogVoter' => new Bsi2004ExerciseLogVoter($hierarchy),
            'RiskIncidentLinkVoter' => new RiskIncidentLinkVoter($hierarchy),
            'UserVoter' => new UserVoter($this->createMock(InitialAdminService::class), $hierarchy),
            'NotificationChannelVoter' => new NotificationChannelVoter($modules, $hierarchy),
            'NotificationRuleVoter' => new NotificationRuleVoter($modules, $hierarchy),
            'EntityVoter' => new EntityVoter($hierarchy),
            'DoraRoiVoter' => new DoraRoiVoter($hierarchy),
            'Nis2RegistrationProfileVoter' => new Nis2RegistrationProfileVoter($hierarchy),
            'ComplianceInheritanceVoter' => new ComplianceInheritanceVoter($hierarchy),
            'TagVoter' => new TagVoter($hierarchy),
        };
    }

    private function vote(VoterInterface $voter, User $user, object $subject, string $attribute): int
    {
        return $voter->vote(new UsernamePasswordToken($user, 'main', $user->getRoles()), $subject, [$attribute]);
    }

    /**
     * @param list<string> $roles
     */
    private function actor(array $roles, ?Tenant $tenant): User
    {
        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn($roles);
        $user->method('getTenant')->willReturn($tenant);
        $user->method('isActive')->willReturn(true);
        $user->method('getUserIdentifier')->willReturn('actor@example.com');

        return $user;
    }

    /**
     * @param class-string $class
     */
    private function subject(string $class, ?Tenant $tenant): object
    {
        $subject = $this->createMock($class);
        $subject->method('getTenant')->willReturn($tenant);
        if ($subject instanceof Incident) {
            $subject->method('isVisibleToHolding')->willReturn(true);
        }
        if ($subject instanceof User) {
            $subject->method('getId')->willReturn(999);
            $subject->method('isActive')->willReturn(true);
        }

        return $subject;
    }

    private function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        new ReflectionProperty(Tenant::class, 'id')->setValue($tenant, $id);

        return $tenant;
    }
}
