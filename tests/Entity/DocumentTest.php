<?php

namespace App\Tests\Entity;

use App\Entity\Document;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class DocumentTest extends TestCase
{
    public function testNewDocumentHasDefaultValues(): void
    {
        $document = new Document();

        $this->assertNull($document->getId());
        $this->assertNull($document->getTenant());
        $this->assertNull($document->getFilename());
        $this->assertNull($document->getOriginalFilename());
        $this->assertNull($document->getMimeType());
        $this->assertNull($document->getFileSize());
        $this->assertNull($document->getFilePath());
        $this->assertNull($document->getCategory());
        $this->assertNull($document->getDescription());
        $this->assertNull($document->getEntityType());
        $this->assertNull($document->getEntityId());
        $this->assertNull($document->getUploadedBy());
        $this->assertInstanceOf(\DateTimeInterface::class, $document->getUploadedAt());
        $this->assertNull($document->getUpdatedAt());
        $this->assertNull($document->getSha256Hash());
        $this->assertFalse($document->isPublic());
        $this->assertFalse($document->isArchived());
        $this->assertEquals('active', $document->getStatus());
    }

    public function testSetAndGetFilename(): void
    {
        $document = new Document();
        $document->setFilename('abc123def456.pdf');

        $this->assertEquals('abc123def456.pdf', $document->getFilename());
    }

    public function testSetAndGetOriginalFilename(): void
    {
        $document = new Document();
        $document->setOriginalFilename('security-policy-v1.pdf');

        $this->assertEquals('security-policy-v1.pdf', $document->getOriginalFilename());
    }

    public function testSetAndGetMimeType(): void
    {
        $document = new Document();
        $document->setMimeType('application/pdf');

        $this->assertEquals('application/pdf', $document->getMimeType());
    }

    public function testSetAndGetFileSize(): void
    {
        $document = new Document();
        $document->setFileSize(1048576); // 1 MB

        $this->assertEquals(1048576, $document->getFileSize());
    }

    public function testSetAndGetFilePath(): void
    {
        $document = new Document();
        $document->setFilePath('/uploads/documents/abc123def456.pdf');

        $this->assertEquals('/uploads/documents/abc123def456.pdf', $document->getFilePath());
    }

    public function testSetAndGetCategory(): void
    {
        $document = new Document();
        $document->setCategory('Policy');

        $this->assertEquals('Policy', $document->getCategory());
    }

    public function testSetAndGetDescription(): void
    {
        $document = new Document();
        $document->setDescription('Company-wide security policy document');

        $this->assertEquals('Company-wide security policy document', $document->getDescription());
    }

    public function testSetAndGetEntityTypeAndId(): void
    {
        $document = new Document();
        $document->setEntityType('Risk');
        $document->setEntityId(42);

        $this->assertEquals('Risk', $document->getEntityType());
        $this->assertEquals(42, $document->getEntityId());
    }

    public function testSetAndGetUploadedBy(): void
    {
        $document = new Document();
        $user = new User();
        $user->setEmail('uploader@example.com');

        $document->setUploadedBy($user);

        $this->assertSame($user, $document->getUploadedBy());
    }

    public function testSetAndGetUploadedAt(): void
    {
        $document = new Document();
        $uploadedAt = new \DateTime('2024-01-15 10:30:00');

        $document->setUploadedAt($uploadedAt);

        $this->assertEquals($uploadedAt, $document->getUploadedAt());
    }

    public function testSetAndGetUpdatedAt(): void
    {
        $document = new Document();
        $updatedAt = new \DateTime('2024-01-20 15:45:00');

        $document->setUpdatedAt($updatedAt);

        $this->assertEquals($updatedAt, $document->getUpdatedAt());
    }

    public function testSetAndGetSha256Hash(): void
    {
        $document = new Document();
        $hash = hash('sha256', 'test content');

        $document->setSha256Hash($hash);

        $this->assertEquals($hash, $document->getSha256Hash());
    }

    public function testSetAndGetIsPublic(): void
    {
        $document = new Document();

        $this->assertFalse($document->isPublic());

        $document->setIsPublic(true);
        $this->assertTrue($document->isPublic());

        $document->setIsPublic(false);
        $this->assertFalse($document->isPublic());
    }

    public function testSetAndGetIsArchived(): void
    {
        $document = new Document();

        $this->assertFalse($document->isArchived());

        $document->setIsArchived(true);
        $this->assertTrue($document->isArchived());

        $document->setIsArchived(false);
        $this->assertFalse($document->isArchived());
    }

    public function testSetAndGetStatus(): void
    {
        $document = new Document();

        $this->assertEquals('active', $document->getStatus());

        $document->setStatus('archived');
        $this->assertEquals('archived', $document->getStatus());
    }

    public function testGetFileExtension(): void
    {
        $document = new Document();
        $document->setOriginalFilename('security-policy.pdf');

        $this->assertEquals('pdf', $document->getFileExtension());
    }

    public function testGetFileSizeFormatted(): void
    {
        $document = new Document();

        $document->setFileSize(500);
        $this->assertEquals('500 B', $document->getFileSizeFormatted());

        $document->setFileSize(2048);
        $this->assertEquals('2 KB', $document->getFileSizeFormatted());

        $document->setFileSize(1048576);
        $this->assertEquals('1 MB', $document->getFileSizeFormatted());

        $document->setFileSize(1073741824);
        $this->assertEquals('1 GB', $document->getFileSizeFormatted());
    }

    public function testIsImageReturnsTrueForImageMimeTypes(): void
    {
        $document = new Document();

        $document->setMimeType('image/png');
        $this->assertTrue($document->isImage());

        $document->setMimeType('image/jpeg');
        $this->assertTrue($document->isImage());

        $document->setMimeType('image/gif');
        $this->assertTrue($document->isImage());
    }

    public function testIsImageReturnsFalseForNonImageMimeTypes(): void
    {
        $document = new Document();

        $document->setMimeType('application/pdf');
        $this->assertFalse($document->isImage());

        $document->setMimeType('text/plain');
        $this->assertFalse($document->isImage());
    }

    public function testIsPdfReturnsTrueForPdfMimeType(): void
    {
        $document = new Document();
        $document->setMimeType('application/pdf');

        $this->assertTrue($document->isPdf());
    }

    public function testIsPdfReturnsFalseForNonPdfMimeTypes(): void
    {
        $document = new Document();

        $document->setMimeType('image/png');
        $this->assertFalse($document->isPdf());

        $document->setMimeType('text/plain');
        $this->assertFalse($document->isPdf());
    }

    public function testConstructorSetsUploadedAt(): void
    {
        $before = new \DateTimeImmutable();
        $document = new Document();
        $after = new \DateTimeImmutable();

        $uploadedAt = $document->getUploadedAt();

        $this->assertGreaterThanOrEqual($before, $uploadedAt);
        $this->assertLessThanOrEqual($after, $uploadedAt);
    }

    public function testCanStoreCompleteDocumentMetadata(): void
    {
        $document = new Document();
        $user = new User();
        $user->setEmail('admin@example.com');

        $document->setFilename('abc123.pdf');
        $document->setOriginalFilename('information-security-policy-2024.pdf');
        $document->setMimeType('application/pdf');
        $document->setFileSize(2097152); // 2 MB
        $document->setFilePath('/uploads/documents/2024/01/abc123.pdf');
        $document->setCategory('Policy');
        $document->setDescription('Annual information security policy update');
        $document->setEntityType('ComplianceFramework');
        $document->setEntityId(5);
        $document->setUploadedBy($user);
        $document->setSha256Hash(hash('sha256', 'policy content'));
        $document->setIsPublic(false);
        $document->setStatus('active');

        $this->assertEquals('abc123.pdf', $document->getFilename());
        $this->assertEquals('information-security-policy-2024.pdf', $document->getOriginalFilename());
        $this->assertEquals('application/pdf', $document->getMimeType());
        $this->assertEquals(2097152, $document->getFileSize());
        $this->assertEquals('2 MB', $document->getFileSizeFormatted());
        $this->assertEquals('/uploads/documents/2024/01/abc123.pdf', $document->getFilePath());
        $this->assertEquals('Policy', $document->getCategory());
        $this->assertEquals('Annual information security policy update', $document->getDescription());
        $this->assertEquals('ComplianceFramework', $document->getEntityType());
        $this->assertEquals(5, $document->getEntityId());
        $this->assertSame($user, $document->getUploadedBy());
        $this->assertFalse($document->isPublic());
        $this->assertFalse($document->isArchived());
        $this->assertEquals('active', $document->getStatus());
        $this->assertTrue($document->isPdf());
        $this->assertFalse($document->isImage());
        $this->assertEquals('pdf', $document->getFileExtension());
    }
}
