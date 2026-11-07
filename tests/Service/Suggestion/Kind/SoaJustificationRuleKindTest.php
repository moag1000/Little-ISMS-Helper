<?php

declare(strict_types=1);

namespace App\Tests\Service\Suggestion\Kind;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Control;
use App\Entity\Risk;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SuggestionOrigin;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ControlRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Suggestion\Kind\SoaJustificationRuleKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SoaJustificationRuleKindTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
    }

    #[Test]
    public function applicableControlGetsAJustificationFromItsRisksAndRequirements(): void
    {
        $control = $this->control(true);
        $control->addRisk((new Risk())->setTitle('Datenverlust'));
        $nis2 = $this->createStub(ComplianceFramework::class);
        $nis2->method('getCode')->willReturn('NIS2');
        $nis2->method('isActive')->willReturn(true);
        $requirement = $this->createStub(ComplianceRequirement::class);
        $requirement->method('getFramework')->willReturn($nis2);
        $requirement->method('getRequirementId')->willReturn('21.2.c');
        $own = $this->createStub(ComplianceRequirement::class);
        $own->method('getFramework')->willReturn($nis2);
        $own->method('getRequirementId')->willReturn('A.8.13');

        $draft = $this->kind([$requirement, $own])->draftFor($this->tenant, $control);

        self::assertNotNull($draft);
        self::assertStringContainsString('suggestions.text.soa_rule.risks „Datenverlust“', (string) $draft->editableText);
        self::assertStringContainsString('suggestions.text.soa_rule.requirements NIS2 21.2.c', (string) $draft->editableText);
        self::assertStringNotContainsString('A.8.13', (string) $draft->editableText, 'the control\'s own Annex A entry is no argument');
        self::assertStringContainsString('suggestions.text.soa_rule.status.implemented', (string) $draft->editableText);
    }

    #[Test]
    public function noFactsNoDraft(): void
    {
        self::assertNull($this->kind([])->draftFor($this->tenant, $this->control(true)));
    }

    #[Test]
    public function excludedControlWithARiskIsAContradictionNotAText(): void
    {
        $control = $this->control(false);
        $control->addRisk((new Risk())->setTitle('Datenverlust'));

        self::assertNull($this->kind([])->draftFor($this->tenant, $control));
    }

    #[Test]
    public function excludedControlWithoutLinksGetsTheExclusionText(): void
    {
        $draft = $this->kind([])->draftFor($this->tenant, $this->control(false));

        self::assertSame('suggestions.text.soa_rule.excluded', $draft?->editableText);
        self::assertSame(SuggestionOrigin::Rule, $this->kind([])->origin());
    }

    #[Test]
    public function controlWithAJustificationIsNotDraftedAndApplyRefusesToOverwrite(): void
    {
        $control = $this->control(true)->setJustification('Schon begründet.');
        self::assertFalse($this->kind([])->supportsRecord($control));

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($control);
        $suggestion = (new Suggestion($this->tenant, SoaJustificationRuleKind::KEY, SuggestionOrigin::Rule, 'T', 'R', 'k'))
            ->setSubject(Control::class, 7);

        $this->expectException(StaleSuggestionException::class);
        $this->kind([], $em)->apply($suggestion, new User(), 'Neu');
    }

    private function control(bool $applicable): Control
    {
        $control = (new Control())->setControlId('A.8.13')->setName('Backup')->setApplicable($applicable)->setTenant($this->tenant);
        $control->setImplementationStatus('implemented');
        (new \ReflectionProperty(Control::class, 'id'))->setValue($control, 7);

        return $control;
    }

    /** @param list<ComplianceRequirement> $requirements */
    private function kind(array $requirements, ?EntityManagerInterface $em = null): SoaJustificationRuleKind
    {
        $repo = $this->createStub(ComplianceRequirementRepository::class);
        $repo->method('findByControl')->willReturn($requirements);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $p = []): string => trim($id . ' ' . implode(' ', array_map('strval', $p))),
        );

        return new SoaJustificationRuleKind(
            $this->createStub(ControlRepository::class),
            $repo,
            $this->createStub(AiAssistant::class),
            $em ?? $this->createStub(EntityManagerInterface::class),
            new SuggestionText($translator),
        );
    }
}
