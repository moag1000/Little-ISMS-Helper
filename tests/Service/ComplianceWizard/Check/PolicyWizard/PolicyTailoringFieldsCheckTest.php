<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard;

use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyTailoringFieldsCheck;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class PolicyTailoringFieldsCheckTest extends TestCase
{
    private DocumentRepository&MockObject $documentRepository;
    private PolicyTailoringFieldsCheck $check;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->check = new PolicyTailoringFieldsCheck($this->documentRepository);
    }

    #[Test]
    public function passesWhenEveryRequiredVariableHasANonEmptyValue(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $policy = $this->buildPolicyMock(
            requiredVariables: [
                ['key' => 'organisation_name', 'required' => true],
                ['key' => 'ciso_name', 'required' => true],
                ['key' => 'review_cadence', 'required' => true],
            ],
            substitutionVariables: [
                'organisation_name' => 'ACME GmbH',
                'ciso_name' => 'Erika Mustermann',
                'review_cadence' => '12',
            ],
        );

        $this->documentRepository->method('createQueryBuilder')->willReturn(
            $this->stubResultQueryBuilder([$policy]),
        );

        $result = $this->check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertSame(1, $result->details['policies_complete']);
    }

    #[Test]
    public function failsWhenMandatoryVariableIsEmptyOrMissing(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $policy = $this->buildPolicyMock(
            requiredVariables: [
                ['key' => 'organisation_name', 'required' => true],
                ['key' => 'ciso_name', 'required' => true],
                ['key' => 'review_cadence', 'required' => true],
            ],
            // ciso_name missing entirely; review_cadence whitespace-only.
            substitutionVariables: [
                'organisation_name' => 'ACME GmbH',
                'review_cadence' => '   ',
            ],
        );

        $this->documentRepository->method('createQueryBuilder')->willReturn(
            $this->stubResultQueryBuilder([$policy]),
        );

        $result = $this->check->run($tenant);

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertSame(1, $result->details['policies_incomplete']);
        self::assertNotNull($result->gap);
        self::assertSame('medium', $result->gap['priority']);
        self::assertNotEmpty($result->gap['items']);
        self::assertContains('ciso_name', $result->gap['items'][0]['missing_fields']);
        self::assertContains('review_cadence', $result->gap['items'][0]['missing_fields']);
    }

    #[Test]
    public function ignoresOptionalVariablesAndPasses(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $policy = $this->buildPolicyMock(
            requiredVariables: [
                ['key' => 'organisation_name', 'required' => true],
                // Optional — empty value MUST NOT trigger a fail.
                ['key' => 'commercial_register', 'required' => false],
            ],
            substitutionVariables: [
                'organisation_name' => 'ACME GmbH',
            ],
        );

        $this->documentRepository->method('createQueryBuilder')->willReturn(
            $this->stubResultQueryBuilder([$policy]),
        );

        $result = $this->check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
    }

    /**
     * @param list<array<string, mixed>>  $requiredVariables
     * @param array<string, mixed>        $substitutionVariables
     */
    private function buildPolicyMock(array $requiredVariables, array $substitutionVariables): Document&MockObject
    {
        $template = $this->createMock(PolicyTemplate::class);
        $template->method('getRequiredVariables')->willReturn($requiredVariables);

        $document = $this->createMock(Document::class);
        $document->method('getId')->willReturn(1);
        $document->method('getOriginalFilename')->willReturn('tailoring-test.pdf');
        $document->method('getGeneratedFromTemplate')->willReturn($template);
        $document->method('getSubstitutionVariables')->willReturn($substitutionVariables);
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
