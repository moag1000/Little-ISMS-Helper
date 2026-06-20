<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ComplianceFramework;
use App\Entity\Tenant;
use App\Entity\User;
use App\Job\LoadStarterPackJob;
use App\Service\Compliance\MappingSeedService;
use App\Service\ComplianceFrameworkLoaderService;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for the Starter-Pack feature on
 * {@see \App\Controller\ComplianceFrameworkLibraryController::loadStarterPack()}
 * and its empty-state CTA on the compliance index.
 *
 * Covers:
 *  - ROLE_USER POST → 403 (route is ROLE_MANAGER-gated)
 *  - ROLE_MANAGER POST → dispatch happens → 303 redirect to progress page
 *  - empty-state CTA renders only when 0 frameworks are loaded
 *
 * The actual job collaborators ({@see ComplianceFrameworkLoaderService},
 * {@see MappingSeedService}) are replaced with no-op stubs for the dispatch
 * test: in the test (CLI) SAPI the in-request runner executes the job
 * synchronously, and running the real framework loaders would take minutes and
 * pollute the global catalogue. The dispatch wiring + redirect is what this
 * test asserts; the job's real behaviour is covered by
 * {@see \App\Tests\Job\LoadStarterPackJobTest}.
 */
class ComplianceStarterPackControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    private ?User $managerUser = null;
    private ?User $plainUser = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $uniqueId = uniqid('sp_', true);

        $this->tenant = new Tenant();
        $this->tenant->setName('SP Tenant ' . $uniqueId);
        $this->tenant->setCode('sp_tenant_' . $uniqueId);
        $this->em->persist($this->tenant);

        $this->managerUser = new User();
        $this->managerUser->setEmail('manager_' . $uniqueId . '@example.com');
        $this->managerUser->setFirstName('Mona');
        $this->managerUser->setLastName('Manager');
        $this->managerUser->setRoles(['ROLE_MANAGER']);
        $this->managerUser->setPassword('hashed');
        $this->managerUser->setTenant($this->tenant);
        $this->managerUser->setIsActive(true);
        $this->em->persist($this->managerUser);

        $this->plainUser = new User();
        $this->plainUser->setEmail('user_' . $uniqueId . '@example.com');
        $this->plainUser->setFirstName('Ulf');
        $this->plainUser->setLastName('User');
        $this->plainUser->setRoles(['ROLE_USER']);
        $this->plainUser->setPassword('hashed');
        $this->plainUser->setTenant($this->tenant);
        $this->plainUser->setIsActive(true);
        $this->em->persist($this->plainUser);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach ([$this->managerUser, $this->plainUser, $this->tenant] as $entity) {
            if ($entity === null) {
                continue;
            }
            try {
                $managed = $this->em->find($entity::class, $entity->getId());
                if ($managed !== null) {
                    $this->em->remove($managed);
                }
            } catch (\Exception) {
                // ignore
            }
        }
        try {
            $this->em->flush();
        } catch (\Exception) {
            // ignore
        }
        parent::tearDown();
    }

    #[Test]
    public function plainUserPostIsForbidden(): void
    {
        $this->client->loginUser($this->plainUser);
        $this->client->request('POST', '/en/compliance/frameworks/load-starter-pack');

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    #[Test]
    public function emptyStateRendersStarterPackCtaForManager(): void
    {
        $this->purgeFrameworks();

        $this->client->loginUser($this->managerUser);
        $crawler = $this->client->request('GET', '/en/compliance');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action*="load-starter-pack"]');
        self::assertGreaterThan(0, $form->count(), 'Starter-Pack CTA form must render when 0 frameworks are loaded');
    }

    #[Test]
    public function managerPostDispatchesJobAndRedirects(): void
    {
        $this->purgeFrameworks();

        // Replace the slow/global collaborators with no-op stubs so the
        // synchronous in-request runner returns instantly.
        $loaderStub = $this->createStub(ComplianceFrameworkLoaderService::class);
        $loaderStub->method('loadFramework')->willReturn([
            'success' => true,
            'message' => 'stub',
        ]);
        $loaderStub->method('isFrameworkLoaded')->willReturn(true);

        $seedStub = $this->createStub(MappingSeedService::class);
        $seedStub->method('seedAvailablePairs')->willReturn([
            'seeded' => 0,
            'skipped' => 0,
            'pairs' => [],
        ]);

        $moduleStub = $this->createStub(ModuleConfigurationService::class);
        $moduleStub->method('isModuleActive')->willReturn(false);

        $container = static::getContainer();
        // The job is autowired from these services; overriding them in the
        // test container makes the dispatched job a no-op.
        $container->set(ComplianceFrameworkLoaderService::class, $loaderStub);
        $container->set(MappingSeedService::class, $seedStub);
        $container->set(LoadStarterPackJob::class, new LoadStarterPackJob($loaderStub, $seedStub, $moduleStub));

        $this->client->loginUser($this->managerUser);

        // GET the index first to render the CTA form with a valid CSRF token,
        // then submit the crawler form (carries the real token / session).
        $crawler = $this->client->request('GET', '/en/compliance');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action*="load-starter-pack"]')->form();
        $this->client->submit($form);

        // 303 See Other → progress page (PRG). Dispatch happened.
        self::assertResponseRedirects();
        $location = $this->client->getResponse()->headers->get('Location') ?? '';
        self::assertStringContainsString('/admin/jobs/', $location, 'Should redirect to the async-job progress page');
    }

    private function purgeFrameworks(): void
    {
        // Ensure the empty-state path: no active frameworks for this run.
        foreach ($this->em->getRepository(ComplianceFramework::class)->findAll() as $fw) {
            $this->em->remove($fw);
        }
        $this->em->flush();
        $this->em->clear();
    }
}
