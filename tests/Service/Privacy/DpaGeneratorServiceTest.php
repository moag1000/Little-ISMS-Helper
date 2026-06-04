<?php

declare(strict_types=1);

namespace App\Tests\Service\Privacy;

use App\Entity\Document;
use App\Entity\ProcessingActivity;
use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DocumentRepository;
use App\Service\AuditLogger;
use App\Service\Privacy\DpaGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Twig\Environment as TwigEnvironment;

/**
 * Unit tests for DpaGeneratorService (F32).
 *
 * Doctrine Query infrastructure is mocked — no database required.
 * Twig rendering returns a predictable stub string so we can assert
 * that the policyBody is stored and the substitution variable snapshot
 * contains the expected keys from PA + Supplier data.
 */
#[AllowMockObjectsWithoutExpectations]
class DpaGeneratorServiceTest extends TestCase
{
    private MockObject&TwigEnvironment $twig;
    private MockObject&EntityManagerInterface $em;
    private MockObject&AuditLogger $auditLogger;
    private MockObject&DocumentRepository $documentRepository;
    private DpaGeneratorService $service;

    protected function setUp(): void
    {
        $this->twig               = $this->createMock(TwigEnvironment::class);
        $this->em                 = $this->createMock(EntityManagerInterface::class);
        $this->auditLogger        = $this->createMock(AuditLogger::class);
        $this->documentRepository = $this->createMock(DocumentRepository::class);

        $this->service = new DpaGeneratorService(
            $this->twig,
            $this->em,
            $this->auditLogger,
            $this->documentRepository,
        );
    }

    #[Test]
    public function generateSetsDocumentCategoryToDpa(): void
    {
        [$supplier, $pa, $user] = $this->makeEntities();

        $this->twig
            ->method('render')
            ->willReturn('## AVV body stub');

        $this->em
            ->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(Document::class));
        $this->em
            ->expects($this->once())
            ->method('flush');

        $doc = $this->service->generate($supplier, $pa, $user);

        $this->assertSame('dpa', $doc->getCategory());
    }

    #[Test]
    public function generateStoresPolicyBody(): void
    {
        [$supplier, $pa, $user] = $this->makeEntities();

        $expectedBody = '## AVV body rendered';
        $this->twig
            ->method('render')
            ->willReturn($expectedBody);

        $doc = $this->service->generate($supplier, $pa, $user);

        $this->assertSame($expectedBody, $doc->getPolicyBody());
    }

    #[Test]
    public function generateStoresSubstitutionVariableSnapshot(): void
    {
        [$supplier, $pa, $user] = $this->makeEntities();

        $this->twig
            ->method('render')
            ->willReturn('stub body');

        $doc = $this->service->generate($supplier, $pa, $user);

        $vars = $doc->getSubstitutionVariables();
        $this->assertIsArray($vars);
        // Assert mandatory variable keys are present.
        $this->assertArrayHasKey('controller_name', $vars);
        $this->assertArrayHasKey('processor_name', $vars);
        $this->assertArrayHasKey('pa_name', $vars);
        $this->assertArrayHasKey('purposes', $vars);
        $this->assertArrayHasKey('data_categories', $vars);
        $this->assertArrayHasKey('has_third_country_transfer', $vars);
        $this->assertArrayHasKey('tom_description', $vars);
        $this->assertArrayHasKey('has_sub_processors', $vars);
    }

    #[Test]
    public function generateCallsAuditLoggerWithDpaGeneratedAction(): void
    {
        [$supplier, $pa, $user] = $this->makeEntities();

        $this->twig->method('render')->willReturn('stub');

        $this->auditLogger
            ->expects($this->once())
            ->method('logCustom');

        $this->service->generate($supplier, $pa, $user);
    }

    #[Test]
    public function generatePassesSupplierNameAsProcessorNameVariable(): void
    {
        [$supplier, $pa, $user] = $this->makeEntities();

        $capturedVars = [];
        $this->twig
            ->expects($this->once())
            ->method('render')
            ->willReturnCallback(function (string $template, array $vars) use (&$capturedVars): string {
                $capturedVars = $vars;
                return 'stub';
            });

        $this->service->generate($supplier, $pa, $user);

        $this->assertSame('Acme Processing GmbH', $capturedVars['processor_name'] ?? null);
    }

    #[Test]
    public function generateSetsThirdCountryFlagFromPa(): void
    {
        [$supplier, $pa, $user] = $this->makeEntities(thirdCountry: true);

        $capturedVars = [];
        $this->twig
            ->expects($this->once())
            ->method('render')
            ->willReturnCallback(function (string $template, array $vars) use (&$capturedVars): string {
                $capturedVars = $vars;
                return 'stub';
            });

        $this->service->generate($supplier, $pa, $user);

        $this->assertTrue($capturedVars['has_third_country_transfer'] ?? false);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @return array{0: Supplier, 1: ProcessingActivity, 2: User}
     */
    private function makeEntities(bool $thirdCountry = false): array
    {
        $tenant = new Tenant();

        $supplier = new Supplier();
        $supplier->setName('Acme Processing GmbH');
        $supplier->setTenant($tenant);
        $supplier->setAddress('Musterstraße 1, 10115 Berlin');
        $supplier->setServiceProvided('Cloud-Hosting');

        $pa = new ProcessingActivity();
        $pa->setTenant($tenant);
        $pa->setName('Kundendatenverwaltung');
        $pa->setPurposes(['Vertragserfüllung']);
        $pa->setDataSubjectCategories(['Kunden']);
        $pa->setPersonalDataCategories(['Kontaktdaten', 'Vertragsdaten']);
        $pa->setLegalBasis('contract');
        $pa->setRetentionPeriod('10 Jahre (HGB §257)');
        $pa->setTechnicalOrganizationalMeasures('TLS 1.3, AES-256 Verschlüsselung, Zugriffskontrolle, regelmäßige Datensicherungen.');
        $pa->setHasThirdCountryTransfer($thirdCountry);

        $user = new User();
        $user->setEmail('dpo@example.com');

        // Silence the em->persist/flush expectations (they have no return value).
        $this->em->method('persist');
        $this->em->method('flush');

        return [$supplier, $pa, $user];
    }
}
