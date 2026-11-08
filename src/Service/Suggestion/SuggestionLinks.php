<?php

declare(strict_types=1);

namespace App\Service\Suggestion;

use App\Entity\Asset;
use App\Entity\AuditFinding;
use App\Entity\BCExercise;
use App\Entity\BusinessContinuityPlan;
use App\Entity\BusinessProcess;
use App\Entity\ComplianceRequirement;
use App\Entity\Control;
use App\Entity\CorrectiveAction;
use App\Entity\FulfillmentInheritanceLog;
use App\Entity\Incident;
use App\Entity\InternalAudit;
use App\Entity\InterestedParty;
use App\Entity\IsmsRegisterEntry;
use App\Entity\ManagementDecision;
use App\Entity\ProcessingActivity;
use App\Entity\Risk;
use App\Entity\RiskTreatmentPlan;
use App\Entity\Suggestion;
use App\Entity\Supplier;
use App\Entity\Vulnerability;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where a suggestion's record and its result live in the UI. A kind may put
 * its own target into the payload as `_link` => [route, params].
 */
final readonly class SuggestionLinks
{
    /** @var array<class-string, string> */
    private const array SHOW_ROUTES = [
        Asset::class => 'app_asset_show',
        Risk::class => 'app_risk_show',
        Control::class => 'app_soa_show',
        RiskTreatmentPlan::class => 'app_risk_treatment_plan_show',
        Incident::class => 'app_incident_show',
        Vulnerability::class => 'app_vulnerability_show',
        AuditFinding::class => 'app_audit_finding_show',
        CorrectiveAction::class => 'app_corrective_action_show',
        BusinessProcess::class => 'app_business_process_show',
        BusinessContinuityPlan::class => 'app_bc_plan_show',
        BCExercise::class => 'app_bc_exercise_show',
        InterestedParty::class => 'app_interested_party_show',
        IsmsRegisterEntry::class => 'app_register_entry_show',
        ComplianceRequirement::class => 'app_compliance_requirement_show',
        Supplier::class => 'app_supplier_show',
        ProcessingActivity::class => 'app_processing_activity_show',
        InternalAudit::class => 'app_audit_show',
        ManagementDecision::class => 'app_management_decision_show',
    ];

    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function subject(Suggestion $suggestion): ?string
    {
        $link = $suggestion->getPayload()['_link'] ?? null;
        if (is_array($link) && is_string($link[0] ?? null)) {
            return $this->urls->generate($link[0], (array) ($link[1] ?? []));
        }
        if ($suggestion->getSubjectClass() === FulfillmentInheritanceLog::class) {
            $framework = $suggestion->getPayload()['framework'] ?? null;

            return is_string($framework) ? $this->urls->generate('app_compliance_inheritance_queue', ['frameworkCode' => $framework]) : null;
        }

        return $this->show($suggestion->getSubjectClass(), $suggestion->getSubjectId());
    }

    public function result(Suggestion $suggestion): ?string
    {
        return $this->show($suggestion->getResultClass(), $suggestion->getResultId());
    }

    public function show(?string $class, ?int $id): ?string
    {
        $route = $class !== null ? (self::SHOW_ROUTES[$class] ?? null) : null;

        return $route !== null && $id !== null ? $this->urls->generate($route, ['id' => $id]) : null;
    }
}
