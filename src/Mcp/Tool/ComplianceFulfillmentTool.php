<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirementFulfillment;
use App\Mcp\McpSession;
use App\Mcp\McpToolException;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Service\Compliance\Fulfillment\RecordCheckRule;
use App\Service\Compliance\Fulfillment\RequirementEvidence;
use App\Service\ModuleConfigurationService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Fulfilment per framework and requirement, with "why this value": the
 * value FulfillmentDerivationService derived from controls, records,
 * mappings, maturity and audit verdicts (or the override with its reason)
 * and the evidence behind it (RequirementEvidence). Read-only: changing a
 * value happens in the app (override with reason and review date).
 */
final class ComplianceFulfillmentTool extends AbstractMcpTool
{
    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworks,
        private readonly ComplianceRequirementFulfillmentRepository $fulfillments,
        private readonly RequirementEvidence $evidence,
        private readonly ModuleConfigurationService $modules,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function name(): string
    {
        return 'compliance_fulfillment';
    }

    public function description(): string
    {
        return 'Fulfilment of the organisation per framework (ISO27001, NIS2, DORA, GDPR, BSI, TISAX, …). '
            . 'Without arguments: one line per framework. With framework: every requirement with percentage, status, '
            . 'how the value came about (rule kind, override) and applicability. With framework and requirement: '
            . '"why this value" — each control, record check, mapping, maturity level or audit verdict that contributed, '
            . 'the override reason if any, and the evidence documents.';
    }

    public function isAvailable(McpSession $session): bool
    {
        return $this->modules->isModuleActiveForTenant('compliance', $session->tenant);
    }

    public function inputSchema(McpSession $session): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'framework' => ['type' => 'string', 'description' => 'Framework code, e.g. ISO27001, NIS2, DORA, GDPR'],
                'requirement' => ['type' => 'string', 'description' => 'Requirement number within the framework, e.g. A.5.1, 6.1.2, 21.2.a'],
            ],
        ];
    }

    public function call(array $arguments, McpSession $session): array
    {
        $code = self::string($arguments, 'framework', max: 50);
        if ($code === null) {
            return $this->overview($session);
        }
        $framework = $this->frameworks->findOneBy(['code' => $code]) ?? $this->frameworks->findOneBy(['code' => strtoupper($code)]);
        if (!$framework instanceof ComplianceFramework) {
            throw new McpToolException(sprintf('Unknown framework "%s". Call compliance_fulfillment without arguments for the codes.', $code));
        }
        $rows = $this->fulfillments->findByFrameworkAndTenant($framework, $session->tenant);
        $number = self::string($arguments, 'requirement', max: 50);
        if ($number !== null) {
            foreach ($rows as $fulfillment) {
                if (self::sameNumber((string) $framework->getCode(), (string) $fulfillment->getRequirement()?->getRequirementId(), $number)) {
                    return $this->why($fulfillment, $framework, $session);
                }
            }
            throw new McpToolException(sprintf('Requirement "%s" not found in %s for your organisation.', $number, $framework->getCode()));
        }

        return [
            'framework' => $framework->getCode(),
            'name' => $framework->getName(),
            'average_percentage' => $this->fulfillments->getAverageFulfillmentPercentage($framework, $session->tenant),
            'requirements' => array_map(fn (ComplianceRequirementFulfillment $f): array => $this->line($f), $rows),
            '_audit' => ['resource' => 'ComplianceFramework', 'record_id' => $framework->getId(), 'count' => count($rows)],
        ];
    }

    /** @return array<string, mixed> */
    private function overview(McpSession $session): array
    {
        $list = [];
        foreach ($this->frameworks->findActiveFrameworks() as $framework) {
            $rows = $this->fulfillments->findByFrameworkAndTenant($framework, $session->tenant);
            if ($rows === []) {
                continue;
            }
            $applicable = array_filter($rows, static fn (ComplianceRequirementFulfillment $f): bool => $f->isApplicable());
            $list[] = [
                'framework' => $framework->getCode(),
                'name' => $framework->getName(),
                'average_percentage' => $this->fulfillments->getAverageFulfillmentPercentage($framework, $session->tenant),
                'requirements' => count($rows),
                'applicable' => count($applicable),
                'fully_met' => count(array_filter($applicable, static fn (ComplianceRequirementFulfillment $f): bool => $f->getFulfillmentPercentage() >= 100)),
                'overridden' => count(array_filter($rows, static fn (ComplianceRequirementFulfillment $f): bool => $f->isOverridden())),
            ];
        }

        return ['frameworks' => $list, '_audit' => ['count' => count($list)]];
    }

    /** @return array<string, mixed> */
    private function line(ComplianceRequirementFulfillment $f): array
    {
        $requirement = $f->getRequirement();

        return [
            'requirement' => $requirement?->getRequirementId(),
            'title' => $requirement?->getTitle(),
            'applicable' => $f->isApplicable(),
            'percentage' => $f->getFulfillmentPercentage(),
            'status' => $f->getStatus(),
            'derived_from' => $f->getDerivationKind(),
            'overridden' => $f->isOverridden(),
        ];
    }

    /** @return array<string, mixed> */
    private function why(ComplianceRequirementFulfillment $f, ComplianceFramework $framework, McpSession $session): array
    {
        $requirement = $f->getRequirement();
        \assert($requirement !== null);
        $basis = [];
        foreach ($f->getDerivationBasis() as $row) {
            if (($row['type'] ?? null) === 'record' && is_string($row['label'] ?? null) && is_string($row['domain'] ?? null)) {
                $row['label'] = $this->translator->trans($row['label'], [], $row['domain'], $session->locale);
            }
            unset($row['domain'], $row['route']);
            $basis[] = $row;
        }
        $documents = [];
        foreach ($this->evidence->for($requirement, $session->tenant, $f) as $entry) {
            $documents[] = [
                'id' => $entry['document']->getId(),
                'title' => $entry['document']->getOriginalFilename(),
                'via' => array_values(array_map(static fn (array $s): string => trim($s['type'] . ' ' . ($s['ref'] ?? '')), $entry['sources'])),
            ];
        }

        return $this->line($f) + [
            'framework' => $framework->getCode(),
            'description' => $requirement->getDescription(),
            'derived_percentage' => $f->getDerivedPercentage(),
            'why' => $f->getDerivationKind() !== null
                ? $this->translator->trans('compliance.fulfillment_rule.why.' . $f->getDerivationKind(), ['%count%' => count($basis)], 'compliance', $session->locale)
                : $this->translator->trans('compliance.fulfillment_rule.no_rule', [], 'compliance', $session->locale),
            'basis' => $basis,
            'override' => $f->isOverridden() ? [
                'source' => $f->getOverrideSource(),
                'reason' => $f->getOverrideReason(),
                'review_due' => self::date($f->getOverrideReviewDue()),
            ] : null,
            'applicability_justification' => $f->getApplicabilityJustification(),
            'evidence' => $documents,
            'url' => $this->urls->generate('app_compliance_requirement_show', ['id' => $requirement->getId(), '_locale' => $session->locale], UrlGeneratorInterface::ABSOLUTE_URL),
            '_audit' => ['resource' => 'ComplianceRequirement', 'record_id' => $requirement->getId()],
        ];
    }

    private static function sameNumber(string $frameworkCode, string $requirementId, string $asked): bool
    {
        return RecordCheckRule::requirementNumber($frameworkCode, $requirementId)
            === RecordCheckRule::requirementNumber($frameworkCode, $asked);
    }
}
