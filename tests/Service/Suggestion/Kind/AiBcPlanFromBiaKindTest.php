<?php

declare(strict_types=1);

namespace App\Tests\Service\Suggestion\Kind;

use App\Entity\BusinessContinuityPlan;
use App\Entity\BusinessProcess;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SuggestionOrigin;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\BusinessProcessRepository;
use App\Repository\SuggestionRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Suggestion\Kind\AiBcPlanFromBiaKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AiBcPlanFromBiaKindTest extends TestCase
{
    #[Test]
    public function aProcessWithBiaAndWithoutPlanIsACandidate(): void
    {
        $process = (new BusinessProcess())->setName('Lohnabrechnung')->setRto(8);

        self::assertTrue($this->kind(planCount: 0)->supportsRecord($process));
        self::assertFalse($this->kind(planCount: 1)->supportsRecord($process), 'a plan exists');
        self::assertFalse($this->kind(planCount: 0)->supportsRecord((new BusinessProcess())->setName('Ohne BIA')), 'no BIA data');
    }

    #[Test]
    public function theAnswerBecomesADraftWithThreeBlocksAndNeedsActivationAndRecovery(): void
    {
        $process = (new BusinessProcess())->setName('Lohnabrechnung')->setRto(8);
        $kind = $this->kind();

        $draft = $kind->draftFromAssistant(new Tenant(), $process, [
            'activation' => 'Ausfall länger als 4 Stunden.',
            'recovery' => '1. Backup einspielen.',
            'communication' => 'Mitarbeitende per Mail.',
        ], 'MCP');

        self::assertNotNull($draft);
        self::assertSame('Ausfall länger als 4 Stunden.', $draft->payload['fields']['activationCriteria']);
        self::assertStringContainsString("1. Backup einspielen.", (string) $draft->editableText);
        self::assertNull($kind->draftFromAssistant(new Tenant(), $process, ['activation' => 'nur das'], 'MCP'));
    }

    #[Test]
    public function acceptingCreatesADraftPlanWithTheTargetsOfTheBia(): void
    {
        $tenant = new Tenant();
        $process = (new BusinessProcess())->setTenant($tenant)->setName('Lohnabrechnung')->setRto(8)->setRpo(2);
        $created = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($process);
        $em->expects(self::once())->method('persist')->with(self::callback(static function (object $o) use (&$created): bool {
            $created = $o;

            return true;
        }));

        $result = $this->kind(em: $em)->apply($this->suggestion($tenant), new User(), "suggestions.text.ai_bc_plan.recovery:\nAnders formuliert.");

        self::assertInstanceOf(BusinessContinuityPlan::class, $result);
        self::assertSame($created, $result);
        self::assertSame($process, $result->getBusinessProcess());
        self::assertSame('draft', $result->getStatus());
        self::assertSame(8, $result->getRto());
        self::assertSame(2, $result->getRpo());
        self::assertSame('Ausfall länger als 4 Stunden.', $result->getActivationCriteria());
        self::assertSame('Anders formuliert.', $result->getRecoveryProcedures());
    }

    #[Test]
    public function aForeignProcessOrAnExistingPlanIsStale(): void
    {
        $process = (new BusinessProcess())->setTenant(new Tenant())->setName('Fremd');
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($process);

        try {
            $this->kind(em: $em)->apply($this->suggestion(new Tenant()), new User(), null);
            self::fail('tenant mismatch must be stale');
        } catch (StaleSuggestionException) {
            self::addToAssertionCount(1);
        }

        $tenant = new Tenant();
        $own = (new BusinessProcess())->setTenant($tenant)->setName('Eigen');
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($own);
        $this->expectException(StaleSuggestionException::class);
        $this->kind(em: $em, planCount: 1)->apply($this->suggestion($tenant), new User(), null);
    }

    private function suggestion(Tenant $tenant): Suggestion
    {
        return (new Suggestion($tenant, AiBcPlanFromBiaKind::KEY, SuggestionOrigin::Ai, 'T', 'R', 'k'))
            ->setSubject(BusinessProcess::class, 4)
            ->setPayload([
                'fields' => ['activationCriteria' => 'Ausfall länger als 4 Stunden.', 'recoveryProcedures' => '1. Backup einspielen.'],
                'labels' => [
                    'activationCriteria' => 'suggestions.text.ai_bc_plan.activation',
                    'recoveryProcedures' => 'suggestions.text.ai_bc_plan.recovery',
                    'communicationPlan' => 'suggestions.text.ai_bc_plan.communication',
                ],
            ]);
    }

    private function kind(?EntityManagerInterface $em = null, int $planCount = 0): AiBcPlanFromBiaKind
    {
        $plans = $this->createStub(BusinessContinuityPlanRepository::class);
        $plans->method('count')->willReturn($planCount);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new AiBcPlanFromBiaKind(
            $this->createStub(AiAssistant::class),
            $this->createStub(SuggestionRepository::class),
            $this->createStub(BusinessProcessRepository::class),
            $plans,
            $em ?? $this->createStub(EntityManagerInterface::class),
            new SuggestionText($translator),
        );
    }
}
