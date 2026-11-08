<?php

declare(strict_types=1);

namespace App\Tests\Service\Suggestion\Kind;

use App\Entity\Control;
use App\Entity\InternalAudit;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SuggestionOrigin;
use App\Repository\ControlRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\SuggestionRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Suggestion\Kind\AiAuditSampleKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AiAuditSampleKindTest extends TestCase
{
    #[Test]
    public function aPlannedOrRunningAuditWithoutSampleIsACandidate(): void
    {
        $tenant = new Tenant();
        $kind = $this->kind(new InternalAudit());

        self::assertTrue($kind->supportsRecord($this->audit($tenant, 'planned')));
        self::assertTrue($kind->supportsRecord($this->audit($tenant, 'in_progress')));
        self::assertFalse($kind->supportsRecord($this->audit($tenant, 'closed')));
        self::assertFalse(
            $kind->supportsRecord($this->audit($tenant, 'planned')->setObservations("suggestions.text.ai_sample.heading\n- A.5.1")),
            'a sample is already in the notes',
        );
    }

    #[Test]
    public function onlyRecordsFromThePoolSurviveTheAnswer(): void
    {
        $tenant = new Tenant();
        $audit = $this->audit($tenant, 'planned');

        $draft = $this->kind($audit)->draftFromAssistant($tenant, $audit, ['sample' => [
            ['ref' => 'A.5.1', 'why' => 'Freigabe der Richtlinie prüfen.'],
            ['ref' => 'A.9.9', 'why' => 'gibt es nicht'],
            ['ref' => 'A.5.1', 'why' => 'doppelt'],
        ]], 'MCP');

        self::assertNotNull($draft);
        self::assertSame(['A.5.1'], $draft->payload['refs']);
        self::assertStringContainsString('- A.5.1 Richtlinien: Freigabe der Richtlinie prüfen.', (string) $draft->editableText);
        self::assertNull($this->kind($audit)->draftFromAssistant($tenant, $audit, ['sample' => [['ref' => 'A.9.9']]], 'MCP'));
    }

    #[Test]
    public function acceptingAppendsToTheObservationsAndNeverTouchesTheStatus(): void
    {
        $tenant = new Tenant();
        $audit = $this->audit($tenant, 'planned')->setObservations('Vorhandene Notiz.');

        $this->kind($audit)->apply($this->suggestion($tenant), new User(), "Stichprobe:\n- A.5.1");

        self::assertSame("Vorhandene Notiz.\n\nStichprobe:\n- A.5.1", $audit->getObservations());
        self::assertSame('planned', $audit->getStatus());
    }

    #[Test]
    public function aForeignAuditOrAnExistingSampleIsStale(): void
    {
        $audit = $this->audit(new Tenant(), 'planned');
        try {
            $this->kind($audit)->apply($this->suggestion(new Tenant()), new User(), null);
            self::fail('tenant mismatch must be stale');
        } catch (StaleSuggestionException) {
            self::addToAssertionCount(1);
        }

        $tenant = new Tenant();
        $own = $this->audit($tenant, 'planned')->setObservations("Stichprobe:\n- A.5.1");
        $this->expectException(StaleSuggestionException::class);
        $this->kind($own)->apply($this->suggestion($tenant), new User(), null);
    }

    private function audit(Tenant $tenant, string $status): InternalAudit
    {
        return (new InternalAudit())->setTenant($tenant)->setAuditNumber('IA-1')->setTitle('ISMS-Audit')->setStatus($status);
    }

    private function suggestion(Tenant $tenant): Suggestion
    {
        return (new Suggestion($tenant, AiAuditSampleKind::KEY, SuggestionOrigin::Ai, 'T', 'R', 'k'))
            ->setSubject(InternalAudit::class, 12)
            ->setPayload(['heading' => 'Stichprobe:', 'refs' => ['A.5.1']])
            ->setEditableText("Stichprobe:\n- A.5.1");
    }

    private function kind(InternalAudit $found): AiAuditSampleKind
    {
        $control = (new Control())->setControlId('A.5.1')->setName('Richtlinien')->setImplementationStatus('implemented');
        $controls = $this->createStub(ControlRepository::class);
        $controls->method('findApplicableControls')->willReturn([$control]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($found);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new AiAuditSampleKind(
            $this->createStub(AiAssistant::class),
            $this->createStub(SuggestionRepository::class),
            $this->createStub(InternalAuditRepository::class),
            $controls,
            $em,
            new SuggestionText($translator),
        );
    }
}
