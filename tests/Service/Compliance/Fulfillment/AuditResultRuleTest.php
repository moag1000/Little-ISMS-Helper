<?php

declare(strict_types=1);

namespace App\Tests\Service\Compliance\Fulfillment;

use App\Entity\AuditChecklist;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\InternalAudit;
use App\Entity\Tenant;
use App\Repository\AuditChecklistRepository;
use App\Service\Compliance\Fulfillment\AuditResultRule;
use App\Service\Compliance\Fulfillment\FulfillmentDerivation;
use App\Service\Compliance\Fulfillment\FulfillmentRuleEngine;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class AuditResultRuleTest extends TestCase
{
    private Tenant $tenant;
    private ComplianceRequirement $requirement;
    /** @var list<AuditChecklist> newest first */
    private array $verdicts = [];
    private int $queries = 0;

    protected function setUp(): void
    {
        $this->tenant = self::withId(new Tenant(), 1);
        $this->requirement = self::withId((new ComplianceRequirement())
            ->setFramework((new ComplianceFramework())->setCode('ISO27001')->setName('ISO 27001'))
            ->setRequirementId('ISO27001-9.2'), 10);
    }

    #[Test]
    public function aNonconformityCapsWhatTheDataSay(): void
    {
        $this->verdict('non_compliant');
        $derivation = $this->rule()->apply($this->controls(100), $this->requirement, $this->tenant);

        self::assertSame(0, $derivation?->percentage);
        self::assertSame(FulfillmentRuleEngine::KIND_CONTROLS, $derivation->kind, 'the rule that computed the value stays named');
        self::assertSame('audit', $derivation->basis[1]['type']);
        self::assertFalse($derivation->verified);
    }

    #[Test]
    public function partialTakesItsScoreAndNeverRaisesTheValue(): void
    {
        $this->verdict('partial', 70);

        self::assertSame(70, $this->rule()->apply($this->controls(100), $this->requirement, $this->tenant)?->percentage);
        self::assertSame(40, $this->rule()->apply($this->controls(40), $this->requirement, $this->tenant)?->percentage);
        self::assertSame(50, AuditResultRule::valueOf((new AuditChecklist())->setVerificationStatus('partial')));
    }

    #[Test]
    public function compliantOnAFullyMetRequirementVerifiesIt(): void
    {
        $this->verdict('compliant');

        self::assertTrue($this->rule()->apply($this->controls(100), $this->requirement, $this->tenant)?->verified);
        self::assertFalse($this->rule()->apply($this->controls(80), $this->requirement, $this->tenant)?->verified);
    }

    #[Test]
    public function withoutOtherRuleTheVerdictIsTheValue(): void
    {
        $this->verdict('compliant');
        $derivation = $this->rule()->apply(null, $this->requirement, $this->tenant);

        self::assertSame(100, $derivation?->percentage);
        self::assertSame(AuditResultRule::KIND_AUDIT, $derivation->kind);
    }

    #[Test]
    public function theNewestVerdictCountsAndNoVerdictChangesNothing(): void
    {
        $rule = $this->rule();
        $plain = $this->controls(90);
        self::assertSame($plain, $rule->apply($plain, $this->requirement, $this->tenant));

        $this->verdict('compliant');
        $this->verdict('non_compliant'); // older
        $rule->forgetTenant($this->tenant);
        self::assertSame(90, $rule->apply($plain, $this->requirement, $this->tenant)?->percentage);
        $rule->apply($plain, $this->requirement, $this->tenant);
        self::assertSame(2, $this->queries, 'verdicts are read once per organisation');
    }

    #[Test]
    public function aNotApplicableRequirementStaysUntouched(): void
    {
        $this->verdict('non_compliant');
        $excluded = new FulfillmentDerivation(null, FulfillmentRuleEngine::KIND_CONTROLS, [], false);

        self::assertSame($excluded, $this->rule()->apply($excluded, $this->requirement, $this->tenant));
    }

    private function rule(): AuditResultRule
    {
        $repo = $this->createStub(AuditChecklistRepository::class);
        $repo->method('findVerdictsSince')->willReturnCallback(function (): array {
            $this->queries++;

            return $this->verdicts;
        });

        return new AuditResultRule($repo);
    }

    private function controls(int $value): FulfillmentDerivation
    {
        return new FulfillmentDerivation($value, FulfillmentRuleEngine::KIND_CONTROLS, [['type' => 'control', 'value' => $value]]);
    }

    private function verdict(string $status, int $score = 0): void
    {
        $audit = (new InternalAudit())->setTitle('Internes Audit')->setAuditNumber('IA-1');
        $this->verdicts[] = (new AuditChecklist())
            ->setTenant($this->tenant)
            ->setAudit($audit)
            ->setRequirement($this->requirement)
            ->setVerificationStatus($status)
            ->setComplianceScore($score)
            ->setVerifiedAt(new DateTimeImmutable('-1 month'));
    }

    /**
     * @template T of object
     * @param T $entity
     * @return T
     */
    private static function withId(object $entity, int $id): object
    {
        (new ReflectionProperty($entity, 'id'))->setValue($entity, $id);

        return $entity;
    }
}
