<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\IndustryBaselineService;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

#[AllowMockObjectsWithoutExpectations]
final class IndustryBaselineServiceTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = realpath(__DIR__ . '/../..') ?: '';
    }

    #[Test]
    public function listFrameworksReturnsAllShippedFrameworks(): void
    {
        $service = $this->makeService();
        $frameworks = $service->listFrameworksWithBaselines();

        $codes = array_column($frameworks, 'code');
        self::assertContains('ISO27001', $codes);
        self::assertContains('NIS2', $codes);
        self::assertContains('DORA', $codes);
        self::assertContains('TISAX', $codes);
        self::assertContains('GDPR', $codes);
        self::assertContains('BSI-C5', $codes);
    }

    #[Test]
    public function listBaselinesForFrameworkReturnsFiveIndustriesPerFramework(): void
    {
        $service = $this->makeService();

        foreach (['ISO27001', 'NIS2', 'DORA', 'TISAX', 'GDPR', 'BSI-C5'] as $framework) {
            $baselines = $service->listBaselinesForFramework($framework);
            self::assertGreaterThanOrEqual(5, count($baselines), "Framework {$framework} should bundle 5+ baselines");
            $industries = array_column($baselines, 'industry');
            self::assertContains('kritis', $industries, "Framework {$framework}: kritis baseline missing");
            self::assertContains('finance', $industries, "Framework {$framework}: finance baseline missing");
            self::assertContains('saas', $industries, "Framework {$framework}: saas baseline missing");
            self::assertContains('manufacturing', $industries, "Framework {$framework}: manufacturing baseline missing");
            self::assertContains('healthcare', $industries, "Framework {$framework}: healthcare baseline missing");
        }
    }

    #[Test]
    public function loadBaselineExposesTargetsAndLocalisedFields(): void
    {
        $service = $this->makeService();
        $baseline = $service->loadBaseline('ISO27001', 'iso27001-kritis');

        self::assertSame('iso27001-kritis', $baseline['id']);
        self::assertSame('kritis', $baseline['industry']);
        self::assertSame('ISO27001', $baseline['framework']);
        self::assertNotEmpty($baseline['targets']);

        $first = (string) array_key_first($baseline['targets']);
        $entry = $baseline['targets'][$first];
        self::assertArrayHasKey('maturity_target', $entry);
        self::assertArrayHasKey('reason', $entry);
        self::assertContains((string) $entry['maturity_target'], ['1', '2', '3', '4', '5']);
    }

    #[Test]
    public function loadBaselineThrowsForUnknownId(): void
    {
        $service = $this->makeService();
        $this->expectException(DomainException::class);
        $service->loadBaseline('ISO27001', 'unknown-baseline-xyz');
    }

    #[Test]
    public function applyBaselineDryRunReportsCountsWithoutWriting(): void
    {
        $framework = (new ComplianceFramework())->setCode('ISO27001');
        $req = new ComplianceRequirement();
        $req->setRequirementId('A.5.1')->setFramework($framework);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $frameworkRepo = $this->createMock(ComplianceFrameworkRepository::class);
        $frameworkRepo->method('findOneBy')->willReturn($framework);

        $reqRepo = $this->createMock(ComplianceRequirementRepository::class);
        $reqRepo->method('findOneBy')->willReturnCallback(
            static fn (array $criteria) => $criteria['requirementId'] === 'A.5.1' ? $req : null,
        );

        $service = new IndustryBaselineService(
            $em,
            $frameworkRepo,
            $reqRepo,
            new RequestStack(),
            $this->projectDir,
        );

        $tenant = new Tenant();
        $result = $service->applyBaseline($tenant, 'ISO27001', 'iso27001-kritis', dryRun: true);

        self::assertSame('iso27001-kritis', $result['baseline']);
        self::assertGreaterThan(0, $result['applied']);
        self::assertNull($req->getMaturityTarget(), 'Dry-run must not mutate the entity');
    }

    #[Test]
    public function applyBaselineWithMissingFrameworkThrows(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $frameworkRepo = $this->createMock(ComplianceFrameworkRepository::class);
        $frameworkRepo->method('findOneBy')->willReturn(null);
        $reqRepo = $this->createMock(ComplianceRequirementRepository::class);

        $service = new IndustryBaselineService(
            $em,
            $frameworkRepo,
            $reqRepo,
            new RequestStack(),
            $this->projectDir,
        );

        $this->expectException(DomainException::class);
        $service->applyBaseline(new Tenant(), 'ISO27001', 'iso27001-kritis');
    }

    #[Test]
    public function frameworkCodeWithPathTraversalIsRejected(): void
    {
        $service = $this->makeService();
        $this->expectException(DomainException::class);
        $service->loadBaseline('../etc/passwd', 'whatever');
    }

    private function makeService(): IndustryBaselineService
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $frameworkRepo = $this->createMock(ComplianceFrameworkRepository::class);
        $reqRepo = $this->createMock(ComplianceRequirementRepository::class);

        return new IndustryBaselineService(
            $em,
            $frameworkRepo,
            $reqRepo,
            new RequestStack(),
            $this->projectDir,
        );
    }
}
