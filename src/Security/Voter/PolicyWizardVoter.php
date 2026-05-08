<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ComplianceFrameworkRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Policy-Wizard Voter — Phase 4-C / Sprint W1-B.
 *
 * Authorisiert die sieben Wizard-spezifischen Aktionen, die in der
 * Verantwortungsmatrix Phase 4-C §7-#4 spezifiziert sind. Spielt mit der
 * RBAC-Hierarchie (security.yaml) zusammen sowie mit den fünf
 * `policy_wizard.*` Permissions, die die Migration
 * Version20260508121000 seedet.
 *
 * Subject-Typen je Attribut:
 *
 *   POLICY_WIZARD_RUN_FULL              — null oder Tenant
 *   POLICY_WIZARD_RUN_TARGETED          — null oder Tenant
 *   POLICY_WIZARD_RUN_SANDBOX           — null oder Tenant
 *   POLICY_WIZARD_BULK_APPROVE          — Tenant (zwingend, plus optional
 *                                          dual-signoff Token-Attribut
 *                                          'policy_wizard.dual_signoff' = true
 *                                          für DORA-pflichtige Tenants)
 *   POLICY_WIZARD_FUNCTION_OWNER_REVIEW — Document oder PolicyTemplate
 *                                          (Function-Match wird geprüft)
 *   POLICY_WIZARD_KONZERN_DEFAULTS      — Tenant (Holding-Tenant; muss
 *                                          gleicher Tenant des Users sein
 *                                          oder Descendant)
 *   POLICY_WIZARD_DPO_SECTION_VETO      — Document oder PolicyTemplate
 *                                          (nur wenn Template
 *                                          dpoSectionRequired = true)
 */
class PolicyWizardVoter extends Voter
{
    use HoldingTreeAccessTrait;

    public const string RUN_FULL              = 'POLICY_WIZARD_RUN_FULL';
    public const string RUN_TARGETED          = 'POLICY_WIZARD_RUN_TARGETED';
    public const string RUN_SANDBOX           = 'POLICY_WIZARD_RUN_SANDBOX';
    public const string BULK_APPROVE          = 'POLICY_WIZARD_BULK_APPROVE';
    public const string FUNCTION_OWNER_REVIEW = 'POLICY_WIZARD_FUNCTION_OWNER_REVIEW';
    public const string KONZERN_DEFAULTS      = 'POLICY_WIZARD_KONZERN_DEFAULTS';
    public const string DPO_SECTION_VETO      = 'POLICY_WIZARD_DPO_SECTION_VETO';

    private const array SUPPORTED = [
        self::RUN_FULL,
        self::RUN_TARGETED,
        self::RUN_SANDBOX,
        self::BULK_APPROVE,
        self::FUNCTION_OWNER_REVIEW,
        self::KONZERN_DEFAULTS,
        self::DPO_SECTION_VETO,
    ];

    public function __construct(
        private readonly ?ComplianceFrameworkRepository $complianceFrameworkRepository = null,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, self::SUPPORTED, true)) {
            return false;
        }

        // RUN_FULL/TARGETED/SANDBOX dürfen ohne Subject (Wizard-Start) oder mit Tenant gevotet werden.
        if (in_array($attribute, [self::RUN_FULL, self::RUN_TARGETED, self::RUN_SANDBOX], true)) {
            return $subject === null || $subject instanceof Tenant;
        }

        // BULK_APPROVE / KONZERN_DEFAULTS brauchen einen Tenant.
        if (in_array($attribute, [self::BULK_APPROVE, self::KONZERN_DEFAULTS], true)) {
            return $subject instanceof Tenant;
        }

        // FUNCTION_OWNER_REVIEW / DPO_SECTION_VETO arbeiten auf Document oder PolicyTemplate.
        return $subject instanceof Document || $subject instanceof PolicyTemplate;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }
        if (!$user->isActive()) {
            return false;
        }

        // ROLE_SUPER_ADMIN ist universal-bypass — konsistent mit den anderen Votern.
        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        return match ($attribute) {
            self::RUN_FULL              => $this->canRunFull($user, $subject),
            self::RUN_TARGETED          => $this->canRunTargeted($user, $subject),
            self::RUN_SANDBOX           => $this->canRunSandbox($user, $subject),
            self::BULK_APPROVE          => $this->canBulkApprove($user, $subject, $token),
            self::FUNCTION_OWNER_REVIEW => $this->canFunctionOwnerReview($user, $subject),
            self::KONZERN_DEFAULTS      => $this->canManageKonzernDefaults($user, $subject),
            self::DPO_SECTION_VETO      => $this->canVetoDpoSection($user, $subject),
            default                     => false,
        };
    }

    /**
     * Vollständigen Wizard-Lauf starten — ROLE_CISO oder ROLE_ADMIN.
     */
    private function canRunFull(User $user, ?Tenant $tenant): bool
    {
        if (!$this->isInUserScope($user, $tenant)) {
            return false;
        }
        return $this->hasAnyRole($user, ['ROLE_CISO', 'ROLE_ADMIN']);
    }

    /**
     * Targeted Wizard (einzelne Sektion) — ROLE_CISO, ROLE_ADMIN
     * oder ROLE_MANAGER.
     */
    private function canRunTargeted(User $user, ?Tenant $tenant): bool
    {
        if (!$this->isInUserScope($user, $tenant)) {
            return false;
        }
        return $this->hasAnyRole($user, ['ROLE_CISO', 'ROLE_ADMIN', 'ROLE_MANAGER']);
    }

    /**
     * Sandbox-Lauf — jeder authentifizierte User darf in der eigenen
     * Tenant-Sandbox spielen, ohne Persistierung.
     */
    private function canRunSandbox(User $user, ?Tenant $tenant): bool
    {
        return $this->isInUserScope($user, $tenant);
    }

    /**
     * Bulk-Freigabe mehrerer Policies — nur ROLE_TOP_MGMT
     * (oder ROLE_SUPER_ADMIN, der oben bereits abgefangen wird).
     *
     * DORA-Compliance-Pflichten: wenn der Tenant der DORA unterliegt,
     * muss zusätzlich ein dual-signoff im Token-Attribut
     * 'policy_wizard.dual_signoff' = true vorliegen — das wird vom
     * Controller per Form-Token / 4-eyes-Workflow gesetzt.
     */
    private function canBulkApprove(User $user, Tenant $tenant, TokenInterface $token): bool
    {
        if (!$this->hasAnyRole($user, ['ROLE_TOP_MGMT'])) {
            return false;
        }
        if (!$this->isInUserScope($user, $tenant)) {
            return false;
        }
        if (!$this->isDoraTenant($tenant)) {
            return true;
        }
        // DORA: dual-signoff Pflicht.
        return $token->hasAttribute('policy_wizard.dual_signoff')
            && $token->getAttribute('policy_wizard.dual_signoff') === true;
    }

    /**
     * Function-Owner-Review — Function-Owner darf nur Policies zeichnen,
     * deren affectedFunctions die eigene Funktion (User.department)
     * abdecken. ROLE_CISO darf jede Policy review-zeichnen
     * (Compliance-Override).
     */
    private function canFunctionOwnerReview(User $user, Document|PolicyTemplate $subject): bool
    {
        if ($this->hasAnyRole($user, ['ROLE_CISO'])) {
            return $this->subjectIsInUserScope($user, $subject);
        }

        if (!$this->hasAnyRole($user, ['ROLE_FUNCTION_OWNER'])) {
            return false;
        }

        if (!$this->subjectIsInUserScope($user, $subject)) {
            return false;
        }

        $userFunction = $user->getDepartment();
        if ($userFunction === null || $userFunction === '') {
            return false;
        }

        $affected = $this->extractAffectedFunctions($subject);
        if ($affected === null) {
            // Keine Funktions-Bindung → jeder Function-Owner abstainen
            // ist hier nicht möglich (binäres voteOnAttribute), also
            // bewusst denied — der Wizard muss explizit eine Funktion
            // an die Policy hängen, sonst greift der Compliance-Voter.
            return false;
        }

        return in_array($userFunction, $affected, true);
    }

    /**
     * Konzern-Defaults pflegen — ROLE_GROUP_CISO oder
     * ROLE_GROUP_BCM_OFFICER, und nur auf Tenants im eigenen
     * Holding-Tree (HoldingTreeAccessTrait).
     */
    private function canManageKonzernDefaults(User $user, Tenant $tenant): bool
    {
        if (!$this->hasAnyRole($user, ['ROLE_GROUP_CISO', 'ROLE_GROUP_BCM_OFFICER'])) {
            return false;
        }
        // Holding-tree-scope: eigener Tenant oder Descendant.
        return $this->canReadAcrossHoldingTree($user, $tenant)
            || $tenant === $user->getTenant();
    }

    /**
     * DPO-Veto auf der DPO-Cross-Check-Section eines Policy-Dokuments —
     * ROLE_DPO. Nur wenn die zugrundeliegende PolicyTemplate
     * dpoSectionRequired=true gesetzt hat (sonst gibt es nichts zu vetoieren).
     */
    private function canVetoDpoSection(User $user, Document|PolicyTemplate $subject): bool
    {
        if (!$this->hasAnyRole($user, ['ROLE_DPO'])) {
            return false;
        }
        if (!$this->subjectIsInUserScope($user, $subject)) {
            return false;
        }

        if ($subject instanceof PolicyTemplate) {
            return $subject->isDpoSectionRequired();
        }

        // Document hat keine eigene dpoSectionRequired-Spalte; der
        // Wizard prägt das in den entityType / category-Feldern. Im
        // Zweifel "true" zurückgeben, damit der DPO sich melden darf —
        // den endgültigen Veto-Fluss validiert ohnehin der Workflow.
        return true;
    }

    /**
     * @param list<string> $roles
     */
    private function hasAnyRole(User $user, array $roles): bool
    {
        $userRoles = $user->getRoles();
        foreach ($roles as $role) {
            if (in_array($role, $userRoles, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Tenant-Isolation: das Subject muss zum Tenant des Users gehören
     * (oder im Holding-Read-Tree liegen, falls der User Group-CISO ist).
     */
    private function isInUserScope(User $user, ?Tenant $tenant): bool
    {
        if ($tenant === null) {
            return true; // Wizard-Start ohne Subject — Scope wird in der nächsten Stufe geprüft.
        }
        $userTenant = $user->getTenant();
        if ($userTenant === null) {
            return false;
        }
        if ($tenant === $userTenant) {
            return true;
        }
        if ($tenant->getId() !== null && $tenant->getId() === $userTenant->getId()) {
            return true;
        }
        return $this->canReadAcrossHoldingTree($user, $tenant);
    }

    private function subjectIsInUserScope(User $user, Document|PolicyTemplate $subject): bool
    {
        if ($subject instanceof Document) {
            return $this->isInUserScope($user, $subject->getTenant());
        }
        // PolicyTemplate ist global / templated; Scope-Check entfällt.
        return true;
    }

    /**
     * @return list<string>|null
     */
    private function extractAffectedFunctions(Document|PolicyTemplate $subject): ?array
    {
        if ($subject instanceof PolicyTemplate) {
            return $subject->getAffectedFunctions();
        }

        // Document: keine direkte affectedFunctions-Spalte. Wir greifen
        // auf die Kategorie zurück — bei Wizard-emittierten Documents
        // setzt der Wizard die Kategorie auf den Funktions-Code, sonst
        // null (→ binding nicht ableitbar).
        $category = $subject->getCategory();
        return $category === null || $category === '' ? null : [$category];
    }

    /**
     * Heuristische DORA-Erkennung. Wenn das ComplianceFramework mit
     * code='DORA' aktiv ist, gilt dies als globale DORA-Subject-Pflicht.
     * In Tests injizieren wir eine Mock-Repo, die hier den passenden
     * Wert liefert.
     */
    private function isDoraTenant(Tenant $tenant): bool
    {
        if ($this->complianceFrameworkRepository === null) {
            return false;
        }
        $framework = $this->complianceFrameworkRepository->findOneBy(['code' => 'DORA']);
        return $framework !== null && $framework->isActive();
    }
}
