<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use App\Entity\WorkflowInstance;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W7-B — entity-level coverage for the witness fields
 * added to {@see WorkflowInstance} (witnessUser + witnessedAt).
 */
final class WorkflowInstanceWitnessTest extends TestCase
{
    #[Test]
    public function testRecordWitnessPersists(): void
    {
        $instance = new WorkflowInstance();
        $witness = new User();
        $witness->setEmail('dpo@example.test');
        $witness->setFirstName('Data');
        $witness->setLastName('Officer');

        $now = new DateTimeImmutable();
        $instance->setWitnessUser($witness);
        $instance->setWitnessedAt($now);

        $this->assertSame($witness, $instance->getWitnessUser());
        $this->assertSame($now, $instance->getWitnessedAt());
        $this->assertTrue($instance->hasWitness());
    }

    #[Test]
    public function testWitnessOptional(): void
    {
        $instance = new WorkflowInstance();

        $this->assertNull($instance->getWitnessUser());
        $this->assertNull($instance->getWitnessedAt());
        $this->assertFalse($instance->hasWitness());

        // Setting only one of the two halves must NOT make hasWitness() true:
        // both signals are required for an audit-trail-quality witness record.
        $instance->setWitnessedAt(new DateTimeImmutable());
        $this->assertFalse(
            $instance->hasWitness(),
            'witnessedAt without witnessUser is not a valid witness record'
        );

        $instance->setWitnessedAt(null);
        $witness = new User();
        $instance->setWitnessUser($witness);
        $this->assertFalse(
            $instance->hasWitness(),
            'witnessUser without witnessedAt is not a valid witness record'
        );
    }
}
