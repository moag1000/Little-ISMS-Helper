<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\Risk;
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
 * POST, tools answering with the token user's scope, direct writing only
 * with a writing token, never for reviewer roles, never for status.
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
    public function aReadOnlyTokenGetsNoWritingTool(): void
    {
        $this->enable();

        $tools = array_column($this->rpc('tools/list')['result']['tools'] ?? [], 'name');
        self::assertNotContains('save_record', $tools);
        $context = $this->rpc('tools/call', ['name' => 'get_context'])['result']['structuredContent'] ?? [];
        self::assertFalse($context['writing']['direct'] ?? true);

        $call = $this->rpc('tools/call', ['name' => 'save_record', 'arguments' => ['resource' => 'Risk', 'data' => ['title' => 'x']]]);
        self::assertSame(-32602, $call['error']['code'] ?? null);
    }

    #[Test]
    public function aWritingTokenCreatesAndChangesARecordInItsOrganisation(): void
    {
        $this->enable();
        $token = static::getContainer()->get(ApiTokenManager::class)->mint($this->user, 'Schreiben', canWrite: true);

        $context = $this->rpc('tools/call', ['name' => 'get_context'], $token)['result']['structuredContent'] ?? [];
        self::assertTrue($context['writing']['direct'] ?? false);
        self::assertTrue($context['resources']['Risk']['create'] ?? false);

        $fields = $this->rpc('tools/call', ['name' => 'save_record', 'arguments' => ['resource' => 'Risk', 'data' => new \stdClass()]], $token)['result'] ?? [];
        self::assertFalse($fields['isError'] ?? true, (string) ($fields['content'][0]['text'] ?? ''));
        $writable = $fields['structuredContent']['writable_fields'] ?? [];
        self::assertContains('title', $writable);
        foreach (['status', 'tenant', 'id', 'acceptanceApprovedBy', 'acceptanceApprovedAt'] as $never) {
            self::assertNotContains($never, $writable, $never . ' must not be writable through the assistant');
        }

        $created = $this->rpc('tools/call', ['name' => 'save_record', 'arguments' => ['resource' => 'Risk', 'data' => [
            'title' => 'Phishing gegen Buchhaltung',
            'description' => 'Aus dem Workshop',
            'probability' => 3,
            'impact' => 4,
        ]]], $token)['result'] ?? [];
        self::assertFalse($created['isError'] ?? true, (string) ($created['content'][0]['text'] ?? ''));
        self::assertSame('created', $created['structuredContent']['operation'] ?? null);
        $id = $created['structuredContent']['record']['id'] ?? null;
        self::assertIsInt($id);

        $this->em->clear();
        $risk = $this->em->find(Risk::class, $id);
        self::assertInstanceOf(Risk::class, $risk);
        self::assertSame($this->tenant->getId(), $risk->getTenant()?->getId(), 'the tenant is forced to the token user\'s organisation');

        $changed = $this->rpc('tools/call', ['name' => 'save_record', 'arguments' => ['resource' => 'Risk', 'id' => $id, 'data' => [
            'title' => 'Phishing gegen Buchhaltung und Einkauf',
        ]]], $token)['result'] ?? [];
        self::assertFalse($changed['isError'] ?? true, (string) ($changed['content'][0]['text'] ?? ''));
        $this->em->clear();
        $risk = $this->em->find(Risk::class, $id);
        self::assertInstanceOf(Risk::class, $risk);
        self::assertSame('Phishing gegen Buchhaltung und Einkauf', $risk->getTitle());
        self::assertSame('Aus dem Workshop', $risk->getDescription(), 'an update changes only the fields sent');
        self::assertSame($this->tenant->getId(), $risk->getTenant()?->getId(), 'an update keeps the organisation');

        $status = $this->rpc('tools/call', ['name' => 'save_record', 'arguments' => ['resource' => 'Risk', 'id' => $id, 'data' => [
            'status' => 'accepted',
        ]]], $token)['result'] ?? [];
        self::assertTrue($status['isError'] ?? false, 'status goes through the lifecycle, not through save_record');

        $audit = $this->em->getRepository(AuditLog::class)->findBy(['action' => 'mcp.write', 'entityId' => $this->tokenId('Schreiben')]);
        self::assertNotEmpty($audit, 'every write is audit-logged as mcp.write');
    }

    #[Test]
    public function aReviewerRoleStaysReadOnlyEvenWithAWritingToken(): void
    {
        $this->enable();
        $this->user->setRoles(['ROLE_AUDITOR']);
        $this->em->flush();
        $token = static::getContainer()->get(ApiTokenManager::class)->mint($this->user, 'Prüfer', canWrite: true);

        $tools = array_column($this->rpc('tools/list', [], $token)['result']['tools'] ?? [], 'name');
        self::assertNotContains('save_record', $tools);
        $call = $this->rpc('tools/call', ['name' => 'save_record', 'arguments' => ['resource' => 'Risk', 'data' => ['title' => 'x']]], $token);
        self::assertSame(-32602, $call['error']['code'] ?? null);
    }

    #[Test]
    public function theOrganisationCanSwitchWritingOff(): void
    {
        $this->enable();
        $tenant = $this->em->find(Tenant::class, $this->tenant->getId());
        self::assertInstanceOf(Tenant::class, $tenant);
        static::getContainer()->get(McpSettings::class)->setWriteEnabled($tenant, false);
        $token = static::getContainer()->get(ApiTokenManager::class)->mint($this->user, 'Schreiben', canWrite: true);

        $tools = array_column($this->rpc('tools/list', [], $token)['result']['tools'] ?? [], 'name');
        self::assertNotContains('save_record', $tools);
        self::assertContains('propose_draft', $tools, 'drafts into the queue stay possible');
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

    private function tokenId(string $label): ?int
    {
        $token = $this->em->getRepository(\App\Entity\ApiToken::class)->findOneBy(['user' => $this->user, 'label' => $label]);

        return $token?->getId();
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
