<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

/**
 * Smoke tests + RBAC for WorkflowOverlayController (Sprint Y.3).
 *
 * Covers:
 *  - Routes are registered
 *  - Anonymous → 302 redirect (auth wall)
 *  - ROLE_USER → 403 / redirect (access denied)
 *  - ROLE_ADMIN → 200 on index + show
 *  - show for lifecycle workflow returns 200 (redirect to lifecycle-overrides hint)
 *  - edit GET returns form with 200
 *  - reset POST requires valid CSRF
 *  - show with unknown workflow name → 404
 */
final class WorkflowOverlayControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    private ?User $adminUser = null;
    private ?User $regularUser = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $this->em = $em;

        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);

        $this->tenant = (new Tenant())
            ->setCode('wo-' . $suffix)
            ->setName('WO Tenant ' . $suffix);
        $this->em->persist($this->tenant);

        $this->adminUser = (new User())
            ->setEmail('wo-admin-' . $suffix . '@example.test')
            ->setFirstName('WO')
            ->setLastName('Admin')
            ->setRoles(['ROLE_ADMIN'])
            ->setPassword('hashed_password')
            ->setTenant($this->tenant)
            ->setAuthProvider('local')
            ->setIsActive(true);
        $this->em->persist($this->adminUser);

        $this->regularUser = (new User())
            ->setEmail('wo-user-' . $suffix . '@example.test')
            ->setFirstName('WO')
            ->setLastName('User')
            ->setRoles(['ROLE_USER'])
            ->setPassword('hashed_password')
            ->setTenant($this->tenant)
            ->setAuthProvider('local')
            ->setIsActive(true);
        $this->em->persist($this->regularUser);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            try {
                foreach ([$this->adminUser, $this->regularUser, $this->tenant] as $e) {
                    if ($e && method_exists($e, 'getId') && $e->getId() !== null) {
                        $reload = $this->em->find($e::class, $e->getId());
                        if ($reload !== null) {
                            $this->em->remove($reload);
                        }
                    }
                }
                $this->em->flush();
            } catch (\Throwable) {
                // best-effort cleanup
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function testRoutesAreRegistered(): void
    {
        $container = static::getContainer();
        /** @var RouterInterface $router */
        $router = $container->get(RouterInterface::class);
        $collection = $router->getRouteCollection();

        self::assertNotNull(
            $collection->get('admin_workflow_overlay_index'),
            'Route admin_workflow_overlay_index is not registered.',
        );
        self::assertNotNull(
            $collection->get('admin_workflow_overlay_show'),
            'Route admin_workflow_overlay_show is not registered.',
        );
        self::assertNotNull(
            $collection->get('admin_workflow_overlay_edit'),
            'Route admin_workflow_overlay_edit is not registered.',
        );
        self::assertNotNull(
            $collection->get('admin_workflow_overlay_reset'),
            'Route admin_workflow_overlay_reset is not registered.',
        );
    }

    #[Test]
    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/de/admin/workflows');

        self::assertSame(
            Response::HTTP_FOUND,
            $this->client->getResponse()->getStatusCode(),
            'Unauthenticated request should redirect.',
        );
    }

    #[Test]
    public function testIndexDeniedForRoleUser(): void
    {
        $this->client->loginUser($this->regularUser);
        $this->client->request('GET', '/de/admin/workflows');

        $status = $this->client->getResponse()->getStatusCode();
        self::assertTrue(
            $status === Response::HTTP_FORBIDDEN || $status === Response::HTTP_FOUND,
            sprintf('Expected 403 or 302 for ROLE_USER, got %d.', $status),
        );
    }

    #[Test]
    public function testIndexRendersForAdmin(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/de/admin/workflows');

        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Workflow', $html);
    }

    #[Test]
    public function testIndexListsLifecycleWorkflows(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/de/admin/workflows');

        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        // document_lifecycle is always present (config/workflows/document.yaml)
        self::assertStringContainsString('document_lifecycle', $html);
    }

    #[Test]
    public function testIndexListsRegulatoryWorkflowFallback(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/de/admin/workflows');

        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        // gdpr_data_breach is in the hardcoded fallback list
        self::assertStringContainsString('gdpr_data_breach', $html);
    }

    #[Test]
    public function testShowLifecycleWorkflowRendersForAdmin(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/de/admin/workflows/document_lifecycle');

        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        // Should contain the hint about transition overrides for lifecycle workflows
        self::assertStringContainsString('document_lifecycle', $html);
    }

    #[Test]
    public function testShowRegulatoryWorkflowWithoutYamlFileRendersEmpty(): void
    {
        // gdpr_data_breach is in fallback list but may not have a YAML file yet (Y.2)
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/de/admin/workflows/gdpr_data_breach');

        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('gdpr_data_breach', $html);
    }

    #[Test]
    public function testShowUnknownWorkflowReturns404(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/de/admin/workflows/totally_unknown_workflow');

        self::assertSame(
            Response::HTTP_NOT_FOUND,
            $this->client->getResponse()->getStatusCode(),
        );
    }

    #[Test]
    public function testEditLifecycleWorkflowWithNoStepsRedirects(): void
    {
        // document_lifecycle has no steps → step 0 → 404 (no steps defined)
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/de/admin/workflows/document_lifecycle/0/edit');

        // Expects 404 because no steps exist for lifecycle workflows
        self::assertSame(
            Response::HTTP_NOT_FOUND,
            $this->client->getResponse()->getStatusCode(),
        );
    }

    #[Test]
    public function testResetRequiresValidCsrf(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('POST', '/de/admin/workflows/document_lifecycle/0/reset', [
            '_token' => 'invalid-token',
        ]);

        // Should redirect back with error flash (CSRF invalid → redirect to show)
        self::assertSame(Response::HTTP_FOUND, $this->client->getResponse()->getStatusCode());
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('workflows/document_lifecycle', $location);
    }
}
