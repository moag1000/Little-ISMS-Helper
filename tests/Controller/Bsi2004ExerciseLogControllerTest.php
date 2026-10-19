<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\BCExercise;
use App\Entity\Bsi2004ExerciseLog;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Smoke tests for Bsi2004ExerciseLogController.
 *
 * Covers: index, new, show, edit, calendar routes with proper auth.
 */
#[AllowMockObjectsWithoutExpectations]
class Bsi2004ExerciseLogControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    private ?User $manager  = null;
    private ?User $auditor  = null;
    private ?BCExercise $exercise = null;
    private ?Bsi2004ExerciseLog $log = null;
    private ?Tenant $foreignTenant = null;
    private ?User $foreignAdmin = null;
    private ?BCExercise $foreignExercise = null;
    /** @var list<User> */
    private array $superAdmins = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();

        $moduleService = $this->createMock(ModuleConfigurationService::class);
        $moduleService->method('isModuleActive')->willReturn(true);
        $container->set(ModuleConfigurationService::class, $moduleService);

        $this->em = $container->get(EntityManagerInterface::class);

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->log) {
                $log = $this->em->find(Bsi2004ExerciseLog::class, $this->log->getId());
                if ($log) {
                    $this->em->remove($log);
                }
            }
            if ($this->foreignExercise) {
                $fx = $this->em->find(BCExercise::class, $this->foreignExercise->getId());
                if ($fx) {
                    $this->em->remove($fx);
                }
            }
            if ($this->exercise) {
                $ex = $this->em->find(BCExercise::class, $this->exercise->getId());
                if ($ex) {
                    $this->em->remove($ex);
                }
            }
            foreach ([$this->manager, $this->auditor, $this->foreignAdmin, ...$this->superAdmins] as $u) {
                if ($u) {
                    $user = $this->em->find(User::class, $u->getId());
                    if ($user) {
                        $this->em->remove($user);
                    }
                }
            }
            foreach ([$this->tenant, $this->foreignTenant] as $tenant) {
                $t = $tenant ? $this->em->find(Tenant::class, $tenant->getId()) : null;
                if ($t) {
                    $this->em->remove($t);
                }
            }
            $this->em->flush();
        } catch (\Throwable) {
        }

        parent::tearDown();
    }

    // -------------------------------------------------------------------------

    #[Test]
    public function indexRequiresAuth(): void
    {
        $this->client->request('GET', '/de/bcm/exercise-log');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function indexRendersForManager(): void
    {
        $this->loginAs($this->manager);
        $this->client->request('GET', '/de/bcm/exercise-log');
        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function newPageRendersForManager(): void
    {
        $this->loginAs($this->manager);
        $this->client->request('GET', '/de/bcm/exercise-log/new/' . $this->exercise->getId());
        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function newPageReturns404ForUnknownExercise(): void
    {
        $this->loginAs($this->manager);
        $this->client->request('GET', '/de/bcm/exercise-log/new/999999');
        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function showRendersForManager(): void
    {
        $this->loginAs($this->manager);
        $this->client->request('GET', '/de/bcm/exercise-log/' . $this->log->getId());
        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function editRendersForManager(): void
    {
        $this->loginAs($this->manager);
        $this->client->request('GET', '/de/bcm/exercise-log/' . $this->log->getId() . '/edit');
        $this->assertResponseIsSuccessful();
    }

    /**
     * ROLE_ADMIN of another organisation used to pass the voter (admin
     * bypass), and new() loaded any tenant's exercise by id.
     */
    #[Test]
    public function foreignAdminCannotReachLogOrExerciseOfAnotherTenant(): void
    {
        $this->loginAs($this->foreignAdmin);

        $this->client->request('GET', '/de/bcm/exercise-log/new/' . $this->exercise->getId());
        $this->assertResponseStatusCodeSame(403);

        // The tenant Doctrine filter may already hide the log (404); either
        // way it must not be reachable.
        foreach (['', '/edit'] as $suffix) {
            $this->client->request('GET', '/de/bcm/exercise-log/' . $this->log->getId() . $suffix);
            $this->assertContains($this->client->getResponse()->getStatusCode(), [403, 404], 'log' . $suffix);
        }
    }

    /**
     * new() took the scope from TenantContext (own or default organisation),
     * so a SUPER_ADMIN got 403 for any other organisation's exercise although
     * Bsi2004ExerciseLogVoter grants the instance operator global access.
     */
    #[Test]
    public function superAdminCanCreateLogForAnotherOrganisationsExercise(): void
    {
        $this->foreignExercise = $this->makeExercise('Foreign Log Target ' . uniqid(), $this->foreignTenant);
        $this->em->flush();

        // Without an organisation the operator works in the default (oldest
        // root) organisation, never in this freshly created one. (A
        // SUPER_ADMIN with an organisation is scoped to it by the tenant
        // Doctrine filter and gets 404 before the controller runs.)
        $superAdmin = $this->makeUser('bsi_super_' . uniqid(), ['ROLE_SUPER_ADMIN']);
        $superAdmin->setTenant(null);
        $this->em->flush();
        $this->superAdmins = [$superAdmin];

        $this->loginAs($superAdmin);
        $this->client->request('GET', '/de/bcm/exercise-log/new/' . $this->foreignExercise->getId());
        $this->assertResponseIsSuccessful();
    }

    /**
     * An exercise without an organisation would create an orphan log that
     * no voter can scope: fail closed, even for the instance operator.
     */
    #[Test]
    public function newIsDeniedForAnExerciseWithoutOrganisation(): void
    {
        $this->foreignExercise = $this->makeExercise('Orphan Exercise ' . uniqid(), $this->foreignTenant);
        $this->foreignExercise->setTenant(null);
        $superAdmin = $this->makeUser('bsi_super_orphan_' . uniqid(), ['ROLE_SUPER_ADMIN']);
        $superAdmin->setTenant(null);
        $this->em->flush();
        $this->superAdmins = [$superAdmin];

        $this->loginAs($superAdmin);
        $this->client->request('GET', '/de/bcm/exercise-log/new/' . $this->foreignExercise->getId());
        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * Same rule as Bsi2004ExerciseLogVoter::EDIT: a manager stays inside the
     * own organisation, even for an exercise of a subsidiary.
     */
    #[Test]
    public function managerCannotCreateLogForASubsidiarysExercise(): void
    {
        $this->foreignTenant->setParent($this->tenant);
        $this->foreignExercise = $this->makeExercise('Subsidiary Log Target ' . uniqid(), $this->foreignTenant);
        $this->em->flush();

        $this->loginAs($this->manager);
        $this->client->request('GET', '/de/bcm/exercise-log/new/' . $this->foreignExercise->getId());
        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * The calendar used to list every tenant's upcoming exercises
     * (findUpcoming() had no tenant parameter).
     */
    #[Test]
    public function calendarListsOnlyTheOwnTenantsExercises(): void
    {
        $this->foreignExercise = new BCExercise();
        $this->foreignExercise->setName('Foreign Upcoming ' . uniqid());
        $this->foreignExercise->setExerciseType('tabletop');
        $this->foreignExercise->setScope('Foreign scope');
        $this->foreignExercise->setObjectives('Objective');
        $this->foreignExercise->setParticipants('Eve');
        $this->foreignExercise->setFacilitator('Mallory');
        $this->foreignExercise->setStatus('planned');
        $this->foreignExercise->setExerciseDate(new \DateTime('+10 days'));
        $this->foreignExercise->setTenant($this->foreignTenant);
        $this->em->persist($this->foreignExercise);
        $this->em->flush();

        $this->loginAs($this->manager);
        $this->client->request('GET', '/de/bcm/exercise-log/calendar');

        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString(
            (string) $this->foreignExercise->getName(),
            (string) $this->client->getResponse()->getContent(),
        );
    }

    #[Test]
    public function calendarRendersForManager(): void
    {
        $this->loginAs($this->manager);
        $this->client->request('GET', '/de/bcm/exercise-log/calendar');
        $this->assertResponseIsSuccessful();
    }

    // -------------------------------------------------------------------------

    private function createTestData(): void
    {
        $this->tenant = new Tenant();
        $this->tenant->setName('BSI Log Test Tenant ' . uniqid());
        $this->tenant->setCode('TST-' . uniqid());
        $this->em->persist($this->tenant);

        $this->foreignTenant = new Tenant();
        $this->foreignTenant->setName('BSI Log Foreign Tenant ' . uniqid());
        $this->foreignTenant->setCode('TSF-' . uniqid());
        $this->em->persist($this->foreignTenant);
        $this->foreignAdmin = $this->makeUser('bsi_foreign_admin_' . uniqid(), ['ROLE_ADMIN'], $this->foreignTenant);

        $this->manager = $this->makeUser('bsi_manager_' . uniqid(), ['ROLE_MANAGER']);
        $this->auditor = $this->makeUser('bsi_auditor_' . uniqid(), ['ROLE_AUDITOR']);

        $this->exercise = new BCExercise();
        $this->exercise->setName('Test Exercise ' . uniqid());
        $this->exercise->setExerciseType('tabletop');
        $this->exercise->setScope('Test scope');
        $this->exercise->setObjectives('Objective 1');
        $this->exercise->setParticipants('Alice, Bob');
        $this->exercise->setFacilitator('Charlie');
        $this->exercise->setStatus('completed');
        $this->exercise->setExerciseDate(new \DateTime('-1 day'));
        $this->exercise->setTenant($this->tenant);
        $this->em->persist($this->exercise);

        $this->log = new Bsi2004ExerciseLog();
        $this->log->setTenant($this->tenant);
        $this->log->setBcExercise($this->exercise);
        $this->log->setExerciseType(Bsi2004ExerciseLog::EXERCISE_TYPE_TABLETOP);
        $this->log->setBsi2004Template(Bsi2004ExerciseLog::TEMPLATE_STANDARD);
        $this->log->setScenarioSummary('Scenario text');
        $this->log->setObjectives(['Objective 1']);
        $this->log->setParticipants([['name' => 'Alice']]);
        $this->em->persist($this->log);

        $this->em->flush();
    }

    private function makeExercise(string $name, ?Tenant $tenant): BCExercise
    {
        $exercise = new BCExercise();
        $exercise->setName($name);
        $exercise->setExerciseType('tabletop');
        $exercise->setScope('Scope');
        $exercise->setObjectives('Objective');
        $exercise->setParticipants('Alice');
        $exercise->setFacilitator('Charlie');
        $exercise->setStatus('completed');
        $exercise->setExerciseDate(new \DateTime('-1 day'));
        $exercise->setTenant($tenant);
        $this->em->persist($exercise);

        return $exercise;
    }

    private function makeUser(string $email, array $roles, ?Tenant $tenant = null): User
    {
        $user = new User();
        $user->setEmail($email . '@test.com');
        $user->setPassword('$2y$13$' . str_repeat('a', 53));
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setRoles($roles);
        $user->setTenant($tenant ?? $this->tenant);
        $this->em->persist($user);
        return $user;
    }

    private function loginAs(User $user): void
    {
        $this->client->loginUser($user);
    }
}
