<?php

declare(strict_types=1);

namespace App\Tests\Controller\Bulk;

use App\Entity\AuditLog;
use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\User;
use App\Tests\Controller\Trait\SessionCsrfTokenTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bulk status-change endpoints: CSRF is mandatory, only mapped targets are
 * accepted (the posted value is never used as a raw transition name), and
 * successful runs write an AuditLogger::logBulk() batch.
 */
final class BulkStatusChangeCsrfTest extends WebTestCase
{
    use SessionCsrfTokenTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    private ?User $admin = null;
    private ?int $documentId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $uid = uniqid('bsc_', true);
        $this->tenant = (new Tenant())->setName('BulkStatus ' . $uid)->setCode('bs_' . substr($uid, -8));
        $this->em->persist($this->tenant);
        $this->admin = (new User())
            ->setEmail('bulkstatus_' . $uid . '@x.test')
            ->setFirstName('Bulk')->setLastName('Status')
            ->setRoles(['ROLE_ADMIN'])->setPassword('hashed')
            ->setTenant($this->tenant)->setIsActive(true);
        $this->em->persist($this->admin);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        try {
            if ($this->documentId !== null) {
                $conn->executeStatement('DELETE FROM audit_log WHERE entity_type = ? AND entity_id = ?', ['Document', $this->documentId]);
                $conn->executeStatement('DELETE FROM document WHERE id = ?', [$this->documentId]);
            }
            $conn->executeStatement('DELETE FROM users WHERE id = ?', [$this->admin?->getId()]);
            $conn->executeStatement('DELETE FROM tenant WHERE id = ?', [$this->tenant?->getId()]);
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function endpoints(): iterable
    {
        yield 'document' => ['/en/document/bulk-status-change'];
        yield 'processing-activity' => ['/en/processing-activity/bulk-status-change'];
    }

    #[Test]
    #[DataProvider('endpoints')]
    public function rejectsMissingToken(string $url): void
    {
        $this->client->loginUser($this->admin);
        $this->postJson($url, ['ids' => [1], 'newStatus' => 'approved']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    #[DataProvider('endpoints')]
    public function rejectsWrongToken(string $url): void
    {
        $this->client->loginUser($this->admin);
        $this->seedSessionCsrfToken($this->client, 'bulk_action');
        $this->postJson($url, ['ids' => [1], 'newStatus' => 'approved', '_token' => 'forged']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    #[DataProvider('endpoints')]
    public function rejectsTransitionNamesAndUnknownTargets(string $url): void
    {
        $this->client->loginUser($this->admin);
        foreach (['soft_delete', 'deleted', 'approve', 'restore'] as $target) {
            $token = $this->seedSessionCsrfToken($this->client, 'bulk_action');
            $this->postJson($url, ['ids' => [1], 'newStatus' => $target, '_token' => $token]);

            self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST, 'target ' . $target);
        }
    }

    #[Test]
    public function validDocumentTransitionWritesBulkAuditBatch(): void
    {
        $document = new Document();
        $unique = uniqid('bulkdoc_');
        $document->setTenant($this->tenant);
        $document->setFilename($unique . '.md');
        $document->setOriginalFilename($unique . '.md');
        $document->setMimeType('text/markdown');
        $document->setFileSize(1);
        $document->setFilePath('virtual:' . $unique);
        $document->setCategory('policy');
        $document->setStatus('draft');
        $document->setUploadedAt(new DateTimeImmutable());
        $document->setUploadedBy($this->admin);
        $this->em->persist($document);
        $this->em->flush();
        $this->documentId = $document->getId();

        $this->client->loginUser($this->admin);
        $token = $this->seedSessionCsrfToken($this->client, 'bulk_action');
        $this->postJson('/en/document/bulk-status-change', [
            'ids' => [$this->documentId, 999999902],
            'newStatus' => 'in_review',
            '_token' => $token,
        ]);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($body['ok']);
        self::assertSame(1, $body['changed']);
        self::assertSame([['id' => 999999902, 'reason' => 'not_found']], $body['rejected']);
        self::assertNotEmpty($body['batch_id']);

        $batch = array_filter(
            $this->em->getRepository(AuditLog::class)->findBy(['entityType' => 'Document', 'action' => 'bulk']),
            static fn (AuditLog $l): bool => str_contains((string) $l->getNewValues(), $body['batch_id']),
        );
        self::assertCount(1, $batch);
    }

    /** @param array<string, mixed> $payload */
    private function postJson(string $url, array $payload): void
    {
        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
    }
}
