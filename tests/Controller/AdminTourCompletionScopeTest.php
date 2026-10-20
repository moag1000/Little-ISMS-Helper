<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use App\Service\GuidedTourService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security hotfix (M1): the tour-completion report listed / exported every
 * user of every tenant (email, name, role) and let any tenant admin reset any
 * user's tours. It is now scoped like user management (UserVoter).
 */
final class AdminTourCompletionScopeTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $uid;
    private Tenant $tenantA;
    private Tenant $tenantB;
    private User $adminA;
    private User $userA;
    private User $userB;
    private User $super;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uid = substr(md5(uniqid('', true)), 0, 10);

        $this->tenantA = (new Tenant())->setName('Tour A ' . $this->uid)->setCode('tour_a_' . $this->uid);
        $this->tenantB = (new Tenant())->setName('Tour B ' . $this->uid)->setCode('tour_b_' . $this->uid);
        $this->em->persist($this->tenantA);
        $this->em->persist($this->tenantB);

        $this->adminA = $this->user('admina', ['ROLE_ADMIN'], $this->tenantA);
        $this->userA = $this->user('usera', ['ROLE_USER'], $this->tenantA);
        $this->userB = $this->user('userb', ['ROLE_USER'], $this->tenantB);
        // Instance operator without an organisation: the only account that
        // lists every organisation's users (JobTenantScope).
        $this->super = $this->user('super', ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN'], null);
        $this->userA->markTourCompleted(GuidedTourService::TOUR_JUNIOR);
        $this->userB->markTourCompleted(GuidedTourService::TOUR_JUNIOR);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        foreach (['admina', 'usera', 'userb', 'super'] as $label) {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $this->email($label)]);
            if ($user !== null) {
                $this->em->remove($user);
            }
        }
        $this->em->flush();
        foreach ([$this->tenantA->getId(), $this->tenantB->getId()] as $id) {
            if ($id !== null && ($tenant = $this->em->find(Tenant::class, $id)) !== null) {
                $this->em->remove($tenant);
                $this->em->flush();
            }
        }
        parent::tearDown();
    }

    #[Test]
    public function tenantAdminSeesAndExportsOnlyOwnTenantUsers(): void
    {
        $this->client->loginUser($this->adminA);

        $this->client->request('GET', '/en/admin/tours/completion');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($this->email('usera'), $html);
        self::assertStringNotContainsString($this->email('userb'), $html);

        $this->client->request('GET', '/en/admin/tours/completion/export.csv');
        self::assertResponseIsSuccessful();
        $csv = (string) $this->client->getInternalResponse()->getContent();
        self::assertStringContainsString($this->email('usera'), $csv);
        self::assertStringNotContainsString($this->email('userb'), $csv);
    }

    #[Test]
    public function tenantAdminCannotResetToursOfForeignUser(): void
    {
        $this->client->loginUser($this->adminA);
        $token = $this->csrf($this->userB);

        $this->client->request('POST', $this->resetUrl($this->userB), ['_token' => $token]);
        // Users of other organisations are treated as non-existent.
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        self::assertTrue($this->reload($this->userB)->hasCompletedTour(GuidedTourService::TOUR_JUNIOR));
    }

    #[Test]
    public function tenantAdminCanResetToursOfOwnTenantUser(): void
    {
        $this->client->loginUser($this->adminA);
        $token = $this->csrf($this->userA);

        $this->client->request('POST', $this->resetUrl($this->userA), ['_token' => $token]);
        self::assertResponseRedirects();

        self::assertFalse($this->reload($this->userA)->hasCompletedTour(GuidedTourService::TOUR_JUNIOR));
    }

    #[Test]
    public function superAdminStillSeesAllUsers(): void
    {
        $this->client->loginUser($this->super);

        $this->client->request('GET', '/en/admin/tours/completion/export.csv');
        self::assertResponseIsSuccessful();
        $csv = (string) $this->client->getInternalResponse()->getContent();
        self::assertStringContainsString($this->email('usera'), $csv);
        self::assertStringContainsString($this->email('userb'), $csv);
    }

    private function resetUrl(User $user): string
    {
        return '/en/admin/tours/completion/reset/' . $user->getId() . '/all';
    }

    private function csrf(User $user): string
    {
        $this->client->request('GET', '/en/admin/tours/completion');
        $session = $this->client->getRequest()->getSession();
        $value = bin2hex(random_bytes(16));
        $session->set('_csrf/admin_tour_reset_' . $user->getId(), $value);
        $session->save();

        return $value;
    }

    /**
     * @param list<string> $roles
     */
    private function user(string $label, array $roles, ?Tenant $tenant): User
    {
        $user = (new User())
            ->setEmail($this->email($label))
            ->setFirstName(ucfirst($label))
            ->setLastName('Tour')
            ->setRoles($roles)
            ->setPassword('hashed_password')
            ->setTenant($tenant)
            ->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }

    private function email(string $label): string
    {
        return $label . '_' . $this->uid . '@example.com';
    }

    private function reload(User $user): User
    {
        $this->em->clear();
        $reloaded = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $reloaded);

        return $reloaded;
    }
}
