<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard\Diff;

use App\Entity\Document;
use App\Entity\DocumentSection;
use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Repository\DocumentSectionRepository;
use App\Service\PolicyWizard\Diff\PolicyDiff;
use App\Service\PolicyWizard\Diff\PolicyDiffService;
use App\Service\PolicyWizard\Diff\SubstitutionVariableDiff;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Policy-Wizard W7-C — PolicyDiffService unit tests.
 *
 * Verifies the doc-level + variable-level diff contract — strictly NO
 * character-level diff. Severity heuristic boundaries are pinned so the
 * §9.4 Review-without-changes Fast-Path keeps working.
 */
#[AllowMockObjectsWithoutExpectations]
final class PolicyDiffServiceTest extends TestCase
{
    private function makeTenant(int $id = 7): Tenant
    {
        $t = new Tenant();
        $t->setName('TestCo');
        $t->setCode('test_' . $id);
        $reflection = new ReflectionProperty(Tenant::class, 'id');
        $reflection->setValue($t, $id);
        return $t;
    }

    /**
     * @param array<string, mixed>|null $variables
     */
    private function makeDocument(
        int $id,
        Tenant $tenant,
        ?string $title = 'policy.md',
        ?string $category = 'policy',
        ?array $variables = null,
        ?string $sha256 = null,
    ): Document {
        $doc = new Document();
        $doc->setTenant($tenant);
        $doc->setFilename($title ?? 'doc.md');
        $doc->setOriginalFilename($title ?? 'doc.md');
        $doc->setMimeType('text/markdown');
        $doc->setFileSize(123);
        $doc->setFilePath('virtual:test');
        $doc->setCategory($category ?? 'policy');
        $doc->setStatus('draft');
        if ($variables !== null) {
            $doc->setSubstitutionVariables($variables);
        }
        if ($sha256 !== null) {
            $doc->setSha256Hash($sha256);
        }

        $reflection = new ReflectionProperty(Document::class, 'id');
        $reflection->setValue($doc, $id);
        return $doc;
    }

    private function makeSection(string $key, string $content): DocumentSection
    {
        $section = new DocumentSection();
        $section->setSectionKey($key);
        $section->setContentSnapshot($content);
        $section->setStatus(DocumentSection::STATUS_DRAFT);
        return $section;
    }

    /**
     * @param array<int, array<DocumentSection>> $byDocumentId
     */
    private function makeSectionRepo(array $byDocumentId): DocumentSectionRepository
    {
        $repo = $this->createMock(DocumentSectionRepository::class);
        $repo->method('findByDocument')->willReturnCallback(
            static fn (Document $doc) => $byDocumentId[$doc->getId() ?? -1] ?? [],
        );
        return $repo;
    }

    #[Test]
    public function testNoChangesDetected(): void
    {
        $tenant = $this->makeTenant();
        $vars = ['tenant.legal_name' => 'TestCo GmbH', 'roles.ciso.fullName' => 'Anna B.'];

        $previous = $this->makeDocument(1, $tenant, variables: $vars, sha256: 'abc');
        $current = $this->makeDocument(2, $tenant, variables: $vars, sha256: 'abc');

        $service = new PolicyDiffService($this->makeSectionRepo([1 => [], 2 => []]));
        $diff = $service->diffDocuments($previous, $current);

        self::assertFalse($diff->hasChanges(), 'identical snapshots must not register as changed');
        self::assertSame(PolicyDiff::SEVERITY_MINOR, $diff->severity());
        self::assertSame(0, $diff->totalChanges());
        self::assertFalse($diff->bodyHashChanged);
    }

    #[Test]
    public function testMetadataChangesIdentified(): void
    {
        $tenant = $this->makeTenant();
        $previous = $this->makeDocument(10, $tenant, title: 'old.md', category: 'policy');
        $current = $this->makeDocument(11, $tenant, title: 'new.md', category: 'procedure');

        $service = new PolicyDiffService($this->makeSectionRepo([]));
        $diff = $service->diffDocuments($previous, $current);

        self::assertTrue($diff->metadataChanged);
        $fields = array_column($diff->metadataDelta, 'field');
        self::assertContains('title', $fields, 'title (originalFilename) change must surface');
        self::assertContains('category', $fields, 'category change must surface');
    }

    #[Test]
    public function testVariableChangesFlattened(): void
    {
        $tenant = $this->makeTenant();
        $previous = $this->makeDocument(20, $tenant, variables: [
            'tenant' => ['legal_name' => 'Old Co GmbH'],
            'roles' => ['ciso' => ['fullName' => 'Anna']],
            '_hash' => 'OLDHASH',
        ]);
        $current = $this->makeDocument(21, $tenant, variables: [
            'tenant' => ['legal_name' => 'New Co GmbH'],
            'roles' => ['ciso' => ['fullName' => 'Bob'], 'dpo' => ['fullName' => 'Cara']],
            '_hash' => 'NEWHASH',
        ]);

        $service = new PolicyDiffService($this->makeSectionRepo([]));
        $diff = $service->diffDocuments($previous, $current);

        $byKey = [];
        foreach ($diff->variableChanges as $row) {
            $byKey[$row['key']] = $row;
        }
        self::assertArrayHasKey('tenant.legal_name', $byKey, 'flattened tenant key must appear');
        self::assertSame(SubstitutionVariableDiff::CHANGE_MODIFIED, $byKey['tenant.legal_name']['change_type']);

        self::assertArrayHasKey('roles.ciso.fullName', $byKey);
        self::assertSame('Anna', $byKey['roles.ciso.fullName']['oldValue']);
        self::assertSame('Bob', $byKey['roles.ciso.fullName']['newValue']);

        self::assertArrayHasKey('roles.dpo.fullName', $byKey);
        self::assertSame(SubstitutionVariableDiff::CHANGE_ADDED, $byKey['roles.dpo.fullName']['change_type']);

        // System internal `_hash` must NOT leak into the variable surface.
        self::assertArrayNotHasKey('_hash', $byKey, 'system-internal _* keys must be filtered');
    }

    #[Test]
    public function testSectionAddedRemoved(): void
    {
        $tenant = $this->makeTenant();
        $previous = $this->makeDocument(30, $tenant);
        $current = $this->makeDocument(31, $tenant);

        $repo = $this->makeSectionRepo([
            30 => [
                $this->makeSection('intro', 'A'),
                $this->makeSection('to_remove', 'B'),
            ],
            31 => [
                $this->makeSection('intro', 'A'),         // unchanged
                $this->makeSection('newly_added', 'C'),   // added
            ],
        ]);
        $service = new PolicyDiffService($repo);
        $diff = $service->diffDocuments($previous, $current);

        $addedKeys = array_map(static fn (DocumentSection $s) => $s->getSectionKey(), $diff->sectionsAdded);
        $removedKeys = array_map(static fn (DocumentSection $s) => $s->getSectionKey(), $diff->sectionsRemoved);

        self::assertContains('newly_added', $addedKeys);
        self::assertContains('to_remove', $removedKeys);
        self::assertCount(0, $diff->sectionsModified, 'unchanged sections must not show as modified');
    }

    #[Test]
    public function testSeverityMinor(): void
    {
        $tenant = $this->makeTenant();
        $previous = $this->makeDocument(40, $tenant, variables: [
            'tenant.legal_name' => 'Co A',
        ]);
        $current = $this->makeDocument(41, $tenant, variables: [
            'tenant.legal_name' => 'Co B', // exactly 1 variable change
        ]);

        $service = new PolicyDiffService($this->makeSectionRepo([]));
        $diff = $service->diffDocuments($previous, $current);

        self::assertSame(PolicyDiff::SEVERITY_MINOR, $diff->severity(), '1 variable change ⇒ minor');
    }

    #[Test]
    public function testSeverityMajor(): void
    {
        $tenant = $this->makeTenant();
        // Same templateless documents but different metadata to force
        // standard/topic-equivalent change. Without templates, "topic"
        // is null on both — so we add 3 section adds/removes to trigger
        // the "≥3 section adds/removes" rule.
        $previous = $this->makeDocument(50, $tenant);
        $current = $this->makeDocument(51, $tenant);

        $repo = $this->makeSectionRepo([
            50 => [
                $this->makeSection('a', 'X'),
                $this->makeSection('b', 'Y'),
                $this->makeSection('c', 'Z'),
            ],
            51 => [
                $this->makeSection('d', 'X'),
            ],
        ]);
        $service = new PolicyDiffService($repo);
        $diff = $service->diffDocuments($previous, $current);

        // 3 removed (a, b, c) + 1 added (d) = 4 section adds/removes ⇒ major.
        self::assertSame(PolicyDiff::SEVERITY_MAJOR, $diff->severity());
        self::assertGreaterThanOrEqual(4, $diff->totalChanges());
    }

    #[Test]
    public function testStandardChangeForcesMajorSeverity(): void
    {
        // Template-bound documents: standard/topic flip ⇒ major regardless
        // of section count. Mirrors the heuristic guard for "different
        // framework now".
        $tenant = $this->makeTenant();
        $tplOld = new PolicyTemplate();
        $tplOld->setKey('iso27001.access_control');
        $tplOld->setStandard('iso27001');
        $tplOld->setTopic('access_control');

        $tplNew = new PolicyTemplate();
        $tplNew->setKey('bsi.access_control');
        $tplNew->setStandard('bsi');
        $tplNew->setTopic('access_control');

        $previous = $this->makeDocument(60, $tenant);
        $previous->setGeneratedFromTemplate($tplOld);
        $current = $this->makeDocument(61, $tenant);
        $current->setGeneratedFromTemplate($tplNew);

        $service = new PolicyDiffService($this->makeSectionRepo([]));
        $diff = $service->diffDocuments($previous, $current);

        self::assertSame(PolicyDiff::SEVERITY_MAJOR, $diff->severity(), 'standard flip ⇒ major');
    }
}
