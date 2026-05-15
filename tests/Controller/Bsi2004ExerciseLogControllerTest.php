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
            if ($this->exercise) {
                $ex = $this->em->find(BCExercise::class, $this->exercise->getId());
                if ($ex) {
                    $this->em->remove($ex);
                }
            }
            foreach ([$this->manager, $this->auditor] as $u) {
                if ($u) {
                    $user = $this->em->find(User::class, $u->getId());
                    if ($user) {
                        $this->em->remove($user);
                    }
                }
            }
            if ($this->tenant) {
                $t = $this->em->find(Tenant::class, $this->tenant->getId());
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

    private function makeUser(string $email, array $roles): User
    {
        $user = new User();
        $user->setEmail($email . '@test.com');
        $user->setPassword('$2y$13$' . str_repeat('a', 53));
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setRoles($roles);
        $user->setTenant($this->tenant);
        $this->em->persist($user);
        return $user;
    }

    private function loginAs(User $user): void
    {
        $this->client->loginUser($user);
    }
}
