<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * UI wave G: one overlay for search and commands. Every page carries one
 * trigger and one overlay; the commands are real, locale-prefixed routes and
 * only those the user may open (admin commands for admins only).
 */
final class SearchOverlayTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $modules = static::getContainer()->get(ModuleConfigurationService::class);
        $uid = substr(uniqid('', true), -8);
        $this->tenant = (new Tenant())->setName('Search ' . $uid)->setCode('search_' . $uid);
        $this->tenant->setActiveModules(array_values(array_unique([
            ...$modules->getInstanceDefaultModules(), 'risks', 'assets',
        ])));
        $this->em->persist($this->tenant);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if ($this->em->isOpen() && $this->tenant?->getId() !== null) {
            $connection = $this->em->getConnection();
            foreach (['DELETE FROM users WHERE tenant_id = ?', 'DELETE FROM tenant WHERE id = ?'] as $sql) {
                try {
                    $connection->executeStatement($sql, [$this->tenant->getId()]);
                } catch (\Throwable) {
                    // best effort: audit rows written during the request may block
                }
            }
        }
        parent::tearDown();
    }

    /** @param list<string> $roles */
    private function login(array $roles): void
    {
        $user = new User();
        $user->setEmail('search_' . uniqid() . '@example.com');
        $user->setFirstName('Sina');
        $user->setLastName('Suche');
        $user->setRoles($roles);
        $user->setPassword('hashed_password');
        $user->setTenant($this->tenant);
        $user->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }

    /** @return list<array{id: string, url: string}> */
    private function commands(): array
    {
        $crawler = $this->client->request('GET', '/de/dashboard');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-command-palette-open]'));
        self::assertCount(1, $crawler->filter('[data-controller="command-palette"]'));
        self::assertCount(0, $crawler->filter('[data-controller="search"]'), 'the old second overlay is gone');

        $json = (string) $crawler->filter('[data-controller="command-palette"]')->attr('data-command-palette-commands-value');

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function oneOverlayWithLocalisedRouteCommands(): void
    {
        $this->login(['ROLE_ADMIN']);
        $commands = $this->commands();

        self::assertNotEmpty($commands);
        foreach ($commands as $command) {
            self::assertMatchesRegularExpression('#^/de/#', $command['url'], $command['id'] . ' is a localised route');
        }
        self::assertContains('admin-settings', array_column($commands, 'id'));
        self::assertContains('create-risk', array_column($commands, 'id'));
    }

    #[Test]
    public function adminCommandsAreHiddenFromUsers(): void
    {
        $this->login(['ROLE_USER']);
        $ids = array_column($this->commands(), 'id');

        self::assertContains('goto-dashboard', $ids);
        foreach ($ids as $id) {
            self::assertStringStartsNotWith('admin-', $id);
        }
        self::assertNotContains('goto-users', $ids);
    }
}
