<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Document;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ComplianceRequirementFulfillmentEvidenceTest extends TestCase
{
    #[Test]
    public function evidenceDocumentsAndVerificationAreSettable(): void
    {
        $f = new ComplianceRequirementFulfillment();
        $doc = new Document();
        $f->addEvidenceDocument($doc);
        self::assertTrue($f->getEvidenceDocuments()->contains($doc));
        $f->addEvidenceDocument($doc); // idempotent
        self::assertCount(1, $f->getEvidenceDocuments());
        $when = new DateTimeImmutable('2026-01-01');
        $user = new User();
        $f->setVerifiedAt($when)->setVerifiedBy($user);
        self::assertSame($when, $f->getVerifiedAt());
        self::assertSame($user, $f->getVerifiedBy());
    }
}
