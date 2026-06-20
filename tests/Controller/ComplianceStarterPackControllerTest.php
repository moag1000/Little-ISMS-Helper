<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ComplianceFramework;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\Job\InRequestJobRunner;
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
 * The async-job RUNNER ({@see InRequestJobRunner}) is replaced with a no-op
 * stub for the dispatch test: in the test (CLI) SAPI the in-request runner
 * would execute the dispatched job synchronously, and the runner resolves the
 * job from the `app.async_job` tagged locator (so stubbing the controller's
 * job/collaborator services in the test container does NOT reach it — the real
 * loaders would run for minutes and pollute the global catalogue). Stubbing the
 * runner is the correct seam: it returns the rendered response unchanged
 * without resolving or executing any job. This keeps the controller test
 * focused on what it owns — dispatch wiring + PRG redirect + RBAC + CTA
 * rendering. The job's real behaviour is covered by
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

        // Replace the in-request runner with a stub that returns the rendered
        // response WITHOUT resolving or executing the dispatched job. This is
        // the correct seam: the real runner resolves the job from the
        // `app.async_job` locator (bypassing any test-container service
        // override), so the real catalogue load would otherwise run here.
        $runnerStub = $this->createStub(InRequestJobRunner::class);
        $runnerStub->method('dispatch')->willReturnArgument(3); // $response

        // KernelBrowser reboots the kernel before every request by default,
        // which would discard the container override below. Disable reboot so
        // the stubbed runner survives into the POST request.
        $this->client->disableReboot();
        static::getContainer()->set(InRequestJobRunner::class, $runnerStub);

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
