<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Asset;
use App\Entity\DataProtectionImpactAssessment;
use App\Entity\Tenant;
use App\Repository\AssetRepository;
use App\Repository\DataProtectionImpactAssessmentRepository;
use App\Service\AiAgentInventoryService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class AiAgentInventoryServiceTest extends TestCase
{
    private function makeService(): AiAgentInventoryService
    {
        return new AiAgentInventoryService($this->createMock(AssetRepository::class));
    }

    private function makeAgent(): Asset
    {
        $agent = new Asset();
        $agent->setAssetType('ai_agent');
        return $agent;
    }

    public function testCompletenessForEmptyAgentIsZero(): void
    {
        $result = $this->makeService()->complianceCompleteness($this->makeAgent());

        self::assertSame(0, $result['filled']);
        self::assertSame(9, $result['total']);
        self::assertSame(0.0, $result['percentage']);
        self::assertCount(9, $result['missing']);
    }

    public function testCompletenessForFullyDocumentedHighRiskAgent(): void
    {
        $agent = $this->makeAgent();
        $agent->setAiAgentClassification('high_risk');
        $agent->setAiAgentPurpose('Code-Vorschläge in der CI-Pipeline');
        $agent->setAiAgentDataSources('Eigene Repos + öffentliche LLM-Trainingsdaten');
        $agent->setAiAgentOversightMechanism('PR-Review durch 4-Augen-Prinzip');
        $agent->setAiAgentProvider('Anthropic');
        $agent->setAiAgentModelVersion('claude-opus-4');
        $agent->setAiAgentCapabilityScope(['read:repo', 'comment:pr']);
        $agent->setAiAgentThreatModelDocId(42);
        $agent->setAiAgentExtensionAllowlist(['mcp-server-github']);

        $result = $this->makeService()->complianceCompleteness($agent);

        self::assertSame(9, $result['filled']);
        self::assertSame(100.0, $result['percentage']);
        self::assertEmpty($result['missing']);
    }

    public function testCompletenessIgnoresNonAiAssets(): void
    {
        $asset = new Asset();
        $asset->setAssetType('hardware');
        $result = $this->makeService()->complianceCompleteness($asset);

        self::assertSame(0, $result['total']);
        self::assertSame(0.0, $result['percentage']);
    }

    public function testEmptyArrayCounted_AsMissing(): void
    {
        $agent = $this->makeAgent();
        $agent->setAiAgentCapabilityScope([]);  // Leeres Array zählt als nicht ausgefüllt
        $agent->setAiAgentExtensionAllowlist(['plugin-x']);  // Eines da

        $result = $this->makeService()->complianceCompleteness($agent);
        // 1 von 9 Feldern befüllt
        self::assertSame(1, $result['filled']);
    }

    public function testIsAiAgentHelper(): void
    {
        $agent = new Asset();
        $agent->setAssetType('ai_agent');
        self::assertTrue($agent->isAiAgent());

        $other = new Asset();
        $other->setAssetType('software');
        self::assertFalse($other->isAiAgent());
    }

    public function testInvalidClassificationIsRejectedByEntityValidator(): void
    {
        // Validator-Constraint testen: Choice-Liste muss greifen
        $allowed = AiAgentInventoryService::RISK_CLASSIFICATIONS;
        self::assertContains('prohibited', $allowed);
        self::assertContains('high_risk', $allowed);
        self::assertContains('limited_risk', $allowed);
        self::assertContains('minimal_risk', $allowed);
        self::assertCount(4, $allowed);
    }

    public function testCompletenessMissingListContainsLegalReferences(): void
    {
        $agent = $this->makeAgent();
        $agent->setAiAgentClassification('high_risk');  // Ein Feld befüllt

        $result = $this->makeService()->complianceCompleteness($agent);

        self::assertSame(1, $result['filled']);
        // 8 Felder fehlen, jedes mit Rechts-Referenz
        $allMissing = implode('|', $result['missing']);
        self::assertStringContainsString('EU AI Act', $allMissing);
        self::assertStringContainsString('MHC-13', $allMissing);
        self::assertStringContainsString('ISO 42001', $allMissing);
    }

    /**
     * EU AI Act Art. 9 + DSGVO Art. 35: Hochrisiko-AI-Agents ohne verknüpfte
     * DPIA gelten als unvollständig dokumentiert — auch wenn alle 9
     * Pflichtfelder befüllt sind.
     *
     * Quelle: Peddi, R. (2026). MRIS v1.5 MHC-13. Lizenz: CC BY 4.0.
     */
    public function testFindHighRiskWithoutDpiaCountsAsIncomplete(): void
    {
        $tenant = new Tenant();

        // Vollständig dokumentierter Hochrisiko-Agent ohne DPIA
        $agent = $this->makeAgent();
        $agent->setTenant($tenant);
        $agent->setAiAgentClassification('high_risk');
        $agent->setAiAgentPurpose('Code-Vorschläge');
        $agent->setAiAgentDataSources('Repos');
        $agent->setAiAgentOversightMechanism('PR-Review');
        $agent->setAiAgentProvider('Anthropic');
        $agent->setAiAgentModelVersion('claude-opus-4');
        $agent->setAiAgentCapabilityScope(['read:repo']);
        $agent->setAiAgentThreatModelDocId(42);
        $agent->setAiAgentExtensionAllowlist(['mcp']);

        $assetRepo = $this->createMock(AssetRepository::class);
        $assetRepo->method('findBy')->willReturn([$agent]);

        $dpiaRepo = $this->createMock(DataProtectionImpactAssessmentRepository::class);
        $dpiaRepo->method('findBy')->willReturn([]); // keine DPIA verknüpft

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')
            ->with(DataProtectionImpactAssessment::class)
            ->willReturn($dpiaRepo);

        $service = new AiAgentInventoryService($assetRepo, $em);
        $result = $service->findHighRiskWithIncompleteDocumentation($tenant);

        self::assertCount(1, $result, 'High-risk agent without DPIA must count as incomplete');
        self::assertSame($agent, $result[0]);
    }
}
