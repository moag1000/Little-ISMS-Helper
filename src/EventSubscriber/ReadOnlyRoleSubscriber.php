<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\Voter\RecordResponsibleVoter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Keeps the reviewer roles read-only: ROLE_AUDITOR, ROLE_GROUP_CISO and
 * ROLE_KONZERN_AUDITOR inherit ROLE_USER, so every action gated with
 * ROLE_USER (risk / asset / supplier / incident forms, …) used to accept their
 * writes. A user who holds a reviewer role but not ROLE_MANAGER may only
 * send state-changing requests to the routes in ALLOWED_PREFIXES /
 * ALLOWED_SUFFIXES: audit work (checklists, findings, report), comments,
 * own profile / MFA / preferences, notifications, decisions they are
 * assigned to (the action's own voter still checks the assignment), own
 * reports and exports, plus every action whose #[IsGranted] names a reviewer
 * role (SoA snapshot, effectiveness review, exercise-log confirmation, …)
 * or accepts the record's responsible person (RecordResponsibleVoter).
 * Opening a create / edit / clone form is refused too, so the user does not
 * fill in a form that cannot be saved.
 */
final class ReadOnlyRoleSubscriber implements EventSubscriberInterface
{
    public const string MESSAGE_KEY = 'error.403.read_only_role';

    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** An action that names one of these in #[IsGranted] is built for reviewers. */
    /** ROLE_TOP_MGMT reads like a reviewer but takes the decisions that belong to top management. */
    private const array REVIEWER_ATTRIBUTES = ['ROLE_AUDITOR', 'ROLE_GROUP_CISO', 'ROLE_KONZERN_AUDITOR', 'ADMIN_HOLDING_READ', 'ROLE_TOP_MGMT'];

    private const array ALLOWED_PREFIXES = [
        // Audit work — never the audit record itself (app_audit_new/edit/clone/delete/close
        // stay with the manager; approve/reject name ROLE_AUDITOR and pass on their own).
        'app_audit_checklist',
        'app_audit_finding_',
        'app_audit_workbook_',
        'app_audit_submit_report',
        'app_audit_resubmit_report',
        'app_comment_',
        'app_profile_',
        'app_preferences_',
        'app_dashboard_',
        'app_guided_tour_',
        'app_welcome_',
        'app_notification_',
        'app_policy_ack_',       // acknowledging a policy
        'app_mfa_',
        'app_reauth_',
        'app_alva_hint_',
        'api_alva_hint_',
        'api_push_',
        'app_api_tokens_',       // API tokens are read-only themselves
        'app_mcp_',              // assistant connection; its API writes are sub-requests, checked again as `_api_` routes
        'report_builder_',       // own report definitions
        'app_mapping_onboarding_',
        'app_workflow_instance_approve',
        'app_workflow_instance_reject',
        'app_workflow_instance_clarify',
        'app_four_eyes_approve',
        'app_four_eyes_reject',
        'app_access_review_item_decide',
        'app_risk_approve_acceptance',   // tier check in RiskAcceptanceVoter (top management above the manager threshold)
        'app_risk_reject_acceptance',
        'app_quick_fix_',        // own guard (QuickFixGuard)
        'session_terminate_single',
        'app_login',
        'app_logout',
        'saml_',
        'setup_',
        'app_home',
        '_',                     // framework internals (profiler, error preview)
    ];

    /** Read-type POST endpoints: exports, previews. Pickers save a selection and are writes. */
    private const array ALLOWED_SUFFIXES = [
        '_export',
        '_export_dispatch',
        '_export_csv_dispatch',
        '_tenant_zip',
        '_tenant_zip_dispatch',
        '_preview',
        '_widget_data',
        '_evaluate',
    ];

    /** GET routes that only show a form whose submit would be refused. */
    private const array FORM_SUFFIXES = ['_new', '_new_quick', '_edit', '_clone'];

    public function __construct(
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => ['onController', 0]];
    }

    public function onController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');
        // Sub-requests only for API operations: the assistant connection (MCP)
        // runs them as sub-requests of POST /mcp, and the read-only rule has to
        // hold there too. Other sub-requests (fragments) stay untouched.
        if (!$event->isMainRequest() && !str_starts_with($route, '_api_')) {
            return;
        }
        if ($route === '' || !$this->isRestricted($route, $request->getMethod())
            || $this->isBuiltForReviewers($event)) {
            return;
        }
        if (!$this->isReadOnlyUser()) {
            return;
        }

        if ($this->wantsJson($request)) {
            $event->setController(static fn (): JsonResponse => new JsonResponse(
                ['ok' => false, 'error' => 'read_only_role'],
                Response::HTTP_FORBIDDEN,
            ));

            return;
        }

        throw new AccessDeniedHttpException(self::MESSAGE_KEY);
    }

    /** A reviewer role (auditor, group CISO, top management, …) without ROLE_MANAGER: reads only. */
    public function isReadOnlyUser(): bool
    {
        return $this->security->getUser() !== null
            && $this->security->isGranted('ROLE_AUDITOR')
            && !$this->security->isGranted('ROLE_MANAGER');
    }

    /** Whether a reviewer-role user may not call this route with this method. */
    public function isRestricted(string $route, string $method): bool
    {
        // API Platform operations are named `_api_…` and would slip through the
        // framework-internals prefix '_' below: the REST API is read-only for
        // reviewer roles, whatever the resource.
        if (str_starts_with($route, '_api_')) {
            return !in_array($method, self::SAFE_METHODS, true);
        }
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return false;
            }
        }
        if (in_array($method, self::SAFE_METHODS, true)) {
            return $this->isFormRoute($route);
        }
        foreach (self::ALLOWED_SUFFIXES as $suffix) {
            if (str_ends_with($route, $suffix)) {
                return false;
            }
        }

        return true;
    }

    private function isBuiltForReviewers(ControllerEvent $event): bool
    {
        foreach ($event->getAttributes(IsGranted::class) as $isGranted) {
            if (is_string($isGranted->attribute) && in_array($isGranted->attribute, self::REVIEWER_ATTRIBUTES, true)) {
                return true;
            }
            // "role OR responsible" (W1): the one named on the record acts there,
            // whatever their role — RecordResponsibleVoter has checked the assignment.
            if ($isGranted->attribute instanceof Expression
                && str_contains((string) $isGranted->attribute, RecordResponsibleVoter::RESPONSIBLE)) {
                return true;
            }
        }

        return false;
    }

    private function isFormRoute(string $route): bool
    {
        foreach (self::FORM_SUFFIXES as $suffix) {
            if (str_ends_with($route, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function wantsJson(Request $request): bool
    {
        return $request->isXmlHttpRequest()
            || str_contains((string) $request->headers->get('Accept', ''), 'application/json')
            || str_contains((string) $request->headers->get('Content-Type', ''), 'json')
            || str_starts_with($request->getPathInfo(), '/api/');
    }
}
