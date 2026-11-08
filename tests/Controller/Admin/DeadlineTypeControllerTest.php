<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Admin > Fristtypen lists every deadline type, the configured ones with
 * their date field; plain users do not get in. Also proves every configured
 * type's route exists (the container builds all sources for the page).
 */
final class DeadlineTypeControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    /** @var list<User> */
    private array $users = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $uid = uniqid('dltype_', true);
        $this->tenant = (new Tenant())->setName('Deadline types ' . $uid)->setCode('dt_' . substr($uid, -8));
        $this->em->persist($this->tenant);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        try {
            foreach ($this->users as $user) {
                $conn->executeStatement('DELETE FROM users WHERE id = ?', [$user->getId()]);
            }
            $conn->executeStatement('DELETE FROM tenant WHERE id = ?', [$this->tenant?->getId()]);
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    #[Test]
    public function adminSeesConfiguredAndProgrammedTypes(): void
    {
        $this->client->loginUser($this->user('ROLE_ADMIN'));
        $crawler = $this->client->request('GET', '/de/admin/deadline-types?per_page=100');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Fristtypen');
        $text = $crawler->filter('table')->text();
        self::assertStringContainsString('Risiko-Review', $text);
        self::assertStringContainsString('reviewDate', $text);
        self::assertStringContainsString('NIS-2-Registrierung', $text);

        $this->client->request('GET', '/de/admin/deadline-types?origin=config&q=Einwilligung');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Einwilligung läuft ab');
        self::assertSelectorTextNotContains('table', 'Risiko-Review');
    }

    #[Test]
    public function everyConfiguredRouteExists(): void
    {
        $routes = static::getContainer()->get('router')->getRouteCollection();
        $sources = static::getContainer()->get(\App\Service\Deadline\Configured\ConfiguredDeadlineSources::class);
        foreach ($sources->all() as $source) {
            $route = $source->definition->route;
            if ($route !== null) {
                self::assertNotNull($routes->get($route) ?? $routes->get($route . '.de') ?? $this->localized($routes, $route), $source->slug() . ': route ' . $route);
            }
        }
    }

    #[Test]
    public function plainUserIsDenied(): void
    {
        $this->client->loginUser($this->user('ROLE_USER'));
        $this->client->request('GET', '/de/admin/deadline-types');
        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 403]);
    }

    private function localized(\Symfony\Component\Routing\RouteCollection $routes, string $name): ?\Symfony\Component\Routing\Route
    {
        foreach ($routes->all() as $key => $route) {
            if ($key === $name || str_starts_with($key, $name . '.')) {
                return $route;
            }
        }

        return null;
    }

    private function user(string $role): User
    {
        $user = (new User())
            ->setEmail(uniqid('dltype_', true) . '@x.test')
            ->setFirstName('Deadline')->setLastName('Types')
            ->setRoles([$role])->setPassword('hashed')
            ->setTenant($this->tenant)->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->users[] = $user;

        return $user;
    }
}
