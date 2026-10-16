<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;

/**
 * The tour-completion report (list, CSV, reset) is scoped to the admin's own
 * organisation — ROLE_ADMIN must not see or reset other tenants' users.
 */
final class AdminTourCompletionControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    /** @var list<object> */
    private array $cleanup = [];
    private User $admin;
    private User $foreignUser;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $own = $this->tenant('own');
        $foreign = $this->tenant('foreign');
        $this->admin = $this->user($own, 'ROLE_ADMIN', 'tour-admin');
        $this->foreignUser = $this->user($foreign, 'ROLE_USER', 'tour-foreign');
        $this->em->flush();
        $this->cleanup = [$own, $foreign, $this->admin, $this->foreignUser];
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $entity) {
            $managed = $this->em->find($entity::class, $entity->getId());
            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }
        $this->em->flush();
        parent::tearDown();
    }

    #[Test]
    public function listAndExportOnlyContainOwnOrganisation(): void
    {
        $this->client->loginUser($this->admin);

        $this->client->request('GET', '/en/admin/tours/completion');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString((string) $this->admin->getEmail(), $html);
        self::assertStringNotContainsString((string) $this->foreignUser->getEmail(), $html);

        ob_start();
        $this->client->request('GET', '/en/admin/tours/completion/export.csv');
        $csv = (string) ob_get_clean() . (string) $this->client->getInternalResponse()->getContent();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString((string) $this->admin->getEmail(), $csv);
        self::assertStringNotContainsString((string) $this->foreignUser->getEmail(), $csv);
    }

    #[Test]
    public function resetOfForeignUserIsNotFound(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/en/admin/tours/completion');
        $tokenId = 'admin_tour_reset_' . $this->foreignUser->getId();
        $session = $this->client->getRequest()->getSession();
        $token = (new UriSafeTokenGenerator())->generateToken();
        $session->set('_csrf/' . $tokenId, $token);
        $session->save();

        $this->client->request(
            'POST',
            '/en/admin/tours/completion/reset/' . $this->foreignUser->getId() . '/all',
            ['_token' => $token],
        );
        self::assertResponseStatusCodeSame(404);
    }

    private function tenant(string $suffix): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tour ' . $suffix);
        $tenant->setCode('tour_' . $suffix . '_' . uniqid());
        $this->em->persist($tenant);

        return $tenant;
    }

    private function user(Tenant $tenant, string $role, string $marker): User
    {
        $user = new User();
        $user->setEmail($marker . '_' . uniqid() . '@example.com');
        $user->setFirstName($marker);
        $user->setLastName('Tour');
        $user->setRoles([$role]);
        $user->setPassword('hashed_password');
        $user->setTenant($tenant);
        $user->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }
}
