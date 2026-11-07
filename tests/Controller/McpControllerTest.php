<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use App\Mcp\McpSettings;
use App\Service\ApiTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * /mcp end to end: bearer token only, organisation opt-in, JSON-RPC over
 * POST, tools answering with the token user's scope.
 */
final class McpControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private User $user;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $id = uniqid('mcp_', true);
        $this->tenant = (new Tenant())->setName('MCP Org ' . $id)->setCode('mcp_' . str_replace('.', '', $id));
        $this->em->persist($this->tenant);
        $this->user = (new User())
            ->setEmail($id . '@example.test')
            ->setFirstName('Ina')
            ->setLastName('Isb')
            ->setRoles(['ROLE_ISB'])
            ->setPassword('hashed')
            ->setTenant($this->tenant)
            ->setIsActive(true);
        $this->em->persist($this->user);
        $this->em->flush();

        $this->token = static::getContainer()->get(ApiTokenManager::class)->mint($this->user, 'Laptop');
    }

    #[Test]
    public function withoutTokenTheAnswerIs401WithBearerChallenge(): void
    {
        $this->rpc('initialize', [], null);

        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('Bearer', (string) $this->client->getResponse()->headers->get('WWW-Authenticate'));
    }

    #[Test]
    public function aSessionCookieAloneIsRefused(): void
    {
        $this->client->loginUser($this->user);
        $this->rpc('initialize', [], null);

        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function theOrganisationMustOptIn(): void
    {
        $this->rpc('initialize', []);
        self::assertResponseStatusCodeSame(403);

        $this->enable();
        $answer = $this->rpc('initialize', ['protocolVersion' => '2025-06-18']);
        self::assertResponseIsSuccessful();
        self::assertSame('2025-06-18', $answer['result']['protocolVersion'] ?? null);
    }

    #[Test]
    public function toolsAnswerInTheTokenUsersOrganisation(): void
    {
        $this->enable();

        $tools = array_column($this->rpc('tools/list')['result']['tools'] ?? [], 'name');
        foreach (['get_context', 'my_day', 'list_records', 'get_record', 'search', 'propose_draft'] as $name) {
            self::assertContains($name, $tools);
        }

        $context = $this->rpc('tools/call', ['name' => 'get_context'])['result'] ?? [];
        self::assertFalse($context['isError'] ?? true);
        self::assertSame($this->tenant->getName(), $context['structuredContent']['organisation']['name'] ?? null);
        self::assertContains('isb', $context['structuredContent']['personas'] ?? []);

        $listable = array_keys(array_filter($context['structuredContent']['resources'] ?? [], static fn (array $r): bool => $r['list']));
        self::assertNotEmpty($listable, 'REST resources are offered to list_records');
        $page = $this->rpc('tools/call', ['name' => 'list_records', 'arguments' => ['resource' => $listable[0], 'items_per_page' => 5]])['result'] ?? [];
        self::assertFalse($page['isError'] ?? true, (string) ($page['content'][0]['text'] ?? ''));
        self::assertIsArray($page['structuredContent']['items'] ?? null);

        $myDay = $this->rpc('tools/call', ['name' => 'my_day'])['result'] ?? [];
        self::assertFalse($myDay['isError'] ?? true, (string) ($myDay['content'][0]['text'] ?? ''));
    }

    #[Test]
    public function getIsNotSpoken(): void
    {
        $this->client->request('GET', '/mcp', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]);

        self::assertResponseStatusCodeSame(405);
    }

    private function enable(): void
    {
        $tenant = $this->em->find(Tenant::class, $this->tenant->getId());
        self::assertInstanceOf(Tenant::class, $tenant);
        static::getContainer()->get(McpSettings::class)->setEnabled($tenant, true);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function rpc(string $method, array $params = [], ?string $token = 'default'): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . ($token === 'default' ? $this->token : $token);
        }
        $this->client->request('POST', '/mcp', server: $server, content: (string) json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params === [] ? new \stdClass() : $params,
        ]));

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
