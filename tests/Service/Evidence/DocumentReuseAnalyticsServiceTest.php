<?php

declare(strict_types=1);

namespace App\Tests\Service\Evidence;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Repository\ControlRepository;
use App\Service\Evidence\DocumentReuseAnalyticsService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * F4 — DocumentReuseAnalyticsService unit tests.
 */
class DocumentReuseAnalyticsServiceTest extends TestCase
{
    private DocumentReuseAnalyticsService $service;
    private EntityManagerInterface $em;
    private ControlRepository $controlRepo;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->controlRepo = $this->createMock(ControlRepository::class);

        $this->service = new DocumentReuseAnalyticsService(
            $this->em,
            $this->controlRepo,
        );
    }

    #[Test]
    public function testGetReuseFactorForDocumentReturnsZerosOnDbError(): void
    {
        $document = $this->createMock(Document::class);
        $document->method('getId')->willReturn(1);

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willThrowException(new \Exception('DB error'));
        $this->em->method('getConnection')->willReturn($conn);

        $result = $this->service->getReuseFactorForDocument($document);

        self::assertSame(0, $result['control_count']);
        self::assertSame(0, $result['framework_count']);
        self::assertSame('', $result['label']);
    }

    #[Test]
    public function testGetReuseFactorLabelFormatsCorrectly(): void
    {
        $document = $this->createMock(Document::class);
        $document->method('getId')->willReturn(1);

        $conn = $this->createMock(Connection::class);
        // Return 5 for first query (control count), 0 for second (framework count)
        $conn->method('fetchOne')->willReturnOnConsecutiveCalls('5', '0');
        $this->em->method('getConnection')->willReturn($conn);

        $result = $this->service->getReuseFactorForDocument($document);

        self::assertSame(5, $result['control_count']);
        self::assertStringContainsString('controls', $result['label']);
    }

    #[Test]
    public function testGetReuseFactorLabelEmptyWhenNoUsage(): void
    {
        $document = $this->createMock(Document::class);
        $document->method('getId')->willReturn(1);

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn('0');
        $this->em->method('getConnection')->willReturn($conn);

        $result = $this->service->getReuseFactorForDocument($document);

        self::assertSame('', $result['label']);
    }
}
