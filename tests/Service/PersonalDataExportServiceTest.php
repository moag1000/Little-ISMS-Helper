<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Consent;
use App\Entity\DataSubjectRequest;
use App\Repository\ConsentRepository;
use App\Repository\DataSubjectRequestRepository;
use App\Service\PersonalDataExportService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GDPR Art. 15/20 — structured personal-data export (audit finding M-2).
 */
#[AllowMockObjectsWithoutExpectations]
class PersonalDataExportServiceTest extends TestCase
{
    #[Test]
    public function testBuildExportAggregatesSubjectRecordsAndChecklist(): void
    {
        $consent = $this->createMock(Consent::class);
        $consent->method('getId')->willReturn(7);
        $consent->method('getPurposes')->willReturn(['marketing']);
        $consent->method('getStatus')->willReturn('active');
        $consent->method('getGrantedAt')->willReturn(new DateTimeImmutable('2026-01-01'));
        $consent->method('getConsentText')->willReturn('I agree');

        $collectedRequest = $this->createMock(DataSubjectRequest::class);
        $collectedRequest->method('getId')->willReturn(99);
        $collectedRequest->method('getRequestType')->willReturn('access');
        $collectedRequest->method('getGdprArticle')->willReturn('Art. 15');
        $collectedRequest->method('getStatus')->willReturn('in_progress');

        $consentRepository = $this->createMock(ConsentRepository::class);
        // identifier match returns the consent; email match returns nothing.
        $consentRepository->method('findByDataSubject')
            ->willReturnCallback(static fn (string $key) => $key === 'cust-42' ? [$consent] : []);

        $dsrRepository = $this->createMock(DataSubjectRequestRepository::class);
        $dsrRepository->method('findBy')->willReturn([$collectedRequest]);

        $service = new PersonalDataExportService($consentRepository, $dsrRepository);

        $source = $this->createMock(DataSubjectRequest::class);
        $source->method('getId')->willReturn(1);
        $source->method('getTenant')->willReturn(null);
        $source->method('getDataSubjectEmail')->willReturn('max@example.com');
        $source->method('getDataSubjectName')->willReturn('Max Mustermann');
        $source->method('getDataSubjectIdentifier')->willReturn('cust-42');

        $export = $service->buildExport($source);

        $this->assertSame('max@example.com', $export['data_subject']['email']);
        $this->assertSame('cust-42', $export['data_subject']['identifier']);

        // Consent aggregated + mapped
        $this->assertCount(1, $export['records_held_in_tool']['consents']);
        $this->assertSame(7, $export['records_held_in_tool']['consents'][0]['id']);
        $this->assertSame(['marketing'], $export['records_held_in_tool']['consents'][0]['purposes']);

        // Request aggregated + deduplicated (findBy returns it for both email & name)
        $this->assertCount(1, $export['records_held_in_tool']['data_subject_requests']);
        $this->assertSame(99, $export['records_held_in_tool']['data_subject_requests'][0]['id']);

        // Honest scope: manual-sources checklist is shipped
        $this->assertArrayHasKey('manual_sources_checklist', $export);
        $this->assertArrayHasKey('processors', $export['manual_sources_checklist']);
    }

    #[Test]
    public function testToJsonProducesValidMachineReadableJson(): void
    {
        $service = new PersonalDataExportService(
            $this->createMock(ConsentRepository::class),
            $this->createMock(DataSubjectRequestRepository::class),
        );

        $source = $this->createMock(DataSubjectRequest::class);
        $source->method('getId')->willReturn(1);
        $source->method('getTenant')->willReturn(null);
        $source->method('getDataSubjectEmail')->willReturn('a@b.de');
        $source->method('getDataSubjectName')->willReturn(null);
        $source->method('getDataSubjectIdentifier')->willReturn(null);

        $json = $service->toJson($service->buildExport($source));

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame('a@b.de', $decoded['data_subject']['email']);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
    }
}
