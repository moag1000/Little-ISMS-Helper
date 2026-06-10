<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Entity\ComplianceFramework;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\Import\BsiKompendiumXmlImporter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * WS-1 review fix: verify BsiKompendiumXmlImporter writes 'hoch' (not 'kern')
 * for H-type requirements.
 *
 * 'kern' is a BSI Vorgehensweise (level/approach), NOT a tier value.
 * The canonical tier vocabulary for absicherungsStufe is: basis / standard / hoch.
 */
#[AllowMockObjectsWithoutExpectations]
final class BsiKompendiumXmlImporterTest extends TestCase
{
    /** @var MockObject&EntityManagerInterface */
    private MockObject $entityManager;

    /** @var MockObject&ComplianceFrameworkRepository */
    private MockObject $frameworkRepo;

    /** @var MockObject&ComplianceRequirementRepository */
    private MockObject $requirementRepo;

    private BsiKompendiumXmlImporter $importer;

    protected function setUp(): void
    {
        $this->entityManager  = $this->createMock(EntityManagerInterface::class);
        $this->frameworkRepo  = $this->createMock(ComplianceFrameworkRepository::class);
        $this->requirementRepo = $this->createMock(ComplianceRequirementRepository::class);

        $this->importer = new BsiKompendiumXmlImporter(
            $this->entityManager,
            $this->frameworkRepo,
            $this->requirementRepo,
        );
    }

    /**
     * WS-1 vocabulary fix: H-type requirements MUST be stored with
     * absicherungsStufe = 'hoch', NOT 'kern'.
     */
    #[Test]
    public function hTypeRequirementIsStoredWithHochNotKern(): void
    {
        $framework = new ComplianceFramework();
        $framework->setCode('BSI_GRUNDSCHUTZ');
        $framework->setName('BSI IT-Grundschutz');
        $framework->setApplicableIndustry('all');
        $framework->setRegulatoryBody('BSI');
        $framework->setMandatory(false);

        $this->frameworkRepo->method('findOneBy')->willReturn($framework);
        $this->requirementRepo->method('findOneBy')->willReturn(null);

        $persistedRequirements = [];
        $this->entityManager
            ->method('persist')
            ->willReturnCallback(function ($entity) use (&$persistedRequirements): void {
                $persistedRequirements[] = $entity;
            });
        $this->entityManager->method('flush');  // void return

        $xml = $this->buildMinimalXml([
            ['id' => 'ISMS.1.A1', 'title' => 'Basis-Anforderung', 'classification' => 'B'],
            ['id' => 'ISMS.1.A2', 'title' => 'Standard-Anforderung', 'classification' => 'S'],
            ['id' => 'ISMS.1.A3', 'title' => 'Erhöhter-Schutzbedarf', 'classification' => 'H'],
        ]);

        $result = $this->importer->import($xml, persist: true);

        self::assertSame(3, $result['created'], 'All 3 requirements must be created.');
        self::assertEmpty($result['errors'], 'No errors expected.');

        // Find persisted requirements by requirement ID
        $stufeByReqId = [];
        foreach ($persistedRequirements as $req) {
            if (method_exists($req, 'getRequirementId') && method_exists($req, 'getAbsicherungsStufe')) {
                $stufeByReqId[$req->getRequirementId()] = $req->getAbsicherungsStufe();
            }
        }

        self::assertSame('basis', $stufeByReqId['ISMS.1.A1'], 'B-type must map to basis.');
        self::assertSame('standard', $stufeByReqId['ISMS.1.A2'], 'S-type must map to standard.');
        self::assertSame(
            'hoch',
            $stufeByReqId['ISMS.1.A3'],
            'H-type must map to hoch (NOT kern — kern is a Vorgehensweise, not a tier).'
        );
        self::assertNotSame(
            'kern',
            $stufeByReqId['ISMS.1.A3'],
            'H-type must NOT be stored as kern — vocabulary bug fixed in WS-1.'
        );
    }

    #[Test]
    public function bAndSTypeRequirementsStoredWithCorrectTier(): void
    {
        $framework = new ComplianceFramework();
        $framework->setCode('BSI_GRUNDSCHUTZ');
        $framework->setName('BSI IT-Grundschutz');
        $framework->setApplicableIndustry('all');
        $framework->setRegulatoryBody('BSI');
        $framework->setMandatory(false);

        $this->frameworkRepo->method('findOneBy')->willReturn($framework);
        $this->requirementRepo->method('findOneBy')->willReturn(null);

        $persistedRequirements = [];
        $this->entityManager
            ->method('persist')
            ->willReturnCallback(function ($entity) use (&$persistedRequirements): void {
                $persistedRequirements[] = $entity;
            });
        $this->entityManager->method('flush');  // void return

        $xml = $this->buildMinimalXml([
            ['id' => 'SYS.1.1.A1', 'title' => 'Allgemeiner Server', 'classification' => 'B'],
            ['id' => 'SYS.1.1.A2', 'title' => 'Haertung', 'classification' => 'S'],
        ]);

        $result = $this->importer->import($xml, persist: true);

        self::assertSame(2, $result['created']);

        $anforderungsTypByReqId = [];
        foreach ($persistedRequirements as $req) {
            if (method_exists($req, 'getRequirementId') && method_exists($req, 'getAnforderungsTyp')) {
                $anforderungsTypByReqId[$req->getRequirementId()] = $req->getAnforderungsTyp();
            }
        }

        self::assertSame('muss', $anforderungsTypByReqId['SYS.1.1.A1']);
        self::assertSame('sollte', $anforderungsTypByReqId['SYS.1.1.A2']);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a minimal BSI DocBook XML fragment with the given requirements.
     *
     * @param array<array{id: string, title: string, classification: string}> $requirements
     */
    private function buildMinimalXml(array $requirements): string
    {
        $sections = '';
        foreach ($requirements as $req) {
            // Extract baustein prefix (e.g. 'ISMS.1' from 'ISMS.1.A1')
            $reqId    = $req['id'];
            $title    = $req['title'];
            $classif  = $req['classification'];

            $sections .= sprintf(
                '<section xmlns="http://docbook.org/ns/docbook">
                    <title>%s (%s)</title>
                    <para>Test-Beschreibung für %s.</para>
                </section>',
                htmlspecialchars($reqId . ' ' . $title),
                $classif,
                htmlspecialchars($reqId)
            );
        }

        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>
<book xmlns="http://docbook.org/ns/docbook" version="5.0">
    <chapter>
        <title>ISMS Sicherheitsmanagement</title>
        <section xmlns="http://docbook.org/ns/docbook">
            <title>ISMS.1 Sicherheitsmanagement</title>
            %s
        </section>
        <section xmlns="http://docbook.org/ns/docbook">
            <title>SYS.1.1 Allgemeiner Server</title>
            %s
        </section>
    </chapter>
</book>',
            $sections,
            ''
        );
    }
}
