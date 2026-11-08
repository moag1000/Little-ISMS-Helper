<?php

declare(strict_types=1);

namespace App\Tests\Service\Suggestion\Kind;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Control;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SuggestionOrigin;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ControlRepository;
use App\Repository\SuggestionRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Compliance\Fulfillment\FulfillmentRuleEngine;
use App\Service\Suggestion\Kind\AiRequirementMappingKind;
use App\Service\Suggestion\Kind\ControlRequirementLinkKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionText;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AiRequirementMappingKindTest extends TestCase
{
    #[Test]
    public function aRequirementOfAnActiveFrameworkWithoutOwnControlsIsACandidate(): void
    {
        $tenant = new Tenant();
        $kind = $this->kind($tenant);

        self::assertTrue($kind->supportsRecordForTest($this->requirement(true)));
        self::assertFalse($kind->supportsRecordForTest($this->requirement(false)), 'framework inactive');

        $covered = $this->requirement(true);
        $covered->addMappedControl($this->control($tenant, 'A.5.1', 1));
        self::assertFalse($kind->supportsRecordForTest($covered));

        $foreignOnly = $this->requirement(true);
        $foreignOnly->addMappedControl($this->control(new Tenant(), 'A.5.1', 2));
        self::assertTrue($kind->supportsRecordForTest($foreignOnly), 'a control of another organisation does not count');
    }

    #[Test]
    public function onlyControlsOfTheOrganisationSurviveTheModelsAnswer(): void
    {
        $tenant = new Tenant();
        $known = $this->control($tenant, 'A.5.1', 11);
        $kind = $this->kind($tenant, byControlId: ['A.5.1' => $known]);

        $draft = $kind->draftFromAssistantForTest($tenant, $this->requirement(true), ['controls' => ['A.5.1', 'X.9.9', 'A.5.1'], 'reasoning' => 'Passt.']);

        self::assertNotNull($draft);
        self::assertSame([11], $draft->payload['controls']);
        self::assertNull($kind->draftFromAssistantForTest($tenant, $this->requirement(true), ['controls' => ['X.9.9']]), 'no valid control, no draft');
    }

    #[Test]
    public function acceptingLinksTheControlsToTheRequirementOnly(): void
    {
        $tenant = new Tenant();
        $requirement = $this->requirement(true);
        $control = $this->control($tenant, 'A.5.1', 11);
        $foreign = $this->control(new Tenant(), 'A.5.2', 12);

        $result = $this->kind($tenant, found: [ComplianceRequirement::class => $requirement, Control::class => [11 => $control, 12 => $foreign]])
            ->apply($this->suggestion($tenant, [11, 12]), new User(), null);

        self::assertSame($requirement, $result);
        self::assertTrue($requirement->getMappedControls()->contains($control));
        self::assertFalse($requirement->getMappedControls()->contains($foreign), 'tenant mismatch is skipped');
    }

    #[Test]
    public function nothingLeftToLinkIsStale(): void
    {
        $tenant = new Tenant();
        $requirement = $this->requirement(true);
        $control = $this->control($tenant, 'A.5.1', 11);
        $requirement->addMappedControl($control);

        $this->expectException(StaleSuggestionException::class);
        $this->kind($tenant, found: [ComplianceRequirement::class => $requirement, Control::class => [11 => $control]])
            ->apply($this->suggestion($tenant, [11]), new User(), null);
    }

    private function requirement(bool $active): ComplianceRequirement
    {
        $framework = (new ComplianceFramework())->setCode('NIS2')->setName('NIS-2')->setActive($active);

        return (new ComplianceRequirement())
            ->setFramework($framework)
            ->setRequirementId('Art. 21')
            ->setTitle('Zugriffskontrolle und Authentifizierung');
    }

    private function control(Tenant $tenant, string $controlId, int $id): Control
    {
        $control = (new Control())->setTenant($tenant)->setControlId($controlId)->setName('Zugangssteuerung');
        (new \ReflectionProperty(Control::class, 'id'))->setValue($control, $id);

        return $control;
    }

    /** @param list<int> $controls */
    private function suggestion(Tenant $tenant, array $controls): Suggestion
    {
        return (new Suggestion($tenant, AiRequirementMappingKind::KEY, SuggestionOrigin::Ai, 'T', 'R', 'k'))
            ->setSubject(ComplianceRequirement::class, 3)
            ->setPayload(['controls' => $controls]);
    }

    /**
     * @param array<string, Control> $byControlId
     * @param array<class-string, mixed> $found
     */
    private function kind(Tenant $tenant, array $byControlId = [], array $found = []): AiRequirementMappingKindUnderTest
    {
        $controls = $this->createStub(ControlRepository::class);
        $controls->method('findByControlIdAndTenant')->willReturnCallback(static fn (string $id): ?Control => $byControlId[$id] ?? null);
        $controls->method('findApplicableControls')->willReturn([]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(static function (string $class, int $id) use ($found): ?object {
            $hit = $found[$class] ?? null;

            return is_array($hit) ? ($hit[$id] ?? null) : $hit;
        });
        $engine = $this->createStub(FulfillmentRuleEngine::class);
        $engine->method('controlsFor')->willReturnCallback(static fn (ComplianceRequirement $r, Tenant $t): array => array_values(
            array_filter($r->getMappedControls()->toArray(), static fn (Control $c): bool => $c->getTenant() === $t),
        ));
        $context = $this->createStub(TenantContext::class);
        $context->method('getCurrentTenant')->willReturn($tenant);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $text = new SuggestionText($translator);

        return new AiRequirementMappingKindUnderTest(
            $this->createStub(AiAssistant::class),
            $this->createStub(SuggestionRepository::class),
            $this->createStub(ComplianceFrameworkRepository::class),
            $this->createStub(ComplianceRequirementRepository::class),
            $controls,
            $engine,
            new ControlRequirementLinkKind($em, $text),
            $context,
            $em,
            $text,
        );
    }
}

/** The kind has no subject classes (catalogue rows have no organisation), so the protected hooks are reached here. */
final class AiRequirementMappingKindUnderTest
{
    private readonly \ReflectionObject $reflection;
    private readonly AiRequirementMappingKind $kind;

    public function __construct(mixed ...$arguments)
    {
        $this->kind = new AiRequirementMappingKind(...$arguments);
        $this->reflection = new \ReflectionObject($this->kind);
    }

    public function supportsRecordForTest(object $record): bool
    {
        return (bool) $this->reflection->getMethod('isCandidate')->invoke($this->kind, $record);
    }

    /** @param array<string, mixed> $data */
    public function draftFromAssistantForTest(Tenant $tenant, object $record, array $data): ?\App\Service\Suggestion\SuggestionDraft
    {
        return $this->reflection->getMethod('toDraft')->invoke($this->kind, $record, $data, 'MCP', $tenant);
    }

    public function apply(Suggestion $suggestion, User $by, ?string $text): object
    {
        return $this->kind->apply($suggestion, $by, $text);
    }
}
