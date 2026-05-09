<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WizardRun;
use App\Repository\ControlRepository;
use App\Repository\DocumentControlLinkRepository;
use App\Repository\DocumentRepository;
use App\Repository\DocumentSectionRepository;
use App\Repository\PolicyTemplateRepository;
use App\Repository\TagRepository;
use App\Service\PolicyWizard\DocumentGenerator;
use App\Service\PolicyWizard\VariableCollector;
use App\Service\PolicyWizard\WizardStepKeys;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Policy-Wizard W3-I — climate-change-wording assertion tests.
 *
 * Verifies the §6 Step 2 hardcoded ON contract: every ISO 27001
 * top-level Information Security Policy emit MUST carry the climate-
 * change wording (Amd. 1:2024 in force since Feb 2024). Missing the
 * phrase aborts generation so the omission cannot silently ship.
 */
#[AllowMockObjectsWithoutExpectations]
final class DocumentGeneratorClimateAssertionTest extends TestCase
{
    private function makeTenant(): Tenant
    {
        $stub = $this->createStub(Tenant::class);
        $stub->method('getId')->willReturn(11);
        $stub->method('getLegalName')->willReturn('TestCo GmbH');
        $stub->method('getName')->willReturn('TestCo');
        return $stub;
    }

    private function makeUser(): User
    {
        $stub = $this->createStub(User::class);
        $stub->method('getId')->willReturn(9);
        return $stub;
    }

    private function makeTemplate(
        string $standard,
        string $topic,
        bool $climate = true,
    ): PolicyTemplate {
        $template = new PolicyTemplate();
        $template->setKey($standard . '.' . $topic);
        $template->setStandard($standard);
        $template->setTopic($topic);
        $template->setDocumentType('policy');
        $template->setTitleTranslationKey('policy.' . $standard . '.' . $topic . '.v1.title');
        $template->setBodyTranslationKey('policy.' . $standard . '.' . $topic . '.v1.body');
        $template->setClimateChangeWording($climate);
        $template->setVersion(1);

        $reflection = new \ReflectionProperty(PolicyTemplate::class, 'id');
        $reflection->setValue($template, 1);
        return $template;
    }

    private function makeRun(Tenant $tenant, array $standards = ['iso27001']): WizardRun
    {
        $run = new WizardRun();
        $run->setTenant($tenant);
        $run->setStandardsAdopted($standards);
        $run->setMode(WizardStepKeys::MODE_FULL);
        $run->setStartedByUser($this->makeUser());
        $reflection = new \ReflectionProperty(WizardRun::class, 'id');
        $reflection->setValue($run, 42);
        return $run;
    }

    private function makeGenerator(PolicyTemplate $template, string $body): DocumentGenerator
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist');
        $em->method('flush');
        $em->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $cb) => $cb(null),
        );

        $templateRepo = $this->createMock(PolicyTemplateRepository::class);
        $templateRepo->method('findActiveByStandard')->willReturn([$template]);

        $controlRepo = $this->createMock(ControlRepository::class);
        $controlRepo->method('findOneBy')->willReturn(null);

        $dclRepo = $this->createMock(DocumentControlLinkRepository::class);
        $dclRepo->method('findOneByDocumentAndControl')->willReturn(null);

        $documentRepo = $this->createMock(DocumentRepository::class);
        $documentRepo->method('findOneBy')->willReturn(null);

        $tagRepo = $this->createMock(TagRepository::class);
        $tagRepo->method('findOneByName')->willReturn(null);

        $variableCollector = $this->createMock(VariableCollector::class);
        $variableCollector->method('collectFor')->willReturn([
            'tenant.legal_name' => 'TestCo GmbH',
        ]);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $key): string => match (true) {
                str_ends_with($key, '.title') => 'Title for ' . $key,
                str_ends_with($key, '.body') => $body,
                default => $key,
            },
        );

        $sectionRepo = $this->createMock(DocumentSectionRepository::class);
        $sectionRepo->method('findOneByDocumentAndKey')->willReturn(null);

        return new DocumentGenerator(
            $em,
            $templateRepo,
            $controlRepo,
            $dclRepo,
            $documentRepo,
            $tagRepo,
            $variableCollector,
            $translator,
            $sectionRepo,
        );
    }

    #[Test]
    public function testIsoTopLevelBodyMustContainClimateWording(): void
    {
        // DE body — climate phrase present → no throw.
        $template = $this->makeTemplate('iso27001', 'top_level');
        $de = "Informationssicherheits-Leitlinie\n\nDer Klimawandel ist ein externer Faktor.";
        $generator = $this->makeGenerator($template, $de);
        $result = $generator->generate($this->makeRun($this->makeTenant()));
        self::assertCount(1, $result['document_ids']);

        // EN body (case-insensitive) — phrase present → no throw.
        $en = "Information Security Policy\n\nClimate Change is treated as an external issue.";
        $generator = $this->makeGenerator($template, $en);
        $result = $generator->generate($this->makeRun($this->makeTenant()));
        self::assertCount(1, $result['document_ids']);

        // Body missing the phrase → RuntimeException.
        $bad = "Information Security Policy\n\nNo magic phrase here.";
        $generator = $this->makeGenerator($template, $bad);
        try {
            $generator->generate($this->makeRun($this->makeTenant()));
            self::fail('expected RuntimeException for missing climate-change wording');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('climate-change wording', $e->getMessage());
            self::assertStringContainsString('Amd. 1:2024', $e->getMessage());
        }
    }

    #[Test]
    public function testNonTopLevelTopicSkipsAssertion(): void
    {
        // Non-top-level ISO topic + climateChangeWording=true + missing
        // phrase → assertion does NOT trip (climate is a Cl. 5.2 top-
        // level concern only).
        $template = $this->makeTemplate('iso27001', 'access_control');
        $body = "Access Control Policy\n\nNo climate phrase, but topic is access_control.";
        $generator = $this->makeGenerator($template, $body);
        $result = $generator->generate($this->makeRun($this->makeTenant()));
        self::assertCount(1, $result['document_ids']);

        // Different non-iso27001 standard at top_level topic also skips
        // (DORA / BSI / BCM may not include the wording).
        $bsi = $this->makeTemplate('bsi', 'top_level');
        $generator = $this->makeGenerator($bsi, "Top-level BSI policy without climate.");
        $result = $generator->generate($this->makeRun($this->makeTenant(), ['bsi']));
        self::assertCount(1, $result['document_ids']);
    }

    #[Test]
    public function testEnvOverrideDisablesAssertion(): void
    {
        $template = $this->makeTemplate('iso27001', 'top_level');
        $bad = "ISMS Top Level — phrase intentionally omitted (override scenario).";

        $previous = $_ENV['ISO_27001_CLIMATE_ASSERT_DISABLED'] ?? null;
        $_ENV['ISO_27001_CLIMATE_ASSERT_DISABLED'] = '1';
        try {
            $generator = $this->makeGenerator($template, $bad);
            $result = $generator->generate($this->makeRun($this->makeTenant()));
            self::assertCount(1, $result['document_ids'], 'ENV override must bypass the assertion');
        } finally {
            if ($previous === null) {
                unset($_ENV['ISO_27001_CLIMATE_ASSERT_DISABLED']);
            } else {
                $_ENV['ISO_27001_CLIMATE_ASSERT_DISABLED'] = $previous;
            }
        }
    }
}
