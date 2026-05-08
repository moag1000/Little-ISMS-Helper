<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard;

use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyReviewCadenceCheck;
use DateTimeImmutable;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class PolicyReviewCadenceCheckTest extends TestCase
{
    private DocumentRepository&MockObject $documentRepository;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepository::class);
    }

    #[Test]
    public function passesWhenAllPoliciesAreInsideReviewWindow(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $now = new DateTimeImmutable('2026-05-06');
        $policy = $this->buildPolicyMock(
            uploadedAt: new DateTimeImmutable('2026-01-01'),
            reviewIntervalMonths: 12,
        );

        $this->documentRepository->method('createQueryBuilder')->willReturn(
            $this->stubResultQueryBuilder([$policy]),
        );
        $check = new PolicyReviewCadenceCheck($this->documentRepository, $now);

        $result = $check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertSame(1, $result->details['policies_on_cadence']);
        self::assertSame(0, $result->details['policies_overdue']);
    }

    #[Test]
    public function failsWhenAtLeastOnePolicyIsOverdue(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $now = new DateTimeImmutable('2026-05-06');
        // Uploaded 2024-01-01 + 12 months = next-review 2025-01-01 < 2026-05-06.
        $policy = $this->buildPolicyMock(
            uploadedAt: new DateTimeImmutable('2024-01-01'),
            reviewIntervalMonths: 12,
        );

        $this->documentRepository->method('createQueryBuilder')->willReturn(
            $this->stubResultQueryBuilder([$policy]),
        );
        $check = new PolicyReviewCadenceCheck($this->documentRepository, $now);

        $result = $check->run($tenant);

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertSame(1, $result->details['policies_overdue']);
        self::assertNotNull($result->gap);
        self::assertSame('high', $result->gap['priority']);
        self::assertNotEmpty($result->gap['items']);
    }

    #[Test]
    public function policyWithoutTemplateProvenanceFallsBackToDefaultInterval(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $now = new DateTimeImmutable('2026-05-06');
        // No template -> default 12 months. Uploaded 2026-04-01 -> still on cadence.
        $policy = $this->createMock(Document::class);
        $policy->method('getId')->willReturn(99);
        $policy->method('getUploadedAt')->willReturn(new DateTimeImmutable('2026-04-01'));
        $policy->method('getGeneratedFromTemplate')->willReturn(null);
        $policy->method('getOriginalFilename')->willReturn('orphan-policy.pdf');

        $this->documentRepository->method('createQueryBuilder')->willReturn(
            $this->stubResultQueryBuilder([$policy]),
        );
        $check = new PolicyReviewCadenceCheck($this->documentRepository, $now);

        $result = $check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
    }

    private function buildPolicyMock(DateTimeImmutable $uploadedAt, int $reviewIntervalMonths): Document&MockObject
    {
        $template = $this->createMock(PolicyTemplate::class);
        $template->method('getReviewIntervalMonths')->willReturn($reviewIntervalMonths);

        $document = $this->createMock(Document::class);
        $document->method('getId')->willReturn(1);
        $document->method('getUploadedAt')->willReturn($uploadedAt);
        $document->method('getGeneratedFromTemplate')->willReturn($template);
        $document->method('getOriginalFilename')->willReturn('test-policy.pdf');
        return $document;
    }

    /**
     * @param list<object> $documents
     */
    private function stubResultQueryBuilder(array $documents): QueryBuilder&MockObject
    {
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getResult'])
            ->getMock();
        $query->method('getResult')->willReturn($documents);

        $qb = $this->createMock(QueryBuilder::class);
        foreach (['select', 'innerJoin', 'where', 'andWhere', 'setParameter'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('getQuery')->willReturn($query);
        return $qb;
    }
}
