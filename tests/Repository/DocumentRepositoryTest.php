<?php

namespace App\Tests\Repository;

use App\Entity\Document;
use App\Repository\DocumentRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class DocumentRepositoryTest extends KernelTestCase
{
    private DocumentRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = static::getContainer()->get(DocumentRepository::class);
    }

    public function testRepositoryExists(): void
    {
        $this->assertInstanceOf(DocumentRepository::class, $this->repository);
    }

    public function testFindByType(): void
    {
        // This test would require database setup with test fixtures
        // For now, just testing the method exists and returns an array
        $result = $this->repository->findByType('asset');
        $this->assertIsArray($result);
    }

    public function testGetStatistics(): void
    {
        $stats = $this->repository->getStatistics();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('by_type', $stats);
        $this->assertArrayHasKey('total_size', $stats);
    }
}
