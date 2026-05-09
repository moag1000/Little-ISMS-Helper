<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WizardRun;
use App\Repository\ControlRepository;
use App\Repository\DocumentControlLinkRepository;
use App\Repository\DocumentRepository;
use App\Repository\PolicyTemplateRepository;
use App\Repository\TagRepository;
use App\Service\PolicyWizard\DocumentGenerator;
use App\Service\PolicyWizard\DoraExtensionCatalogue;
use App\Service\PolicyWizard\VariableCollector;
use App\Service\PolicyWizard\WizardStepKeys;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Junior-ISB Wish #2 — verify the NIS-2 / DORA lex specialis footer
 * append behaviour on standalone DORA documents.
 *
 * Footer must be appended to every DORA-standard body when DORA is
 * in scope (lex specialis evidence), and skipped for non-DORA bodies
 * (ISO/BSI/etc. — those carry the DORA section via
 * {@see DocumentGenerator::appendDoraExtensionIfApplicable}).
 */
#[AllowMockObjectsWithoutExpectations]
final class DocumentGeneratorNis2LexSpecialisFooterTest extends TestCase
{
    private function makeTenant(int $id = 33): Tenant
    {
        $stub = $this->createStub(Tenant::class);
        $stub->method('getId')->willReturn($id);
        $stub->method('getLegalName')->willReturn('TestCo GmbH');
        $stub->method('getName')->willReturn('TestCo');
        return $stub;
    }

    private function makeUser(int $id = 9): User
    {
        $stub = $this->createStub(User::class);
        $stub->method('getId')->willReturn($id);
        return $stub;
    }

    private function makeTemplate(string $standard, string $topic, int $id = 1): PolicyTemplate
    {
        $template = new PolicyTemplate();
        $template->setKey($standard . '.' . $topic);
        $template->setStandard($standard);
        $template->setTopic($topic);
        $template->setDocumentType('policy');
        $template->setTitleTranslationKey('policy.' . $standard . '.' . $topic . '.v1.title');
        $template->setBodyTranslationKey('policy.' . $standard . '.' . $topic . '.v1.body');
        $template->setVersion(1);

        $reflection = new \ReflectionProperty(PolicyTemplate::class, 'id');
        $reflection->setValue($template, $id);
        return $template;
    }

    private function makeRun(Tenant $tenant, array $standards): WizardRun
    {
        $run = new WizardRun();
        $run->setTenant($tenant);
        $run->setStandardsAdopted($standards);
        $run->setMode(WizardStepKeys::MODE_FULL);
        $run->setStartedByUser($this->makeUser());
        $run->setInputs([]);

        $reflection = new \ReflectionProperty(WizardRun::class, 'id');
        $reflection->setValue($run, 99);
        return $run;
    }

    private function makeGenerator(): DocumentGenerator
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $templateRepo = $this->createMock(PolicyTemplateRepository::class);
        $controlRepo = $this->createMock(ControlRepository::class);
        $dclRepo = $this->createMock(DocumentControlLinkRepository::class);
        $documentRepo = $this->createMock(DocumentRepository::class);
        $tagRepo = $this->createMock(TagRepository::class);
        $variableCollector = $this->createMock(VariableCollector::class);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $key, array $params = [], ?string $domain = null): string => match (true) {
                $key === 'policy_wizard.step.welcome.dora_nis2_lex_specialis.footer_in_doc'
                    => 'This DORA policy satisfies NIS-2 Art. 21 + Art. 23 via DORA Art. 6 + Art. 17-23 (lex specialis per DORA Art. 1(2) and NIS-2 recital 16).',
                default => $key,
            },
        );

        return new DocumentGenerator(
            $em,
            $templateRepo,
            $controlRepo,
            $dclRepo,
            $documentRepo,
            $tagRepo,
            $variableCollector,
            $translator,
            null,
            new \Psr\Log\NullLogger(),
            new DoraExtensionCatalogue(),
        );
    }

    #[Test]
    public function testFooterAppendedToDoraDocs(): void
    {
        $tenant = $this->makeTenant();
        $generator = $this->makeGenerator();
        $template = $this->makeTemplate('dora', 'ict_risk_management');
        $run = $this->makeRun($tenant, ['iso27001', 'dora']);

        $body = 'Body of the DORA ICT risk policy.';
        $appended = $generator->appendNis2LexSpecialisFooterIfApplicable($template, $body, $run);

        self::assertNotSame($body, $appended, 'DORA bodies must grow a NIS-2 lex specialis footer.');
        self::assertStringContainsString('NIS-2 Art. 21', $appended);
        self::assertStringContainsString('lex specialis', $appended);
        // Original body must remain at the top.
        self::assertStringStartsWith($body, $appended);
    }

    #[Test]
    public function testNoFooterForNonDoraDocs(): void
    {
        $tenant = $this->makeTenant();
        $generator = $this->makeGenerator();

        // ISO 27001 template — must NOT receive the lex specialis
        // footer (those carry the DORA SECTION via the extension hook,
        // not the standalone NIS-2 footer).
        $isoTemplate = $this->makeTemplate('iso27001', 'access_control');
        $run = $this->makeRun($tenant, ['iso27001', 'dora']);

        $body = 'Body of the ISO Access-Control policy.';
        $appended = $generator->appendNis2LexSpecialisFooterIfApplicable($isoTemplate, $body, $run);

        self::assertSame($body, $appended, 'Non-DORA standards must NOT receive the lex specialis footer.');

        // DORA template but DORA NOT in scope — also no footer.
        $doraTemplate = $this->makeTemplate('dora', 'ict_risk_management', 2);
        $isoOnlyRun = $this->makeRun($tenant, ['iso27001']);
        $appendedNoScope = $generator->appendNis2LexSpecialisFooterIfApplicable($doraTemplate, $body, $isoOnlyRun);
        self::assertSame($body, $appendedNoScope, 'DORA template without DORA scope must NOT receive the footer.');
    }
}
