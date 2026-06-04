<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\TransferImpactAssessment;
use DateTime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TransferImpactAssessment entity.
 *
 * Verifies: default values, getters/setters, helper methods,
 * and the Schrems-II / EDPB Step 5 risk logic.
 */
final class TransferImpactAssessmentTest extends TestCase
{
    #[Test]
    public function defaultStatusIsDraft(): void
    {
        $tia = new TransferImpactAssessment();
        self::assertSame('draft', $tia->getStatus());
    }

    #[Test]
    public function defaultLockVersionIsZero(): void
    {
        $tia = new TransferImpactAssessment();
        self::assertSame(0, $tia->getLockVersion());
    }

    #[Test]
    public function createdAtIsSetOnConstruction(): void
    {
        $tia = new TransferImpactAssessment();
        self::assertNotNull($tia->getCreatedAt());
    }

    #[Test]
    public function updatedAtIsSetOnConstruction(): void
    {
        $tia = new TransferImpactAssessment();
        self::assertNotNull($tia->getUpdatedAt());
    }

    #[Test]
    public function destinationCountryRoundTrip(): void
    {
        $tia = new TransferImpactAssessment();
        $tia->setDestinationCountry('US');
        self::assertSame('US', $tia->getDestinationCountry());
    }

    #[Test]
    public function recipientNameRoundTrip(): void
    {
        $tia = new TransferImpactAssessment();
        $tia->setRecipientName('AWS Inc.');
        self::assertSame('AWS Inc.', $tia->getRecipientName());
    }

    #[Test]
    public function transferMechanismRoundTrip(): void
    {
        $tia = new TransferImpactAssessment();
        $tia->setTransferMechanism('scc');
        self::assertSame('scc', $tia->getTransferMechanism());
    }

    #[Test]
    public function residualRiskLowIsAcceptable(): void
    {
        $tia = new TransferImpactAssessment();
        $tia->setResidualRiskRating('low');
        self::assertTrue($tia->isResidualRiskAcceptable());
    }

    #[Test]
    public function residualRiskMediumIsAcceptable(): void
    {
        $tia = new TransferImpactAssessment();
        $tia->setResidualRiskRating('medium');
        self::assertTrue($tia->isResidualRiskAcceptable());
    }

    #[Test]
    public function residualRiskHighIsNotAcceptable(): void
    {
        $tia = new TransferImpactAssessment();
        $tia->setResidualRiskRating('high');
        self::assertFalse($tia->isResidualRiskAcceptable());
    }

    #[Test]
    public function residualRiskNullIsNotAcceptable(): void
    {
        $tia = new TransferImpactAssessment();
        // residualRiskRating is null by default (not yet set)
        self::assertFalse($tia->isResidualRiskAcceptable());
    }

    #[Test]
    public function displayNameContainsRecipientAndCountry(): void
    {
        $tia = new TransferImpactAssessment();
        $tia->setRecipientName('Google LLC');
        $tia->setDestinationCountry('US');

        $displayName = $tia->getDisplayName();
        self::assertStringContainsString('Google LLC', $displayName);
        self::assertStringContainsString('US', $displayName);
    }

    #[Test]
    public function displayNameHandlesNullFields(): void
    {
        $tia = new TransferImpactAssessment();
        // Must not throw even when both fields are null
        $displayName = $tia->getDisplayName();
        self::assertIsString($displayName);
    }

    #[Test]
    public function statusCanBeChangedToAssessed(): void
    {
        $tia = new TransferImpactAssessment();
        $tia->setStatus('assessed');
        self::assertSame('assessed', $tia->getStatus());
    }

    #[Test]
    public function assessedAtRoundTrip(): void
    {
        $tia = new TransferImpactAssessment();
        $date = new DateTime('2026-06-01');
        $tia->setAssessedAt($date);
        self::assertSame($date, $tia->getAssessedAt());
    }

    #[Test]
    public function conclusionRoundTrip(): void
    {
        $tia = new TransferImpactAssessment();
        $tia->setConclusion('Transfer suspended pending supplementary measures.');
        self::assertSame('Transfer suspended pending supplementary measures.', $tia->getConclusion());
    }

    #[Test]
    public function supplementaryMeasuresCanBeNull(): void
    {
        $tia = new TransferImpactAssessment();
        $tia->setSupplementaryMeasures(null);
        self::assertNull($tia->getSupplementaryMeasures());
    }
}
