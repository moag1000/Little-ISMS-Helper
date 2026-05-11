<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Entity\BulkImportBatch;
use App\Entity\Tenant;
use App\Entity\User;
use App\Message\BulkImportMessage;
use App\Service\AuditLogger;
use App\Service\Import\BulkImportOrchestrator;
use App\Service\Import\DeltaCalculator;
use App\Service\Import\Dto\DeltaConfig;
use App\Service\Import\Dto\DeltaResult;
use App\Service\Import\Dto\ParsedSpreadsheet;
use App\Service\Import\EntityMapperRegistry;
use App\Service\Import\HeaderHeuristicMapper;
use App\Service\Import\Mapper\EntityMapperInterface;
use App\Service\Import\SpreadsheetParser;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Unit tests for BulkImportOrchestrator.
 *
 * Final dependencies (SpreadsheetParser, HeaderHeuristicMapper, DeltaCalculator,
 * EntityMapperRegistry) cannot be mocked directly — they are instantiated with
 * real constructors or lightweight stubs.  Non-final dependencies (AuditLogger,
 * EntityManagerInterface, MessageBusInterface) are mocked normally.
 */
#[AllowMockObjectsWithoutExpectations]
final class BulkImportOrchestratorTest extends TestCase
{
    /** @var AuditLogger&MockObject */
    private AuditLogger $auditLogger;

    /** @var EntityManagerInterface&MockObject */
    private EntityManagerInterface $em;

    /** @var MessageBusInterface&MockObject */
    private MessageBusInterface $bus;

    /** @var EntityMapperInterface&MockObject */
    private EntityMapperInterface $mapper;

    private SpreadsheetParser $parser;
    private HeaderHeuristicMapper $headerMapper;
    private EntityMapperRegistry $mapperRegistry;
    private DeltaCalculator $deltaCalculator;

    private string $uploadDir;

    private BulkImportOrchestrator $orchestrator;

    /** @var Tenant&MockObject */
    private Tenant $tenant;

    /** @var User&MockObject */
    private User $user;

    protected function setUp(): void
    {
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->em          = $this->createMock(EntityManagerInterface::class);
        $this->bus         = $this->createMock(MessageBusInterface::class);
        $this->tenant      = $this->createMock(Tenant::class);
        $this->user        = $this->createMock(User::class);
        $this->uploadDir   = sys_get_temp_dir() . '/bulk_import_test_' . uniqid('', true);

        // SpreadsheetParser, HeaderHeuristicMapper, EntityMapperRegistry and
        // DeltaCalculator are final — use real instances with lightweight stubs.
        $this->parser     = new SpreadsheetParser(new NullLogger());
        $this->headerMapper = new HeaderHeuristicMapper();

        // A mocked EntityMapperInterface (interface — mockable) supports 'Asset'.
        $this->mapper = $this->createMock(EntityMapperInterface::class);
        $this->mapper->method('supportsEntityType')
            ->willReturnCallback(fn (string $t): bool => $t === 'Asset');

        $this->mapperRegistry  = new EntityMapperRegistry([$this->mapper]);
        $this->deltaCalculator = new DeltaCalculator($this->mapperRegistry, $this->em);

        $this->orchestrator = new BulkImportOrchestrator(
            $this->parser,
            $this->headerMapper,
            $this->mapperRegistry,
            $this->deltaCalculator,
            $this->auditLogger,
            $this->em,
            $this->bus,
            $this->uploadDir,
        );
    }

    protected function tearDown(): void
    {
        // Clean up temp upload dir created during tests
        if (is_dir($this->uploadDir)) {
            array_map('unlink', glob($this->uploadDir . '/*') ?: []);
            rmdir($this->uploadDir);
        }
    }

    // -------------------------------------------------------------------------
    // Step 1: upload
    // -------------------------------------------------------------------------

    #[Test]
    public function testUploadCreatesBatchWithSha256Hash(): void
    {
        // Create a real temp file to simulate an upload
        $tmpFile = tempnam(sys_get_temp_dir(), 'bulk_import_test_');
        file_put_contents($tmpFile, 'header1,header2' . PHP_EOL . 'value1,value2');
        $expectedHash = hash_file('sha256', $tmpFile);

        $uploadedFile = $this->createMock(UploadedFile::class);
        $uploadedFile->method('getClientOriginalName')->willReturn('test.csv');
        $uploadedFile->method('getClientOriginalExtension')->willReturn('csv');
        $uploadedFile->method('move')->willReturnCallback(function (string $dir, string $name) use ($tmpFile): File {
            if (!is_dir($dir)) {
                mkdir($dir, 0750, true);
            }
            $dest = $dir . DIRECTORY_SEPARATOR . $name;
            copy($tmpFile, $dest);
            return new File($dest);
        });

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $batch = $this->orchestrator->upload($uploadedFile, 'Asset', $this->tenant, $this->user);

        $this->assertInstanceOf(BulkImportBatch::class, $batch);
        $this->assertSame('Asset', $batch->getEntityType());
        $this->assertSame(BulkImportBatch::STATUS_UPLOADED, $batch->getStatus());
        $this->assertSame(BulkImportBatch::MODE_INITIAL, $batch->getMode());
        $this->assertSame('test.csv', $batch->getSourceFileName());
        $this->assertSame($expectedHash, $batch->getSourceFileHash());
        $this->assertNotEmpty($batch->getSourceFileSize());

        unlink($tmpFile);
    }

    // -------------------------------------------------------------------------
    // Step 2: preview
    // -------------------------------------------------------------------------

    #[Test]
    public function testPreviewPersistsColumnMappingAndReturnsDeltaResult(): void
    {
        // Create a real CSV file in the upload dir so the orchestrator finds it
        mkdir($this->uploadDir, 0750, true);
        $storedFile = $this->uploadDir . '/test_file.csv';
        file_put_contents($storedFile, 'Name,assetType' . PHP_EOL . 'Server A,Hardware');
        $fileHash = hash_file('sha256', $storedFile);

        $batch = new BulkImportBatch();
        $batch->setEntityType('Asset');
        $batch->setSourceFileHash($fileHash);
        $batch->setSourceFileName('test.csv');
        $batch->setSourceFileSize('42');
        $batch->setTenant($this->tenant);

        // Mapper validates without errors so delta can categorise the row
        $this->mapper->method('validate')->willReturn(['errors' => [], 'warnings' => []]);
        $this->mapper->method('findExisting')->willReturn(null); // treat as create
        $this->mapper->method('toEntityData')->willReturn(['name' => 'Server A', 'assetType' => 'Hardware']);

        $this->em->expects($this->once())->method('flush');

        $result = $this->orchestrator->preview($batch);

        $this->assertInstanceOf(DeltaResult::class, $result);
        $this->assertSame(BulkImportBatch::STATUS_PREVIEW, $batch->getStatus());
        $this->assertIsArray($batch->getColumnMapping());
    }

    // -------------------------------------------------------------------------
    // Step 3: dispatchCommit
    // -------------------------------------------------------------------------

    #[Test]
    public function testDispatchCommitDispatchesBulkImportMessage(): void
    {
        $batch = new BulkImportBatch();
        $batch->setEntityType('Asset');
        $batch->setSourceFileHash('abc123');
        $batch->setSourceFileName('test.csv');
        $batch->setSourceFileSize('42');
        $batch->setTenant($this->tenant);

        // Simulate a persisted batch with an ID via reflection
        $reflection = new \ReflectionProperty(BulkImportBatch::class, 'id');
        $reflection->setValue($batch, 42);

        $this->em->expects($this->once())->method('flush');

        $capturedMessage = null;
        $this->bus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (object $message) use (&$capturedMessage): Envelope {
                $capturedMessage = $message;
                return new Envelope($message);
            });

        $this->orchestrator->dispatchCommit($batch);

        $this->assertSame(BulkImportBatch::STATUS_COMMITTING, $batch->getStatus());
        $this->assertInstanceOf(BulkImportMessage::class, $capturedMessage);
        $this->assertSame(42, $capturedMessage->batchId);
    }

    // -------------------------------------------------------------------------
    // Step 4: commit helpers
    // -------------------------------------------------------------------------

    /**
     * Returns a batch backed by a real CSV file in the upload dir.
     */
    private function prepareBatchWithCsvFile(array $extraRows = []): BulkImportBatch
    {
        mkdir($this->uploadDir, 0750, true);
        $storedFile = $this->uploadDir . '/test_file.csv';
        $csvContent = 'Name' . PHP_EOL;
        foreach ($extraRows as $row) {
            $csvContent .= $row . PHP_EOL;
        }
        file_put_contents($storedFile, $csvContent);
        $fileHash = hash_file('sha256', $storedFile);

        $batch = new BulkImportBatch();
        $batch->setEntityType('Asset');
        $batch->setSourceFileHash($fileHash);
        $batch->setSourceFileName('test.csv');
        $batch->setSourceFileSize((string) strlen($csvContent));
        $batch->setTenant($this->tenant);
        $batch->setColumnMapping([]);

        return $batch;
    }

    #[Test]
    public function testCommitInvokesDeltaCalculatorWithBatchMapping(): void
    {
        $columnMapping = ['Name' => 'name'];

        mkdir($this->uploadDir, 0750, true);
        $storedFile = $this->uploadDir . '/test_file.csv';
        file_put_contents($storedFile, 'Name' . PHP_EOL);
        $fileHash = hash_file('sha256', $storedFile);

        $batch = new BulkImportBatch();
        $batch->setEntityType('Asset');
        $batch->setSourceFileHash($fileHash);
        $batch->setSourceFileName('test.csv');
        $batch->setSourceFileSize('5');
        $batch->setTenant($this->tenant);
        $batch->setColumnMapping($columnMapping);

        // No data rows → delta will have empty creates/updates/unchanged/errors
        $this->mapper->method('validate')->willReturn(['errors' => [], 'warnings' => []]);

        $this->auditLogger->method('logBulk')->willReturn('test-batch-uuid');

        // Wrap wrapInTransaction so it actually executes the callback
        $this->em->method('wrapInTransaction')->willReturnCallback(fn (callable $cb) => $cb());

        // The real DeltaCalculator will call validate on the mapper with columnMapping
        // and the EntityManagerInterface mock for findBy (not needed for empty sheet)
        $this->em->method('getRepository')->willReturn(
            $this->createMock(\Doctrine\ORM\EntityRepository::class)
        );

        $this->orchestrator->commit($batch);

        // Verify column mapping was passed through (batch remains with the mapping)
        $this->assertSame($columnMapping, $batch->getColumnMapping());
        $this->assertSame(BulkImportBatch::STATUS_COMPLETED, $batch->getStatus());
    }

    #[Test]
    public function testCommitCallsAuditLoggerLogBulkAfterFlush(): void
    {
        $batch = $this->prepareBatchWithCsvFile();

        $this->mapper->method('validate')->willReturn(['errors' => [], 'warnings' => []]);
        $this->em->method('wrapInTransaction')->willReturnCallback(fn (callable $cb) => $cb());

        // logBulk MUST be called exactly once with the expected event type
        $this->auditLogger->expects($this->once())
            ->method('logBulk')
            ->with(
                'bulk_import',
                'Asset',
                $this->arrayHasKey('source_file_hash'),
                $this->isArray(),
                $this->anything(),
            )
            ->willReturn('generated-batch-uuid');

        $this->orchestrator->commit($batch);

        $this->assertSame('generated-batch-uuid', $batch->getBatchId());
    }

    #[Test]
    public function testCommitUpdatesBatchStatusToCompleted(): void
    {
        $batch = $this->prepareBatchWithCsvFile();

        $this->mapper->method('validate')->willReturn(['errors' => [], 'warnings' => []]);
        $this->auditLogger->method('logBulk')->willReturn('uuid');
        $this->em->method('wrapInTransaction')->willReturnCallback(fn (callable $cb) => $cb());

        $this->orchestrator->commit($batch);

        $this->assertSame(BulkImportBatch::STATUS_COMPLETED, $batch->getStatus());
        $this->assertNotNull($batch->getCommittedAt());
    }

    #[Test]
    public function testCommitOnExceptionSetsBatchStatusToFailedAndStoresErrorInNotes(): void
    {
        $batch = new BulkImportBatch();
        $batch->setEntityType('Asset');
        $batch->setSourceFileHash('irrelevant-hash');
        $batch->setSourceFileName('test.csv');
        $batch->setSourceFileSize('42');
        $batch->setTenant($this->tenant);
        $batch->setColumnMapping([]);

        // wrapInTransaction throws to simulate a DB/runtime failure
        $this->em->method('wrapInTransaction')
            ->willThrowException(new \RuntimeException('Simulated commit failure'));

        $this->em->method('isOpen')->willReturn(true);
        $this->em->expects($this->once())->method('flush');

        $exceptionThrown = false;
        try {
            $this->orchestrator->commit($batch);
        } catch (\RuntimeException $e) {
            $exceptionThrown = true;
            $this->assertSame('Simulated commit failure', $e->getMessage());
        }

        $this->assertTrue($exceptionThrown, 'Expected RuntimeException was not thrown.');
        $this->assertSame(BulkImportBatch::STATUS_FAILED, $batch->getStatus());
        $this->assertSame('Simulated commit failure', $batch->getNotes());
    }
}
