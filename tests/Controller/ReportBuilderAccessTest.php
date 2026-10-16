<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CustomReport;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;

/**
 * CustomReport is scoped by an int tenantId the Doctrine tenant filter does
 * not see: every action must check the organisation itself, shared
 * templates stay inside their organisation, and the state-changing POSTs
 * require a CSRF token.
 */
final class ReportBuilderAccessTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    /** @var list<object> */
    private array $cleanup = [];
    private User $owner;
    private User $colleague;
    private User $foreignAdmin;
    private User $foreignUser;
    private CustomReport $template;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $own = $this->tenant('own');
        $foreign = $this->tenant('foreign');
        $this->owner = $this->user($own, 'ROLE_MANAGER', 'owner');
        $this->colleague = $this->user($own, 'ROLE_USER', 'colleague');
        $this->foreignAdmin = $this->user($foreign, 'ROLE_ADMIN', 'foreign_admin');
        $this->foreignUser = $this->user($foreign, 'ROLE_USER', 'foreign_user');
        $this->em->flush();

        $this->template = new CustomReport();
        $this->template->setName('Shared template');
        $this->template->setCategory(CustomReport::CATEGORY_GENERAL);
        $this->template->setLayout(CustomReport::LAYOUT_DASHBOARD);
        $this->template->setOwner($this->owner);
        $this->template->setTenantId($own->getId());
        $this->template->setIsTemplate(true);
        $this->template->setIsShared(true);
        $this->em->persist($this->template);
        $this->em->flush();

        $this->cleanup = [$own, $foreign, $this->owner, $this->colleague, $this->foreignAdmin, $this->foreignUser, $this->template];
    }

    protected function tearDown(): void
    {
        $tenantIds = [];
        foreach ($this->cleanup as $entity) {
            if ($entity instanceof Tenant) {
                $tenantIds[] = $entity->getId();
            }
        }
        foreach ($this->em->getRepository(CustomReport::class)->findBy(['tenantId' => $tenantIds]) as $report) {
            $this->em->remove($report);
        }
        $this->em->flush();
        foreach (array_reverse($this->cleanup) as $entity) {
            if ($entity instanceof CustomReport) {
                continue; // removed with the tenant's reports above
            }
            $managed = $this->em->find($entity::class, $entity->getId());
            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }
        $this->em->flush();
        parent::tearDown();
    }

    #[Test]
    public function sharedTemplateIsNotReadableFromAnotherOrganisation(): void
    {
        $id = $this->template->getId();
        $this->client->loginUser($this->foreignUser);
        foreach (['edit', 'preview', 'export/pdf'] as $action) {
            $this->client->request('GET', '/en/report-builder/' . $id . '/' . $action);
            self::assertResponseStatusCodeSame(404, $action);
        }

        $this->client->loginUser($this->colleague);
        $this->client->request('GET', '/en/report-builder/' . $id . '/edit');
        self::assertResponseIsSuccessful('a colleague of the same organisation may use the shared template');
    }

    #[Test]
    public function foreignAdminCannotEditReportSettings(): void
    {
        $this->client->loginUser($this->foreignAdmin);
        $this->client->request('GET', '/en/report-builder/' . $this->template->getId() . '/settings');
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function stateChangingAjaxActionsRequireTheReportToken(): void
    {
        $id = $this->template->getId();
        $this->client->loginUser($this->owner);
        $payload = (string) json_encode(['name' => 'Renamed']);

        foreach (['save', 'favorite', 'share', 'save-as-template'] as $action) {
            $this->client->request('POST', '/en/report-builder/' . $id . '/' . $action, [], [], ['CONTENT_TYPE' => 'application/json'], $payload);
            self::assertResponseStatusCodeSame(403, $action . ' without token');
        }

        $token = $this->seedCsrf('report_builder_' . $id);
        $this->client->request('POST', '/en/report-builder/' . $id . '/save', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], $payload);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        self::assertSame('Renamed', $this->em->find(CustomReport::class, $id)?->getName());
    }

    #[Test]
    public function shareOnlyAcceptsColleaguesOfTheOwnOrganisation(): void
    {
        $id = $this->template->getId();
        $this->client->loginUser($this->owner);
        $token = $this->seedCsrf('report_builder_' . $id);

        $this->client->request('POST', '/en/report-builder/' . $id . '/share', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], (string) json_encode(['user_ids' => [$this->colleague->getId(), $this->foreignUser->getId()]]));
        self::assertResponseIsSuccessful();

        $this->em->clear();
        self::assertSame([$this->colleague->getId()], $this->em->find(CustomReport::class, $id)?->getSharedWith());
    }

    #[Test]
    public function cloneAndDeleteRequireTheirFormToken(): void
    {
        $id = $this->template->getId();
        $this->client->loginUser($this->owner);
        $repository = $this->em->getRepository(CustomReport::class);
        $before = count($repository->findBy(['tenantId' => $this->template->getTenantId()]));

        $this->client->request('POST', '/en/report-builder/' . $id . '/clone');
        $this->client->request('POST', '/en/report-builder/' . $id . '/delete');

        $this->em->clear();
        self::assertCount($before, $repository->findBy(['tenantId' => $this->template->getTenantId()]));
        self::assertNotNull($repository->find($id), 'delete without token must not remove the report');

        $this->client->request('POST', '/en/report-builder/' . $id . '/delete', ['_token' => $this->seedCsrf('delete' . $id)]);
        self::assertResponseRedirects();
        $this->em->clear();
        self::assertNull($repository->find($id));
    }

    private function seedCsrf(string $tokenId): string
    {
        $this->client->request('GET', '/en/report-builder');
        $session = $this->client->getRequest()->getSession();
        $token = (new UriSafeTokenGenerator())->generateToken();
        $session->set('_csrf/' . $tokenId, $token);
        $session->save();

        return $token;
    }

    private function tenant(string $marker): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('RB access ' . $marker);
        $tenant->setCode('rb_access_' . $marker . '_' . uniqid());
        $this->em->persist($tenant);

        return $tenant;
    }

    private function user(Tenant $tenant, string $role, string $marker): User
    {
        $user = new User();
        $user->setEmail('rb_' . $marker . '_' . uniqid() . '@example.com');
        $user->setFirstName($marker);
        $user->setLastName('Report');
        $user->setRoles([$role]);
        $user->setPassword('hashed_password');
        $user->setTenant($tenant);
        $user->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }
}
