<?php

namespace App\Tests\Entity;

use App\Entity\Asset;
use App\Entity\CryptographicOperation;
use App\Entity\Tenant;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class CryptographicOperationTest extends TestCase
{
    public function testConstructor(): void
    {
        $operation = new CryptographicOperation();

        $this->assertNotNull($operation->getTimestamp());
        $this->assertInstanceOf(\DateTimeInterface::class, $operation->getTimestamp());
        $this->assertEquals('success', $operation->getStatus());
        $this->assertTrue($operation->isComplianceRelevant());
    }

    public function testOperationType(): void
    {
        $operation = new CryptographicOperation();

        $this->assertNull($operation->getOperationType());

        $operation->setOperationType('encrypt');
        $this->assertEquals('encrypt', $operation->getOperationType());

        $operation->setOperationType('decrypt');
        $this->assertEquals('decrypt', $operation->getOperationType());

        $operation->setOperationType('sign');
        $this->assertEquals('sign', $operation->getOperationType());

        $operation->setOperationType('verify');
        $this->assertEquals('verify', $operation->getOperationType());

        $operation->setOperationType('hash');
        $this->assertEquals('hash', $operation->getOperationType());

        $operation->setOperationType('key_generation');
        $this->assertEquals('key_generation', $operation->getOperationType());

        $operation->setOperationType('key_rotation');
        $this->assertEquals('key_rotation', $operation->getOperationType());

        $operation->setOperationType('key_deletion');
        $this->assertEquals('key_deletion', $operation->getOperationType());
    }

    public function testAlgorithm(): void
    {
        $operation = new CryptographicOperation();

        $this->assertNull($operation->getAlgorithm());

        $operation->setAlgorithm('AES-256-GCM');
        $this->assertEquals('AES-256-GCM', $operation->getAlgorithm());
    }

    public function testKeyLength(): void
    {
        $operation = new CryptographicOperation();

        $this->assertNull($operation->getKeyLength());

        $operation->setKeyLength(256);
        $this->assertEquals(256, $operation->getKeyLength());

        $operation->setKeyLength(null);
        $this->assertNull($operation->getKeyLength());
    }

    public function testKeyIdentifier(): void
    {
        $operation = new CryptographicOperation();

        $this->assertNull($operation->getKeyIdentifier());

        $operation->setKeyIdentifier('key-12345');
        $this->assertEquals('key-12345', $operation->getKeyIdentifier());

        $operation->setKeyIdentifier(null);
        $this->assertNull($operation->getKeyIdentifier());
    }

    public function testPurpose(): void
    {
        $operation = new CryptographicOperation();

        $this->assertNull($operation->getPurpose());

        $operation->setPurpose('Data encryption for customer records');
        $this->assertEquals('Data encryption for customer records', $operation->getPurpose());

        $operation->setPurpose(null);
        $this->assertNull($operation->getPurpose());
    }

    public function testDataClassification(): void
    {
        $operation = new CryptographicOperation();

        $this->assertNull($operation->getDataClassification());

        $operation->setDataClassification('confidential');
        $this->assertEquals('confidential', $operation->getDataClassification());

        $operation->setDataClassification(null);
        $this->assertNull($operation->getDataClassification());
    }

    public function testUserRelationship(): void
    {
        $operation = new CryptographicOperation();
        $user = new User();

        $this->assertNull($operation->getUser());

        $operation->setUser($user);
        $this->assertSame($user, $operation->getUser());

        $operation->setUser(null);
        $this->assertNull($operation->getUser());
    }

    public function testRelatedAssetRelationship(): void
    {
        $operation = new CryptographicOperation();
        $asset = new Asset();

        $this->assertNull($operation->getRelatedAsset());

        $operation->setRelatedAsset($asset);
        $this->assertSame($asset, $operation->getRelatedAsset());

        $operation->setRelatedAsset(null);
        $this->assertNull($operation->getRelatedAsset());
    }

    public function testApplicationComponent(): void
    {
        $operation = new CryptographicOperation();

        $this->assertNull($operation->getApplicationComponent());

        $operation->setApplicationComponent('Payment Module');
        $this->assertEquals('Payment Module', $operation->getApplicationComponent());

        $operation->setApplicationComponent(null);
        $this->assertNull($operation->getApplicationComponent());
    }

    public function testStatus(): void
    {
        $operation = new CryptographicOperation();

        $this->assertEquals('success', $operation->getStatus());

        $operation->setStatus('failure');
        $this->assertEquals('failure', $operation->getStatus());

        $operation->setStatus('pending');
        $this->assertEquals('pending', $operation->getStatus());

        $operation->setStatus('success');
        $this->assertEquals('success', $operation->getStatus());
    }

    public function testErrorMessage(): void
    {
        $operation = new CryptographicOperation();

        $this->assertNull($operation->getErrorMessage());

        $operation->setErrorMessage('Invalid key format');
        $this->assertEquals('Invalid key format', $operation->getErrorMessage());

        $operation->setErrorMessage(null);
        $this->assertNull($operation->getErrorMessage());
    }

    public function testTimestamp(): void
    {
        $operation = new CryptographicOperation();

        // Constructor sets timestamp
        $this->assertNotNull($operation->getTimestamp());

        $newTimestamp = new \DateTime('2024-06-15 10:30:00');
        $operation->setTimestamp($newTimestamp);
        $this->assertEquals($newTimestamp, $operation->getTimestamp());
    }

    public function testIpAddress(): void
    {
        $operation = new CryptographicOperation();

        $this->assertNull($operation->getIpAddress());

        $operation->setIpAddress('192.168.1.100');
        $this->assertEquals('192.168.1.100', $operation->getIpAddress());

        $operation->setIpAddress('2001:0db8:85a3:0000:0000:8a2e:0370:7334');
        $this->assertEquals('2001:0db8:85a3:0000:0000:8a2e:0370:7334', $operation->getIpAddress());

        $operation->setIpAddress(null);
        $this->assertNull($operation->getIpAddress());
    }

    public function testMetadata(): void
    {
        $operation = new CryptographicOperation();

        $this->assertNull($operation->getMetadata());

        $metadata = '{"user_agent": "Mozilla/5.0", "session_id": "abc123"}';
        $operation->setMetadata($metadata);
        $this->assertEquals($metadata, $operation->getMetadata());

        $operation->setMetadata(null);
        $this->assertNull($operation->getMetadata());
    }

    public function testComplianceRelevant(): void
    {
        $operation = new CryptographicOperation();

        $this->assertTrue($operation->isComplianceRelevant());

        $operation->setComplianceRelevant(false);
        $this->assertFalse($operation->isComplianceRelevant());

        $operation->setComplianceRelevant(true);
        $this->assertTrue($operation->isComplianceRelevant());
    }

    public function testTenantRelationship(): void
    {
        $operation = new CryptographicOperation();
        $tenant = new Tenant();

        $this->assertNull($operation->getTenant());

        $operation->setTenant($tenant);
        $this->assertSame($tenant, $operation->getTenant());

        $operation->setTenant(null);
        $this->assertNull($operation->getTenant());
    }

    public function testFluentSetters(): void
    {
        $operation = new CryptographicOperation();
        $user = new User();
        $tenant = new Tenant();
        $asset = new Asset();

        $result = $operation
            ->setOperationType('encrypt')
            ->setAlgorithm('RSA-2048')
            ->setKeyLength(2048)
            ->setStatus('success')
            ->setUser($user)
            ->setTenant($tenant)
            ->setRelatedAsset($asset)
            ->setComplianceRelevant(true);

        $this->assertSame($operation, $result);
        $this->assertEquals('encrypt', $operation->getOperationType());
        $this->assertEquals('RSA-2048', $operation->getAlgorithm());
        $this->assertEquals(2048, $operation->getKeyLength());
        $this->assertEquals('success', $operation->getStatus());
        $this->assertSame($user, $operation->getUser());
        $this->assertSame($tenant, $operation->getTenant());
        $this->assertSame($asset, $operation->getRelatedAsset());
        $this->assertTrue($operation->isComplianceRelevant());
    }
}
