<?php

declare(strict_types=1);

namespace App\Lifecycle\EventListener;

use App\Entity\User;
use App\Lifecycle\Config\LifecycleConfigResolverInterface;
use App\Lifecycle\Exception\FourEyesRequiredException;
use App\Repository\UserRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
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
 *   3. carries at least one of the `roles` listed in the transition metadata
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
final class FourEyesValidator implements EventSubscriberInterface
{
    public function __construct(
        private readonly LifecycleConfigResolverInterface $resolver,
        private readonly UserRepository $userRepository,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.transition' => ['onTransition', 40],
        ];
    }

    public function onTransition(TransitionEvent $event): void
    {
        $effective = $this->resolver->resolve(
            $event->getSubject(),
            $event->getWorkflowName(),
            $event->getTransition()->getName(),
        );

        if (($effective['four_eyes'] ?? false) !== true) {
            return;
        }

        $context = $event->getContext();
        $initiator = $context['user'] ?? null;
        $approver = $context['four_eyes_approver'] ?? null;
        $requiredRoles = $effective['roles'] ?? [];

        // ── Single-user-tenant escape ──────────────────────────────────────
        // If the tenant has exactly one active user holding any of the
        // required roles, allow self-approval (initiator == approver, or
        // approver omitted) on the condition that a reason is supplied.
        // The audit-log will reflect the same user as initiator + approver,
        // making the offline counter-signature traceable.
        if ($this->isSingleApproverTenant($initiator, $requiredRoles)) {
            $reason = $context['reason'] ?? null;
            if (!is_string($reason) || trim($reason) === '') {
                // Force the user to document the offline-sign-off in the reason.
                // ReasonValidator at prio 50 already enforces this for transitions
                // with reason_required: true — this branch only triggers when the
                // YAML omitted reason_required but the single-approver-escape
                // still demands it for audit-trail integrity.
                throw FourEyesRequiredException::missing(
                    $event->getWorkflowName(),
                    $event->getTransition()->getName(),
                );
            }
            // Self-approval permitted. Record the initiator as approver too
            // (audit-trail will surface "self-approved (single-user tenant)").
            return;
        }

        if (!$approver instanceof User) {
            throw FourEyesRequiredException::missing(
                $event->getWorkflowName(),
                $event->getTransition()->getName(),
            );
        }

        if ($initiator instanceof User && $initiator->getId() === $approver->getId()) {
            throw FourEyesRequiredException::sameUser(
                $event->getWorkflowName(),
                $event->getTransition()->getName(),
            );
        }

        // Approver MUST carry one of the YAML `roles` for this transition.
        // Otherwise a regular USER could rubber-stamp a CISO-only transition
        // by submitting their own ID as approver. Defence-in-depth — the
        // LifecycleVoter already gates the INITIATOR by role; this gates the
        // APPROVER.
        if ($requiredRoles !== [] && !$this->approverHasAnyRole($approver, $requiredRoles)) {
            throw FourEyesRequiredException::approverLacksRole(
                $event->getWorkflowName(),
                $event->getTransition()->getName(),
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

        $approvers = [];
        foreach ($requiredRoles as $role) {
            foreach ($this->userRepository->findByRoleInTenant($role, $tenant) as $u) {
                $approvers[$u->getId()] = true;
            }
        }
        // ROLE_SUPER_ADMIN is always a valid approver — count those too.
        foreach ($this->userRepository->findByRoleInTenant('ROLE_SUPER_ADMIN', $tenant) as $u) {
            $approvers[$u->getId()] = true;
        }
        return count($approvers) <= 1;
    }

    /**
     * @param array<int, string> $requiredRoles
     */
    private function approverHasAnyRole(User $approver, array $requiredRoles): bool
    {
        $approverRoles = $approver->getRoles();
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
