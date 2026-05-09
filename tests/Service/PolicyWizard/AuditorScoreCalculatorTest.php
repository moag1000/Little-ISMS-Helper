<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Entity\WizardRun;
use App\Service\PolicyWizard\AuditorScore;
use App\Service\PolicyWizard\AuditorScoreCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Junior-ISB Wish #5 — AuditorScoreCalculator readiness scoring tests.
 *
 * Verifies the green/yellow/red traffic-light tiers and the
 * human-readable reason list. Non-Wizard-generated documents must
 * return null (out of contract).
 */
final class AuditorScoreCalculatorTest extends TestCase
{
    private AuditorScoreCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new AuditorScoreCalculator();
    }

    private function makeTenant(int $id = 7): Tenant
    {
        $stub = $this->createStub(Tenant::class);
        $stub->method('getId')->willReturn($id);
        return $stub;
    }

    private function makeRun(Tenant $tenant): WizardRun
    {
        $run = new WizardRun();
        $run->setTenant($tenant);
        $run->setStandardsAdopted(['iso27001']);
        $reflection = new \ReflectionProperty(WizardRun::class, 'id');
        $reflection->setValue($run, 42);
        return $run;
    }

    private function makeTemplate(string $standard = 'iso27001', string $topic = 'access_control', bool $climate = false): PolicyTemplate
    {
        $template = new PolicyTemplate();
        $template->setKey($standard . '.' . $topic);
        $template->setStandard($standard);
        $template->setTopic($topic);
        $template->setDocumentType('policy');
        $template->setTitleTranslationKey('policy.' . $standard . '.' . $topic . '.v1.title');
        $template->setBodyTranslationKey('policy.' . $standard . '.' . $topic . '.v1.body');
        $template->setClimateChangeWording($climate);

        $reflection = new \ReflectionProperty(PolicyTemplate::class, 'id');
        $reflection->setValue($template, 1);
        return $template;
    }

    /**
     * Build a Document with the bare-minimum Wizard-provenance fields
     * filled. Tests can mutate after construction.
     */
    private function makeWizardDocument(
        PolicyTemplate $template,
        WizardRun $run,
        Tenant $tenant,
        string $description = 'A complete policy paragraph that easily exceeds the eighty-character minimum threshold for body content.',
        ?array $vars = null,
        string $status = 'approved',
    ): Document {
        $doc = new Document();
        $doc->setTenant($tenant);
        $doc->setFilename('policy-test.md');
        $doc->setOriginalFilename('policy-test.md');
        $doc->setMimeType('text/markdown');
        $doc->setFileSize(strlen($description));
        $doc->setFilePath('virtual:policy-wizard/test.md');
        $doc->setCategory('policy');
        $doc->setDescription($description);
        $doc->setStatus($status);
        $doc->setGeneratedFromTemplate($template);
        $doc->setGeneratedFromWizardRun($run);
        $doc->setSubstitutionVariables($vars ?? [
            'tenant.legal_name' => 'TestCo GmbH',
            '_hash' => 'abc',
            '_template_version' => 1,
        ]);

        $reflection = new \ReflectionProperty(Document::class, 'id');
        $reflection->setValue($doc, 100);
        return $doc;
    }

    #[Test]
    public function testGreenWhenAllFieldsFilled(): void
    {
        $tenant = $this->makeTenant();
        $run = $this->makeRun($tenant);
        $template = $this->makeTemplate();
        $document = $this->makeWizardDocument($template, $run, $tenant);

        $score = $this->calculator->calculateForDocument($document);

        self::assertNotNull($score);
        self::assertSame(AuditorScore::TIER_GREEN, $score->tier);
        self::assertGreaterThanOrEqual(80, $score->score);
        self::assertSame([], $score->reasons);
    }

    #[Test]
    public function testYellowWhenMinorIssues(): void
    {
        $tenant = $this->makeTenant();
        $run = $this->makeRun($tenant);
        $template = $this->makeTemplate('iso27001', 'top_level', climate: true);
        // Climate-wording missing (-10) AND short body (-15) AND draft (-5)
        // => 100 - 10 - 15 - 5 = 70 (yellow tier).
        $document = $this->makeWizardDocument(
            $template,
            $run,
            $tenant,
            description: 'Short stub body here.',
            status: 'draft',
        );

        $score = $this->calculator->calculateForDocument($document);

        self::assertNotNull($score);
        self::assertSame(AuditorScore::TIER_YELLOW, $score->tier);
        self::assertGreaterThanOrEqual(50, $score->score);
        self::assertLessThan(80, $score->score);
        self::assertContains('body_too_short', $score->reasons);
        self::assertContains('still_in_draft', $score->reasons);
        self::assertContains('climate_wording_missing', $score->reasons);
    }

    #[Test]
    public function testRedWhenMajorMissing(): void
    {
        $tenant = $this->makeTenant();
        $run = $this->makeRun($tenant);
        $template = $this->makeTemplate();
        // Missing required vars + leakage + body too short.
        $document = $this->makeWizardDocument(
            $template,
            $run,
            $tenant,
            description: 'Stub {{ tenant.legal_name }} body.',
            vars: [],
            status: 'draft',
        );

        $score = $this->calculator->calculateForDocument($document);

        self::assertNotNull($score);
        self::assertSame(AuditorScore::TIER_RED, $score->tier);
        self::assertLessThan(50, $score->score);
        self::assertContains('substitution_vars_missing', $score->reasons);
        self::assertContains('substitution_leakage', $score->reasons);
        self::assertContains('body_too_short', $score->reasons);
    }

    #[Test]
    public function testReasonsListHumanReadable(): void
    {
        $tenant = $this->makeTenant();
        $run = $this->makeRun($tenant);
        $template = $this->makeTemplate();
        // Empty value for required tenant.legal_name var.
        $document = $this->makeWizardDocument(
            $template,
            $run,
            $tenant,
            vars: ['tenant.legal_name' => '   '],
        );

        $score = $this->calculator->calculateForDocument($document);

        self::assertNotNull($score);
        self::assertContains('substitution_var_incomplete:tenant.legal_name', $score->reasons);
        // Reason codes must be machine-readable strings the Twig layer
        // can map to translation keys via the leading code segment.
        foreach ($score->reasons as $reason) {
            self::assertNotEmpty($reason);
            $code = explode(':', $reason, 2)[0];
            self::assertMatchesRegularExpression('/^[a-z_]+$/', $code, 'Reason code must be lowercase snake-case.');
        }
    }

    #[Test]
    public function testNonGeneratedDocReturnsNull(): void
    {
        $doc = new Document();
        $doc->setFilename('upload.pdf');
        $doc->setOriginalFilename('upload.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setFileSize(1024);
        $doc->setFilePath('uploads/upload.pdf');
        $doc->setCategory('manual');
        $doc->setStatus('approved');
        // No generatedFromTemplate set — uploaded document.

        $score = $this->calculator->calculateForDocument($doc);

        self::assertNull($score, 'Non-Wizard documents are out of contract and must return null.');
    }
}
