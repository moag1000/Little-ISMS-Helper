<?php

declare(strict_types=1);

namespace App\Lifecycle\EventListener;

use App\Entity\User;
use App\Lifecycle\Config\LifecycleConfigResolverInterface;
use App\Lifecycle\Exception\FourEyesRequiredException;
use App\Repository\UserRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Workflow\Event\TransitionEvent;

/**
 * Pre-apply validator: when transition metadata declares
 * `four_eyes: true` (YAML or DB-overlay), the context-array passed to
 * `Workflow::apply()` MUST contain a `four_eyes_approver` (App\Entity\User)
 * that:
 *   1. is non-null
 *   2. differs from the `user` (initiator) — UNLESS the tenant has
 *      exactly ONE active user holding any of the required roles
 *      (single-user-tenant escape, see below)
 *   3. carries at least one of the `roles` listed in the transition metadata,
 *      resolved through the Symfony role hierarchy (ROLE_DPO / ROLE_ADMIN
 *      reach ROLE_MANAGER) — UNLESS the caller sets
 *      `four_eyes_approver_authorized: true`, meaning it has already
 *      verified the approver's authority against a more specific rule
 *      (WorkflowService: the step's approver_role / approver_users).
 *      The distinct-user rule (2) is never waived by that flag.
 *
 * Otherwise throws FourEyesRequiredException (caught by LifecycleService
 * + translated to 422 in LifecycleController).
 *
 * ── Single-user-tenant escape (KMU pragma) ──────────────────────────────
 * Many KMU run their ISMS as a one-person operation (ISB == Geschäftsführer).
 * Hard-blocking 4-eyes would make the tool unusable for them. So if the
 * tenant has exactly ONE active user carrying any of the required roles,
 * self-approval is permitted on the condition that:
 *   - the transition declares `reason_required: true` (most do) OR a
 *     reason is in fact supplied
 *   - the audit-log records both `user` and `four_eyes_approver` as the
 *     SAME id, which makes the offline-sign-off auditable
 *
 * The expectation is that the user obtains an offline counter-signature
 * (paper, email, signed PDF) and documents it in the reason field —
 * "Offline-Gegenzeichnung durch Hr. Müller (Bürgermeister), Mail v. 12.05."
 *
 * ISO 27001:2022 Cl. 5.3 (Segregation of Duties) — required on destructive
 * / irreversible transitions: dispose, publish, close, approve, accept.
 *
 * Priority 40 = runs AFTER ReasonValidator (50). Reason gets validated first
 * so a UI can show "missing reason" + "missing approver" in the same
 * round-trip if both are absent.
 */
#[AsEventListener(event: 'workflow.transition', method: 'onTransition', priority: 40)]
final class FourEyesValidator
{
    public function __construct(
        private readonly LifecycleConfigResolverInterface $resolver,
        private readonly UserRepository $userRepository,
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {}

    public function onTransition(TransitionEvent $event): void
    {
        $this->assertSatisfied(
            $event->getSubject(),
            $event->getWorkflowName(),
            $event->getTransition()->getName(),
            $event->getContext(),
        );
    }

    /**
     * Runs the four-eyes check without applying the transition. Callers
     * that must not leave partial writes behind when the transition is
     * refused (e.g. a section sign-off that releases a host workflow)
     * call this BEFORE mutating anything; the listener runs the very same
     * check again when the transition is applied.
     *
     * @param array<string, mixed> $context same keys LifecycleService passes
     *                                      to Workflow::apply()
     *
     * @throws FourEyesRequiredException
     */
    public function assertSatisfied(object $subject, string $workflowName, string $transitionName, array $context): void
    {
        $effective = $this->resolver->resolve($subject, $workflowName, $transitionName);

        if (($effective['four_eyes'] ?? false) !== true) {
            return;
        }

        $initiator = $context['user'] ?? null;
        $approver = $context['four_eyes_approver'] ?? null;
        $requiredRoles = $effective['roles'] ?? [];

        // ── Single-user-tenant escape ──────────────────────────────────────
        // If the tenant has exactly one active user holding any of the
        // required roles, allow self-approval (initiator == approver, or
        // approver omitted) on the condition that a reason is supplied.
        // The audit-log will reflect the same user as initiator + approver,
        // making the offline counter-signature traceable.
        //
        // The escape ONLY kicks in when the caller did NOT supply a distinct
        // approver. If a different valid approver is present, fall through to
        // the standard checks (approver-role + initiator≠approver) — those
        // succeed exactly when 4-eyes is genuinely satisfied. Without this
        // guard, two-step workflows where the controller already paired
        // (reporter → approver) would be falsely treated as single-user
        // setups and demand a reason they have no place to provide.
        $approverIsInitiator = $approver instanceof User
            && $initiator instanceof User
            && $initiator->getId() === $approver->getId();
        $approverOmitted = !($approver instanceof User);
        if (($approverOmitted || $approverIsInitiator)
            && $this->isSingleApproverTenant($initiator, $requiredRoles)) {
            $reason = $context['reason'] ?? null;
            if (!is_string($reason) || trim($reason) === '') {
                // Force the user to document the offline-sign-off in the reason.
                // ReasonValidator at prio 50 already enforces this for transitions
                // with reason_required: true — this branch only triggers when the
                // YAML omitted reason_required but the single-approver-escape
                // still demands it for audit-trail integrity.
                throw FourEyesRequiredException::missing(
                    $workflowName,
                    $transitionName,
                );
            }
            // Self-approval permitted. Record the initiator as approver too
            // (audit-trail will surface "self-approved (single-user tenant)").
            return;
        }

        if (!$approver instanceof User) {
            throw FourEyesRequiredException::missing(
                $workflowName,
                $transitionName,
            );
        }

        if ($initiator instanceof User && $initiator->getId() === $approver->getId()) {
            throw FourEyesRequiredException::sameUser(
                $workflowName,
                $transitionName,
            );
        }

        // Approver authority already verified by the caller against a more
        // specific rule (workflow step approver_role / approver_users).
        if (($context['four_eyes_approver_authorized'] ?? false) === true) {
            return;
        }

        // Approver MUST carry one of the YAML `roles` for this transition.
        // Otherwise a regular USER could rubber-stamp a CISO-only transition
        // by submitting their own ID as approver. Defence-in-depth — the
        // LifecycleVoter already gates the INITIATOR by role; this gates the
        // APPROVER.
        if ($requiredRoles !== [] && !$this->approverHasAnyRole($approver, $requiredRoles)) {
            throw FourEyesRequiredException::approverLacksRole(
                $workflowName,
                $transitionName,
                $requiredRoles,
            );
        }
    }

    /**
     * True when the initiator's tenant has at most ONE active user holding
     * any of the required roles — i.e. there is nobody else to act as the
     * second approver. KMU-pragma documented in class doc-block.
     *
     * @param array<int, string> $requiredRoles
     */
    private function isSingleApproverTenant(mixed $initiator, array $requiredRoles): bool
    {
        if (!$initiator instanceof User) {
            return false;
        }
        $tenant = $initiator->getTenant();
        if ($tenant === null) {
            return false;
        }
        if ($requiredRoles === []) {
            // No role-gating on the transition — fall back to counting any
            // active tenant-user. If only one exists, escape applies.
            $allActive = $this->userRepository->findBy([
                'tenant' => $tenant,
                'isActive' => true,
            ]);
            return count($allActive) <= 1;
        }

        // Count with the same predicate the approver check uses (role
        // hierarchy + SUPER_ADMIN), so a DPO who inherits ROLE_MANAGER
        // counts as a second eligible approver.
        $approvers = [];
        foreach ($this->userRepository->findBy(['tenant' => $tenant, 'isActive' => true]) as $u) {
            if ($this->approverHasAnyRole($u, $requiredRoles)) {
                $approvers[$u->getId()] = true;
            }
        }
        return count($approvers) <= 1;
    }

    /**
     * @param array<int, string> $requiredRoles
     */
    private function approverHasAnyRole(User $approver, array $requiredRoles): bool
    {
        // Resolve through the Symfony role hierarchy (security.yaml), the
        // same way isGranted() does: ROLE_ADMIN / ROLE_DPO / ROLE_CISO …
        // reach ROLE_MANAGER.
        $approverRoles = $this->roleHierarchy->getReachableRoleNames($approver->getRoles());
        foreach ($requiredRoles as $required) {
            if (in_array($required, $approverRoles, true)) {
                return true;
            }
        }
        // ROLE_SUPER_ADMIN bypass — matches Symfony's role-hierarchy ergonomics
        // for the highest-tier admin. Aligns with TenantScopedAdminVoter which
        // also treats SUPER_ADMIN as a wildcard approver.
        return in_array('ROLE_SUPER_ADMIN', $approverRoles, true);
    }
}
