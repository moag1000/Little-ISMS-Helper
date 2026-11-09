<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\AuditLog;
use App\Entity\LifecycleConfig;
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
        // S15: roles are checkboxes from the role list, no free text
        self::assertStringContainsString('lifecycle_override[roles][]', $html);
        self::assertStringNotContainsString('value="ROLE_USER"', $html);
    }

    #[Test]
    public function testRolesOutsideTheListAreRejected(): void
    {
        $this->client->loginUser($this->adminUser);
        $crawler = $this->client->request('GET', '/de/admin/lifecycle-overrides/document_lifecycle/publish/edit');
        $values = $crawler->filter('form[name="lifecycle_override"]')->form()->getPhpValues();
        $values['lifecycle_override']['roles'] = ['ROLE_USER'];
        $this->client->request('POST', '/de/admin/lifecycle-overrides/document_lifecycle/publish/edit', $values);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->countOverrides('document_lifecycle', 'publish'));
    }

    #[Test]
    public function testFourEyesRequiredByTheDefinitionCannotBeSwitchedOff(): void
    {
        // asset.yaml dispose: four_eyes: true (ISO A.7.14 secure disposal)
        $this->client->loginUser($this->adminUser);
        $crawler = $this->client->request('GET', '/de/admin/lifecycle-overrides/asset_lifecycle/dispose/edit');
        $values = $crawler->filter('form[name="lifecycle_override"]')->form()->getPhpValues();
        $values['lifecycle_override']['four_eyes_override'] = '1';
        unset($values['lifecycle_override']['four_eyes']);
        $this->client->request('POST', '/de/admin/lifecycle-overrides/asset_lifecycle/dispose/edit', $values);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->countOverrides('asset_lifecycle', 'dispose'));
    }

    #[Test]
    public function testManagementDecisionKeepsItsRoles(): void
    {
        // management_decision.yaml approve: ROLE_TOP_MGMT only — an org admin must not hand it to ROLE_ADMIN.
        $this->client->loginUser($this->adminUser);
        $crawler = $this->client->request('GET', '/de/admin/lifecycle-overrides/management_decision_lifecycle/approve/edit');
        self::assertResponseIsSuccessful();
        $values = $crawler->filter('form[name="lifecycle_override"]')->form()->getPhpValues();
        $values['lifecycle_override']['roles'] = ['ROLE_ADMIN'];
        $this->client->request('POST', '/de/admin/lifecycle-overrides/management_decision_lifecycle/approve/edit', $values);

        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(LifecycleConfig::class)->count([
            'tenant' => $this->tenant->getId(),
            'workflowName' => 'management_decision_lifecycle',
            'transitionName' => 'approve',
            'configKey' => 'roles',
        ]));
    }

    #[Test]
    public function testTighteningIsSaved(): void
    {
        $this->client->loginUser($this->adminUser);
        $crawler = $this->client->request('GET', '/de/admin/lifecycle-overrides/document_lifecycle/submit_for_review/edit');
        $values = $crawler->filter('form[name="lifecycle_override"]')->form()->getPhpValues();
        $values['lifecycle_override']['roles'] = ['ROLE_MANAGER'];
        $values['lifecycle_override']['reason_required_override'] = '1';
        $values['lifecycle_override']['reason_required'] = '1';
        $this->client->request('POST', '/de/admin/lifecycle-overrides/document_lifecycle/submit_for_review/edit', $values);

        self::assertSame(Response::HTTP_FOUND, $this->client->getResponse()->getStatusCode());
        self::assertSame(2, $this->countOverrides('document_lifecycle', 'submit_for_review'));
    }

    private function countOverrides(string $workflow, string $transition): int
    {
        $this->em->clear();

        return $this->em->getRepository(LifecycleConfig::class)->count([
            'tenant' => $this->tenant->getId(),
            'workflowName' => $workflow,
            'transitionName' => $transition,
        ]);
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

    #[Test]
    public function testResetWithOverridesWritesAuditEntry(): void
    {
        // Regression: the reset was logged via logUpdate(old, []) — logUpdate
        // only diffs keys of the new values, so nothing was ever recorded.
        $override = (new LifecycleConfig())
            ->setTenant($this->tenant)
            ->setWorkflowName('document_lifecycle')
            ->setTransitionName('submit_for_review')
            ->setConfigKey('roles')
            ->setConfigValue(['ROLE_ADMIN'])
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->em->persist($override);
        $this->em->flush();

        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/de/dashboard');
        $session = $this->client->getRequest()->getSession();
        $token = (new \Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator())->generateToken();
        $session->set('_csrf/lifecycle_reset_submit_for_review', $token);
        \call_user_func([$session, 'save']);

        $this->client->request('POST', '/de/admin/lifecycle-overrides/document_lifecycle/submit_for_review/reset', [
            '_token' => $token,
        ]);

        self::assertSame(Response::HTTP_FOUND, $this->client->getResponse()->getStatusCode());
        $entry = $this->em->getRepository(AuditLog::class)->findOneBy(
            ['entityType' => 'LifecycleConfig', 'action' => 'delete'],
            ['id' => 'DESC'],
        );
        self::assertNotNull($entry, 'override reset must be audit-logged');
        self::assertStringContainsString('submit_for_review', (string) $entry->getDescription());
    }
}
