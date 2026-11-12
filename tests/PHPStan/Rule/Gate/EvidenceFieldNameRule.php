<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule\Gate;

use PhpParser\Node;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Gate 105 (S34): a persisted entity field whose NAME says "this is evidence" (who or when something was approved,
 * verified, reported, closed, signed; the facts `actual…`, `last…Review…`) must say who writes it:
 *
 *   #[ApiReadOnlyEvidence]       a step or action stamps it (REST/MCP read it, never write; forms show it disabled)
 *   #[ManagerOrResponsibleField] classification / result a manager or the holder sets
 *   #[CreateOnly]                fixed at creation, a manager corrects with a reason
 *   #[FrozenInEndState]          result of a sealed record, a correction needs a reason
 *   #[EvidenceRecord] (class)    the whole record is evidence (restore, import and API never change it)
 *   // @evidence-ok: <reason>    reviewed: a plain fact nobody's workflow owns
 *
 * `ApiEvidenceFieldNamesTest` saw only API resources and the suffixes At/By/Approved; this reads every entity, so
 * `RiskAppetite.approvedBy`, `actualCompletionDate` and `actualRpoAchieved` do not fall through again. Old cases
 * sit in phpstan-gates-baseline.neon with their reason (`reportUnmatched`: the list only shrinks).
 *
 * @implements Rule<InClassNode>
 */
final class EvidenceFieldNameRule implements Rule
{
    private const NAME = '/^(?:approved|verified|verificationDate|signed|accepted|certified|closed|resolved|submitted|confirmed|actual(?!Time|Minutes|Hours)|lastReview|lastAudit|lastTest)'
        . '|(?:ApprovedBy|ApprovedAt|VerifiedBy|VerifiedAt|ReportedAt|SubmittedAt|ClosedAt|AcceptedAt|SignedAt|ConfirmedAt|NotifiedAt|ReviewedAt|ReviewedBy)$/';

    private const DECLARED = [
        'App\Attribute\ApiReadOnlyEvidence',
        'App\Attribute\ManagerOrResponsibleField',
        'App\Attribute\CreateOnly',
        'App\Attribute\FrozenInEndState',
    ];

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $rel = GateAst::relative($scope->getFile());
        if (!str_starts_with($rel, 'src/Entity/')) {
            return [];
        }
        $class = $node->getOriginalNode();
        if (!$class instanceof Node\Stmt\Class_
            || GateAst::attributes($class, 'Doctrine\ORM\Mapping\Entity') === []
            || GateAst::attributes($class, 'App\Attribute\EvidenceRecord') !== []) {
            return [];
        }

        $errors = [];
        foreach ($class->getProperties() as $property) {
            if ($property->isStatic() || !$this->isPersisted($property)
                || GateAst::attributes($property, ...self::DECLARED) !== []
                || GateAst::markerOnMember($scope->getFile(), '@evidence-ok', $property)) {
                continue;
            }
            foreach ($property->props as $prop) {
                $name = $prop->name->toString();
                if (preg_match(self::NAME, $name) !== 1) {
                    continue;
                }
                $errors[] = RuleErrorBuilder::message(sprintf(
                    'Gate 105 [%s::%s]: %s looks like evidence (who/when something was approved, verified, reported, '
                    . 'closed) but says nobody writes it — mark it #[ApiReadOnlyEvidence] (a step stamps it), '
                    . '#[ManagerOrResponsibleField], #[CreateOnly] or #[FrozenInEndState], or review it with '
                    . '`// @evidence-ok: <reason>`.',
                    $rel,
                    $name,
                    $name,
                ))->identifier('gate105.evidenceField')->line($prop->getStartLine())->build();
            }
        }

        return $errors;
    }

    private function isPersisted(Property $property): bool
    {
        return GateAst::attributes(
            $property,
            'Doctrine\ORM\Mapping\Column',
            'Doctrine\ORM\Mapping\ManyToOne',
            'Doctrine\ORM\Mapping\OneToOne',
        ) !== [];
    }
}
