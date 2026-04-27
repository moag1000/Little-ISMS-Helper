<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Entity\ImportRowEvent;
use App\Entity\ImportSession;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ImportRowEventRepository;
use App\Service\Import\ImportSessionRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for ImportSessionRecorder (ISB MINOR-1).
 *
 * Verifies:
 *   - SHA-256 hash is computed correctly for the uploaded file
 *   - Per-row recording aggregates counters via closeSession()
 *   - JSON payloads exceeding 4 KB are truncated (MAX_PAYLOAD_BYTES)
 */
final class ImportSessionRecorderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ImportSessionRecorder $recorder;
    private Tenant $tenant;
    private User $user;
    private string $fixtureFile = '';

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $this->em = $em;
        /** @var ImportSessionRecorder $recorder */
        $recorder = $container->get(ImportSessionRecorder::class);
        $this->recorder = $recorder;

        $this->em->getConnection()->beginTransaction();

        $suffix = substr((string) bin2hex(random_bytes(4)), 0, 8);

        $this->tenant = (new Tenant())
            ->setCode('isr-' . $suffix)
            ->setName('ImportSessionRecorder Test ' . $suffix);
        $this->em->persist($this->tenant);

        $this->user = (new User())
            ->setEmail('isr-' . $suffix . '@example.test')
            ->setFirstName('Im')
            ->setLastName('Porter')
            ->setRoles(['ROLE_ADMIN'])
            ->setTenant($this->tenant)
            ->setAuthProvider('local');
        $this->em->persist($this->user);
        $this->em->flush();

        // Write a small deterministic fixture file into var/test-uploads/.
        $dir = sys_get_temp_dir() . '/lih-isr-test';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $this->fixtureFile = $dir . '/sample-' . $suffix . '.csv';
        file_put_contents(
            $this->fixtureFile,
            "source_framework,target_framework\nISO27001,NIS2\nISO27001,DORA\n",
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $connection = $this->em->getConnection();
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->em->clear();
        }
        if ($this->fixtureFile !== '' && is_file($this->fixtureFile)) {
            @unlink($this->fixtureFile);
        }

        parent::tearDown();
    }

    #[Test]
    public function testOpenSessionComputesSha256(): void
    {
        $expected = hash_file('sha256', $this->fixtureFile);

        $session = $this->recorder->openSession(
            $this->fixtureFile,
            ImportSession::FORMAT_CSV,
            'sample.csv',
            $this->user,
            $this->tenant,
        );

        self::assertSame($expected, $session->getFileSha256());
        self::assertGreaterThan(0, $session->getFileSizeBytes());
        self::assertSame(ImportSession::STATUS_PREVIEW, $session->getStatus());
        self::assertSame('sample.csv', $session->getOriginalFilename());
    }

    #[Test]
    public function testRecordRowAggregatesCountsOnClose(): void
    {
        $session = $this->recorder->openSession(
            $this->fixtureFile,
            ImportSession::FORMAT_CSV,
            'sample.csv',
            $this->user,
            $this->tenant,
        );

        // 3 rows: 1 import, 1 skip, 1 error (matches plan assertion).
        $this->recorder->recordRow(
            $session, 1, ImportRowEvent::DECISION_IMPORT,
            'ComplianceMapping', 42,
            null, ['mapping_percentage' => 80], ['row' => 1], null,
        );
        $this->recorder->recordRow(
            $session, 2, ImportRowEvent::DECISION_SKIP,
            null, null,
            null, null, ['row' => 2], 'column count mismatch',
        );
        $this->recorder->recordRow(
            $session, 3, ImportRowEvent::DECISION_ERROR,
            null, null,
            null, null, ['row' => 3], 'framework not found',
        );

        $this->recorder->closeSession($session, ImportSession::STATUS_COMMITTED);

        self::assertSame(ImportSession::STATUS_COMMITTED, $session->getStatus());
        self::assertNotNull($session->getCommittedAt());
        self::assertSame(1, $session->getRowCountImported(), 'DECISION_IMPORT should map to rowCountImported');
        self::assertSame(0, $session->getRowCountSuperseded(), 'no DECISION_UPDATE emitted');
        self::assertSame(2, $session->getRowCountSkipped(), 'skip + error roll up into rowCountSkipped');
        self::assertSame(3, $session->getRowCountTotal());

        /** @var ImportRowEventRepository $repo */
        $repo = $this->em->getRepository(ImportRowEvent::class);
        $byTarget = $repo->findByTarget('ComplianceMapping', 42);
        self::assertCount(1, $byTarget);
        self::assertSame(1, $byTarget[0]->getLineNumber());
        self::assertSame(ImportRowEvent::DECISION_IMPORT, $byTarget[0]->getDecision());
    }

    #[Test]
    public function testJsonPayloadTruncatedAtFourKb(): void
    {
        $session = $this->recorder->openSession(
            $this->fixtureFile,
            ImportSession::FORMAT_CSV,
            'sample.csv',
            $this->user,
            $this->tenant,
        );

        // Build a payload that serialises well beyond 4 KB.
        $giant = ['blob' => str_repeat('x', 10_000)];
        $event = $this->recorder->recordRow(
            $session, 1, ImportRowEvent::DECISION_IMPORT,
            'ComplianceMapping', 1,
            null, $giant, $giant, null,
        );

        // Force flush so the string is what will be persisted.
        $this->em->flush();

        $after = $event->getAfterState();
        self::assertNotNull($after);
        self::assertLessThanOrEqual(
            ImportSessionRecorder::MAX_PAYLOAD_BYTES,
            strlen($after),
            'afterState must be truncated at or below MAX_PAYLOAD_BYTES',
        );
        self::assertStringEndsWith('...[truncated]', $after);

        $raw = $event->getSourceRowRaw();
        self::assertNotNull($raw);
        self::assertLessThanOrEqual(ImportSessionRecorder::MAX_PAYLOAD_BYTES, strlen($raw));
    }
}
