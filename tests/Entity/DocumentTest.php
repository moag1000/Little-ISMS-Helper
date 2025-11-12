<?php

namespace App\Tests\Entity;

use App\Entity\Document;
use App\Entity\Control;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class DocumentTest extends TestCase
{
    public function testNewDocumentHasDefaultValues(): void
    {
        $document = new Document();

        $this->assertNull($document->getId());
        $this->assertNull($document->getTitle());
        $this->assertNull($document->getDescription());
        $this->assertNull($document->getFilename());
        $this->assertNull($document->getFilePath());
        $this->assertNull($document->getMimeType());
        $this->assertNull($document->getFileSize());
        $this->assertNull($document->getVersion());
        $this->assertNull($document->getCategory());
        $this->assertNull($document->getStatus());
        $this->assertNull($document->getOwner());
        $this->assertInstanceOf(\DateTimeImmutable::class, $document->getCreatedAt());
        $this->assertNull($document->getUpdatedAt());
        $this->assertCount(0, $document->getRelatedControls());
    }

    public function testSetAndGetTitle(): void
    {
        $document = new Document();
        $document->setTitle('Information Security Policy');

        $this->assertEquals('Information Security Policy', $document->getTitle());
    }

    public function testSetAndGetDescription(): void
    {
        $document = new Document();
        $document->setDescription('Company-wide security policy document');

        $this->assertEquals('Company-wide security policy document', $document->getDescription());
    }

    public function testSetAndGetFilename(): void
    {
        $document = new Document();
        $document->setFilename('security-policy-v1.pdf');

        $this->assertEquals('security-policy-v1.pdf', $document->getFilename());
    }

    public function testSetAndGetFilePath(): void
    {
        $document = new Document();
        $document->setFilePath('/uploads/documents/security-policy-v1.pdf');

        $this->assertEquals('/uploads/documents/security-policy-v1.pdf', $document->getFilePath());
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

    public function testSetAndGetVersion(): void
    {
        $document = new Document();
        $document->setVersion('1.0');

        $this->assertEquals('1.0', $document->getVersion());
    }

    public function testSetAndGetCategory(): void
    {
        $document = new Document();
        $document->setCategory('Policy');

        $this->assertEquals('Policy', $document->getCategory());
    }

    public function testSetAndGetStatus(): void
    {
        $document = new Document();
        $document->setStatus('approved');

        $this->assertEquals('approved', $document->getStatus());
    }

    public function testSetAndGetOwner(): void
    {
        $document = new Document();
        $user = new User();
        $user->setEmail('owner@example.com');

        $document->setOwner($user);

        $this->assertSame($user, $document->getOwner());
    }

    public function testSetUpdatedAt(): void
    {
        $document = new Document();
        $now = new \DateTimeImmutable();

        $document->setUpdatedAt($now);

        $this->assertEquals($now, $document->getUpdatedAt());
    }

    public function testAddAndRemoveRelatedControl(): void
    {
        $document = new Document();
        $control = new Control();
        $control->setTitle('Access Control');

        $this->assertCount(0, $document->getRelatedControls());

        $document->addRelatedControl($control);
        $this->assertCount(1, $document->getRelatedControls());
        $this->assertTrue($document->getRelatedControls()->contains($control));

        $document->removeRelatedControl($control);
        $this->assertCount(0, $document->getRelatedControls());
        $this->assertFalse($document->getRelatedControls()->contains($control));
    }

    public function testAddRelatedControlDoesNotDuplicate(): void
    {
        $document = new Document();
        $control = new Control();
        $control->setTitle('Access Control');

        $document->addRelatedControl($control);
        $document->addRelatedControl($control); // Add same control again

        $this->assertCount(1, $document->getRelatedControls());
    }
}
