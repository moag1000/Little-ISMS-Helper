<?php

namespace App\Tests\Entity;

use App\Entity\Process;
use App\Entity\Asset;
use App\Entity\Control;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class ProcessTest extends TestCase
{
    public function testNewProcessHasDefaultValues(): void
    {
        $process = new Process();

        $this->assertNull($process->getId());
        $this->assertNull($process->getName());
        $this->assertNull($process->getDescription());
        $this->assertNull($process->getOwner());
        $this->assertNull($process->getCriticality());
        $this->assertNull($process->getCategory());
        $this->assertInstanceOf(\DateTimeImmutable::class, $process->getCreatedAt());
        $this->assertNull($process->getUpdatedAt());
        $this->assertCount(0, $process->getAssets());
        $this->assertCount(0, $process->getControls());
    }

    public function testSetAndGetName(): void
    {
        $process = new Process();
        $process->setName('Customer Order Processing');

        $this->assertEquals('Customer Order Processing', $process->getName());
    }

    public function testSetAndGetDescription(): void
    {
        $process = new Process();
        $process->setDescription('Process for handling customer orders');

        $this->assertEquals('Process for handling customer orders', $process->getDescription());
    }

    public function testSetAndGetOwner(): void
    {
        $process = new Process();
        $user = new User();
        $user->setEmail('owner@example.com');

        $process->setOwner($user);

        $this->assertSame($user, $process->getOwner());
    }

    public function testSetAndGetCriticality(): void
    {
        $process = new Process();
        $process->setCriticality('high');

        $this->assertEquals('high', $process->getCriticality());
    }

    public function testSetAndGetCategory(): void
    {
        $process = new Process();
        $process->setCategory('Core Business');

        $this->assertEquals('Core Business', $process->getCategory());
    }

    public function testSetUpdatedAt(): void
    {
        $process = new Process();
        $now = new \DateTimeImmutable();

        $process->setUpdatedAt($now);

        $this->assertEquals($now, $process->getUpdatedAt());
    }

    public function testAddAndRemoveAsset(): void
    {
        $process = new Process();
        $asset = new Asset();
        $asset->setName('Customer Database');

        $this->assertCount(0, $process->getAssets());

        $process->addAsset($asset);
        $this->assertCount(1, $process->getAssets());
        $this->assertTrue($process->getAssets()->contains($asset));

        $process->removeAsset($asset);
        $this->assertCount(0, $process->getAssets());
        $this->assertFalse($process->getAssets()->contains($asset));
    }

    public function testAddAssetDoesNotDuplicate(): void
    {
        $process = new Process();
        $asset = new Asset();
        $asset->setName('Customer Database');

        $process->addAsset($asset);
        $process->addAsset($asset); // Add same asset again

        $this->assertCount(1, $process->getAssets());
    }

    public function testAddAndRemoveControl(): void
    {
        $process = new Process();
        $control = new Control();
        $control->setTitle('Access Control');

        $this->assertCount(0, $process->getControls());

        $process->addControl($control);
        $this->assertCount(1, $process->getControls());
        $this->assertTrue($process->getControls()->contains($control));

        $process->removeControl($control);
        $this->assertCount(0, $process->getControls());
        $this->assertFalse($process->getControls()->contains($control));
    }

    public function testAddControlDoesNotDuplicate(): void
    {
        $process = new Process();
        $control = new Control();
        $control->setTitle('Access Control');

        $process->addControl($control);
        $process->addControl($control); // Add same control again

        $this->assertCount(1, $process->getControls());
    }
}
