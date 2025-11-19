<?php

namespace App\Tests\Entity;

use App\Entity\SystemSettings;
use PHPUnit\Framework\TestCase;

class SystemSettingsTest extends TestCase
{
    public function testConstructor(): void
    {
        $setting = new SystemSettings();

        $this->assertNotNull($setting->getCreatedAt());
        $this->assertInstanceOf(\DateTimeInterface::class, $setting->getCreatedAt());
        $this->assertFalse($setting->isEncrypted());
    }

    public function testGettersAndSetters(): void
    {
        $setting = new SystemSettings();

        $setting->setCategory('email');
        $this->assertEquals('email', $setting->getCategory());

        $setting->setKey('smtp_host');
        $this->assertEquals('smtp_host', $setting->getKey());

        $setting->setDescription('SMTP server hostname');
        $this->assertEquals('SMTP server hostname', $setting->getDescription());
    }

    public function testValueStorage(): void
    {
        $setting = new SystemSettings();

        $this->assertNull($setting->getValue());

        $value = ['host' => 'smtp.example.com', 'port' => 587];
        $setting->setValue($value);

        $this->assertEquals($value, $setting->getValue());
    }

    public function testSetValueUpdatesTimestamp(): void
    {
        $setting = new SystemSettings();

        $this->assertNull($setting->getUpdatedAt());

        $setting->setValue('test_value');

        $this->assertNotNull($setting->getUpdatedAt());
        $this->assertInstanceOf(\DateTimeInterface::class, $setting->getUpdatedAt());
    }

    public function testEncryptedValue(): void
    {
        $setting = new SystemSettings();

        $this->assertNull($setting->getEncryptedValue());
        $this->assertFalse($setting->isEncrypted());

        $setting->setEncryptedValue('encrypted_string');
        $this->assertEquals('encrypted_string', $setting->getEncryptedValue());

        $setting->setIsEncrypted(true);
        $this->assertTrue($setting->isEncrypted());
    }

    public function testSetEncryptedValueUpdatesTimestamp(): void
    {
        $setting = new SystemSettings();

        $this->assertNull($setting->getUpdatedAt());

        $setting->setEncryptedValue('encrypted');

        $this->assertNotNull($setting->getUpdatedAt());
        $this->assertInstanceOf(\DateTimeInterface::class, $setting->getUpdatedAt());
    }

    public function testUpdatedBy(): void
    {
        $setting = new SystemSettings();

        $this->assertNull($setting->getUpdatedBy());

        $setting->setUpdatedBy('admin@example.com');
        $this->assertEquals('admin@example.com', $setting->getUpdatedBy());
    }

    public function testTimestamps(): void
    {
        $setting = new SystemSettings();

        // createdAt set in constructor
        $this->assertNotNull($setting->getCreatedAt());

        $now = new \DateTimeImmutable();
        $setting->setUpdatedAt($now);
        $this->assertEquals($now, $setting->getUpdatedAt());
    }
}
