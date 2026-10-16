<?php

declare(strict_types=1);

namespace App\Tests\Controller\Bulk;

use App\Entity\Asset;
use App\Entity\AuditLog;
use App\Entity\Tenant;
use App\Entity\User;
use App\Tests\Controller\Trait\SessionCsrfTokenTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every list-view bulk-delete endpoint goes through
 * BulkActionTrait::handleBulkDelete(): CSRF (`bulk_action`) is mandatory,
 * other-tenant rows are rejected, and a successful run writes one
 * AuditLogger::logBulk() batch (ISO 27001 Cl. 7.5.3).
 */
final class BulkDeleteCsrfTest extends WebTestCase
{
    use SessionCsrfTokenTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    private ?Tenant $otherTenant = null;
    private ?User $admin = null;
    /** @var list<object> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $uid = uniqid('bdc_', true);
        $this->tenant = (new Tenant())->setName('BulkDel ' . $uid)->setCode('bd_' . substr($uid, -8));
        $this->otherTenant = (new Tenant())->setName('BulkDelOther ' . $uid)->setCode('bo_' . substr($uid, -8));
        $this->em->persist($this->tenant);
        $this->em->persist($this->otherTenant);

        $this->admin = (new User())
            ->setEmail('bulkdel_' . $uid . '@x.test')
            ->setFirstName('Bulk')->setLastName('Admin')
            ->setRoles(['ROLE_ADMIN'])->setPassword('hashed')
            ->setTenant($this->tenant)->setIsActive(true);
        $this->em->persist($this->admin);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse([...$this->cleanup, $this->admin, $this->otherTenant, $this->tenant]) as $entity) {
            if ($entity === null || $entity->getId() === null) {
                continue;
            }
            try {
                $managed = $this->em->find($entity::class, $entity->getId());
                if ($managed !== null) {
                    $this->em->remove($managed);
                    $this->em->flush();
                }
            } catch (\Throwable) {
            }
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function bulkDeleteEndpoints(): iterable
    {
        foreach ([
            'asset', 'audit', 'audit-finding', 'bc-exercise', 'business-continuity-plan',
            'change-request', 'corrective-action', 'dpia', 'data-breach', 'data-subject-request',
            'document', 'objective', 'incident', 'management-review', 'patch',
            'processing-activity', 'risk', 'supplier', 'threat-intelligence', 'training', 'vulnerability',
        ] as $slug) {
            yield $slug => ['/en/' . $slug . '/bulk-delete'];
        }
    }

    #[Test]
    #[DataProvider('bulkDeleteEndpoints')]
    public function rejectsRequestWithoutToken(string $url): void
    {
        $this->client->loginUser($this->admin);
        $this->postJson($url, ['ids' => [999999901]]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    #[DataProvider('bulkDeleteEndpoints')]
    public function rejectsRequestWithWrongToken(string $url): void
    {
        $this->client->loginUser($this->admin);
        $this->seedSessionCsrfToken($this->client, 'bulk_action');
        $this->postJson($url, ['ids' => [999999901], '_token' => 'not-the-session-token']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    #[DataProvider('bulkDeleteEndpoints')]
    public function validTokenReachesTheSharedHandler(string $url): void
    {
        $this->client->loginUser($this->admin);
        $token = $this->seedSessionCsrfToken($this->client, 'bulk_action');
        $this->postJson($url, ['ids' => [999999901], '_token' => $token]);

        // Token accepted → per-entity checks run → unknown id is reported.
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(0, $body['deleted']);
        self::assertStringContainsString('999999901 not found', $body['errors'][0]);
    }

    #[Test]
    public function validTokenViaHeaderIsAccepted(): void
    {
        $this->client->loginUser($this->admin);
        $token = $this->seedSessionCsrfToken($this->client, 'bulk_action');
        $this->client->request('POST', '/en/asset/bulk-delete', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], json_encode(['ids' => [999999901]]));

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    #[Test]
    public function deletesOwnTenantRowsRejectsForeignRowsAndWritesOneAuditBatch(): void
    {
        $own = $this->makeAsset('Own asset', $this->tenant);
        $foreign = $this->makeAsset('Foreign asset', $this->otherTenant);
        $this->em->flush();
        $ownId = $own->getId();
        $foreignId = $foreign->getId();

        $this->client->loginUser($this->admin);
        $token = $this->seedSessionCsrfToken($this->client, 'bulk_action');
        $this->postJson('/en/asset/bulk-delete', ['ids' => [$ownId, $foreignId], '_token' => $token]);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(1, $body['deleted']);
        self::assertCount(1, $body['errors']);
        // The tenant SQL filter already hides the foreign row ("not found");
        // BulkDeleteService's own tenant check is the second line of defence.
        self::assertStringContainsString('Asset ID ' . $foreignId . ' ', $body['errors'][0]);
        self::assertNotEmpty($body['batch_id']);

        // Raw SQL: the tenant ORM filter stays enabled in the shared kernel.
        $conn = $this->em->getConnection();
        self::assertFalse($conn->fetchOne('SELECT id FROM asset WHERE id = ?', [$ownId]));
        self::assertSame($foreignId, (int) $conn->fetchOne('SELECT id FROM asset WHERE id = ?', [$foreignId]));

        $logs = $this->em->getRepository(AuditLog::class)->findBy(['entityType' => 'Asset']);
        $batchRows = array_filter($logs, static fn (AuditLog $l): bool =>
            str_contains((string) $l->getNewValues(), $body['batch_id']));
        $actions = array_map(static fn (AuditLog $l): string => (string) $l->getAction(), $batchRows);
        sort($actions);
        self::assertSame(['bulk', 'delete'], $actions, 'one batch entry + one per-entity entry');

        foreach ($batchRows as $row) {
            $this->cleanup[] = $row;
        }
    }

    private function makeAsset(string $name, Tenant $tenant): Asset
    {
        $asset = new Asset();
        $asset->setName($name . ' ' . uniqid());
        $asset->setAssetType('hardware');
        $asset->setOwner('Owner');
        $asset->setTenant($tenant);
        $asset->setConfidentialityValue(2);
        $asset->setIntegrityValue(2);
        $asset->setAvailabilityValue(2);
        $asset->setStatus('active');
        $this->em->persist($asset);
        $this->cleanup[] = $asset;

        return $asset;
    }

    /** @param array<string, mixed> $payload */
    private function postJson(string $url, array $payload): void
    {
        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
    }
}
