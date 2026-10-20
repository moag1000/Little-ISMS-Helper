<?php

declare(strict_types=1);

namespace App\Tests\Controller\Bulk;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\User;
use App\Tests\Controller\Trait\SessionCsrfTokenTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Document bulk delete runs the same `soft_delete` lifecycle transition as
 * the single delete. It used to hard-delete the rows and unlink the files.
 */
final class DocumentBulkSoftDeleteTest extends WebTestCase
{
    use SessionCsrfTokenTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    private ?User $admin = null;
    /** @var list<int> */
    private array $documentIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $uid = uniqid('dbsd_', true);
        $this->tenant = (new Tenant())->setName('DocBulk ' . $uid)->setCode('db_' . substr($uid, -8));
        $this->em->persist($this->tenant);
        $this->admin = (new User())
            ->setEmail('docbulk_' . $uid . '@x.test')
            ->setFirstName('Doc')->setLastName('Bulk')
            ->setRoles(['ROLE_ADMIN'])->setPassword('hashed')
            ->setTenant($this->tenant)->setIsActive(true);
        $this->em->persist($this->admin);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        try {
            foreach ($this->documentIds as $id) {
                $conn->executeStatement('DELETE FROM audit_log WHERE entity_type = ? AND entity_id = ?', ['Document', $id]);
                $conn->executeStatement('DELETE FROM document WHERE id = ?', [$id]);
            }
            $conn->executeStatement('DELETE FROM users WHERE id = ?', [$this->admin?->getId()]);
            $conn->executeStatement('DELETE FROM tenant WHERE id = ?', [$this->tenant?->getId()]);
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    #[Test]
    public function bulkDeleteSoftDeletesLikeTheSingleDelete(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'dbsd');
        self::assertIsString($file);
        $bulk = $this->document('bulk', $file);
        $single = $this->document('single', null);

        $this->client->loginUser($this->admin);
        $token = $this->seedSessionCsrfToken($this->client, 'bulk_action');
        $this->client->request(
            'POST',
            '/en/document/bulk-delete',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$bulk->getId()], '_token' => $token]),
        );
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(1, $body['deleted']);
        self::assertNotEmpty($body['batch_id']);

        $singleToken = $this->seedSessionCsrfToken($this->client, 'delete' . $single->getId());
        $this->client->request('POST', '/en/document/' . $single->getId() . '/delete', ['_token' => $singleToken]);
        self::assertResponseRedirects();

        $this->em->clear();
        $bulkRow = $this->em->find(Document::class, $bulk->getId());
        $singleRow = $this->em->find(Document::class, $single->getId());
        self::assertNotNull($bulkRow, 'bulk delete must not remove the row');
        self::assertSame('deleted', $bulkRow->getStatus());
        self::assertSame($singleRow?->getStatus(), $bulkRow->getStatus());
        self::assertFileExists($file, 'bulk delete must keep the stored file');
        @unlink($file);

        // Both vanish from the list in the same way.
        $this->client->request('GET', '/en/document');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString((string) $bulk->getOriginalFilename(), $html);
        self::assertStringNotContainsString((string) $single->getOriginalFilename(), $html);
    }

    private function document(string $label, ?string $filePath): Document
    {
        $unique = uniqid('dbsd_' . $label . '_');
        $document = new Document();
        $document->setTenant($this->tenant);
        $document->setFilename($unique . '.md');
        $document->setOriginalFilename($unique . '.md');
        $document->setMimeType('text/markdown');
        $document->setFileSize(1);
        $document->setFilePath($filePath ?? 'virtual:' . $unique);
        $document->setCategory('policy');
        $document->setStatus('draft');
        $document->setUploadedAt(new DateTimeImmutable());
        $document->setUploadedBy($this->admin);
        $this->em->persist($document);
        $this->em->flush();
        $this->documentIds[] = (int) $document->getId();

        return $document;
    }
}
