<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Document;
use App\Entity\DocumentVersion;
use App\Entity\Tenant;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * F4 — DocumentVersion entity unit tests.
 */
class DocumentVersionTest extends TestCase
{
    #[Test]
    public function testDefaultValues(): void
    {
        $version = new DocumentVersion();

        self::assertSame(1, $version->getVersionNumber());
        self::assertSame('', $version->getContentHash());
        self::assertSame('', $version->getFileName());
        self::assertSame('', $version->getFilePath());
        self::assertSame(0, $version->getFileSize());
        self::assertSame('', $version->getMimeType());
        self::assertNull($version->getPublishedAt());
        self::assertNull($version->getRetentionUntil());
        self::assertNull($version->getReplacedBy());
        self::assertTrue($version->isActive());
        self::assertFalse($version->isPublished());
        self::assertInstanceOf(DateTimeImmutable::class, $version->getUploadedAt());
    }

    #[Test]
    public function testIsPublishedAfterPublishedAtSet(): void
    {
        $version = new DocumentVersion();
        self::assertFalse($version->isPublished());

        $version->setPublishedAt(new DateTimeImmutable());
        self::assertTrue($version->isPublished());
    }

    #[Test]
    public function testFileSizeFormatted(): void
    {
        $version = new DocumentVersion();
        $version->setFileSize(0);
        self::assertSame('0 B', $version->getFileSizeFormatted());

        $version->setFileSize(1024);
        self::assertStringContainsString('KB', $version->getFileSizeFormatted());

        $version->setFileSize(1024 * 1024);
        self::assertStringContainsString('MB', $version->getFileSizeFormatted());
    }

    #[Test]
    public function testSetterChaining(): void
    {
        $version = new DocumentVersion();
        $tenant = new Tenant();
        $document = new Document();
        $user = new User();
        $replacedBy = new DocumentVersion();

        $result = $version
            ->setTenant($tenant)
            ->setDocument($document)
            ->setVersionNumber(3)
            ->setContentHash('abc123')
            ->setFileName('test.pdf')
            ->setFilePath('/uploads/test.pdf')
            ->setFileSize(5000)
            ->setMimeType('application/pdf')
            ->setUploadedBy($user)
            ->setReplacedBy($replacedBy)
            ->setIsActive(false);

        self::assertSame($version, $result);
        self::assertSame($tenant, $version->getTenant());
        self::assertSame($document, $version->getDocument());
        self::assertSame(3, $version->getVersionNumber());
        self::assertSame('abc123', $version->getContentHash());
        self::assertSame('test.pdf', $version->getFileName());
        self::assertSame('/uploads/test.pdf', $version->getFilePath());
        self::assertSame(5000, $version->getFileSize());
        self::assertSame('application/pdf', $version->getMimeType());
        self::assertSame($user, $version->getUploadedBy());
        self::assertSame($replacedBy, $version->getReplacedBy());
        self::assertFalse($version->isActive());
    }

    #[Test]
    public function testVersionImmutabilityConvention(): void
    {
        // A published version must not be deleted (convention: publishedAt is set).
        $version = new DocumentVersion();
        $version->setPublishedAt(new DateTimeImmutable());

        self::assertTrue($version->isPublished(), 'Published version should be marked as immutable evidence.');
    }

    #[Test]
    public function testRetentionUntil(): void
    {
        $version = new DocumentVersion();
        $deadline = new DateTimeImmutable('+5 years');
        $version->setRetentionUntil($deadline);

        self::assertSame($deadline, $version->getRetentionUntil());
    }
}
