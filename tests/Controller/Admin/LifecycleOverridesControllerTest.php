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
 * Smoke tests for LifecycleOverridesController.
 *
 * Covers:
 *  - ROLE_USER → 302 redirect (access denied / auth wall)
 *  - ROLE_ADMIN → 200 on index
 *  - Routes are registered in the router
 *  - Show + Edit routes return 200 for ROLE_ADMIN (skipped: requires fixture workflows)
 */
final class LifecycleOverridesControllerTest extends WebTestCase
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
            ->setCode('lo-' . $suffix)
            ->setName('LO Tenant ' . $suffix);
        $this->em->persist($this->tenant);

        $this->adminUser = (new User())
            ->setEmail('lo-admin-' . $suffix . '@example.test')
            ->setFirstName('LO')
            ->setLastName('Admin')
            ->setRoles(['ROLE_ADMIN'])
            ->setPassword('hashed_password')
            ->setTenant($this->tenant)
            ->setAuthProvider('local')
            ->setIsActive(true);
        $this->em->persist($this->adminUser);

        $this->regularUser = (new User())
            ->setEmail('lo-user-' . $suffix . '@example.test')
            ->setFirstName('LO')
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
            $collection->get('admin_lifecycle_overrides_index'),
            'Route admin_lifecycle_overrides_index is not registered.',
        );
        self::assertNotNull(
            $collection->get('admin_lifecycle_overrides_show'),
            'Route admin_lifecycle_overrides_show is not registered.',
        );
        self::assertNotNull(
            $collection->get('admin_lifecycle_overrides_edit'),
            'Route admin_lifecycle_overrides_edit is not registered.',
        );
        self::assertNotNull(
            $collection->get('admin_lifecycle_overrides_reset'),
            'Route admin_lifecycle_overrides_reset is not registered.',
        );
    }

    #[Test]
    public function testIndexRequiresAuthentication(): void
    {
        // Anonymous request → redirect to login
        $this->client->request('GET', '/de/admin/lifecycle-overrides');

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
        $this->client->request('GET', '/de/admin/lifecycle-overrides');

        $status = $this->client->getResponse()->getStatusCode();
        // Either 403 Forbidden or 302 redirect (depending on security config)
        self::assertTrue(
            $status === Response::HTTP_FORBIDDEN || $status === Response::HTTP_FOUND,
            sprintf('Expected 403 or 302 for ROLE_USER, got %d.', $status),
        );
    }

    #[Test]
    public function testIndexRendersForAdmin(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/de/admin/lifecycle-overrides');

        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        // The page must contain the localized title
        self::assertStringContainsString('Lifecycle', $html);
    }

    #[Test]
    public function testIndexContainsWorkflowRows(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/de/admin/lifecycle-overrides');

        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        // The document_lifecycle workflow is always present (config/workflows/document.yaml)
        self::assertStringContainsString('document_lifecycle', $html);
    }

    #[Test]
    public function testShowWorkflowRendersForAdmin(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/de/admin/lifecycle-overrides/document_lifecycle');

        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        // Transitions from document.yaml must appear
        self::assertStringContainsString('submit_for_review', $html);
    }

    #[Test]
    public function testEditTransitionRendersFormForAdmin(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/de/admin/lifecycle-overrides/document_lifecycle/submit_for_review/edit');

        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();
        // Form must render with the roles field
        self::assertStringContainsString('roles_raw', $html);
    }

    #[Test]
    public function testResetRequiresValidCsrf(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('POST', '/de/admin/lifecycle-overrides/document_lifecycle/submit_for_review/reset', [
            '_token' => 'invalid-token',
        ]);

        // Should redirect back to show page with error flash
        self::assertSame(Response::HTTP_FOUND, $this->client->getResponse()->getStatusCode());
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('lifecycle-overrides/document_lifecycle', $location);
    }
}
