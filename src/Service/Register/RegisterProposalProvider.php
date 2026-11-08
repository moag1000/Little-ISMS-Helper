<?php

declare(strict_types=1);

namespace App\Service\Register;

use App\Entity\AuditFinding;
use App\Entity\ISMSContext;
use App\Entity\IsmsRegisterEntry;
use App\Entity\ManagementReview;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\RegisterType;
use App\Repository\AuditFindingRepository;
use App\Repository\BCExerciseRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\IncidentRepository;
use App\Repository\InterestedPartyRepository;
use App\Repository\IsmsRegisterEntryRepository;
use App\Repository\ISMSContextRepository;
use App\Repository\ManagementReviewRepository;
use App\Repository\SupplierRepository;
use App\Repository\UserRepository;
use App\Security\Persona\Persona;
use App\Security\Persona\PersonaResolver;
use App\Service\ModuleConfigurationService;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Register entries the tool can propose from records it already holds, so
 * nobody types a register from scratch (Paket 9, data reuse):
 *
 *  - free text of the ISMS context, one entry per paragraph (4.1, 5.3, A.5.31)
 *  - users holding a persona role → ISMS roles (5.3), with the persona's duties
 *  - required competences per persona role (7.2, NIS-2 Art. 20 (2) for the board)
 *  - legally mandatory frameworks and legal requirements of interested parties (A.5.31)
 *  - critical suppliers → interfaces of the scope (4.3 c)
 *  - improvements (10.1): opportunities of the last management review (9.3.2 g),
 *    audit findings of type "opportunity", lessons learned of incidents and
 *    BC exercises of the last 12 months
 *
 * Proposals whose origin an entry already carries (adopted, also archived)
 * never come back. A person adopts them ({@see RegisterProposalAdopter}).
 */
final class RegisterProposalProvider
{
    /** Personas that are ISMS roles in the sense of 5.3 (not mere access levels). */
    private const array ROLE_PERSONAS = [
        Persona::TOP_MANAGEMENT, Persona::CISO, Persona::ISB, Persona::RISK_MANAGER,
        Persona::COMPLIANCE_MANAGER, Persona::DPO, Persona::BCM_OFFICER,
    ];

    /** Required competence per persona role: persona => [level, reference]. */
    private const array PERSONA_COMPETENCES = [
        'top_management' => ['basic', 'NIS-2 Art. 20 (2) · § 38 (3) BSIG'],
        'ciso' => ['expert', 'ISO 27001 · ISO 27002'],
        'isb' => ['expert', 'ISO 27001 · ISO 27002'],
        'risk_manager' => ['intermediate', 'ISO 27005 · ISO 31000'],
        'compliance_manager' => ['intermediate', 'ISO 27001 4.2 · A.5.31'],
        'dpo' => ['expert', 'DSGVO Art. 37 (5)'],
        'bcm_officer' => ['intermediate', 'ISO 22301'],
    ];

    /** ISMS context text fields => [register, category]. */
    private const array CONTEXT_FIELDS = [
        'externalIssues' => [RegisterType::ISSUE_EXTERNAL, null],
        'internalIssues' => [RegisterType::ISSUE_INTERNAL, null],
        'rolesAndResponsibilities' => [RegisterType::ROLE, null],
        'legalRequirements' => [RegisterType::LEGAL_REQUIREMENT, 'law'],
        'regulatoryRequirements' => [RegisterType::LEGAL_REQUIREMENT, 'regulation'],
        'contractualObligations' => [RegisterType::LEGAL_REQUIREMENT, 'contract'],
    ];

    public function __construct(
        private readonly IsmsRegisterEntryRepository $entries,
        private readonly ISMSContextRepository $contexts,
        private readonly UserRepository $users,
        private readonly PersonaResolver $personas,
        private readonly ComplianceFrameworkRepository $frameworks,
        private readonly InterestedPartyRepository $parties,
        private readonly SupplierRepository $suppliers,
        private readonly ManagementReviewRepository $reviews,
        private readonly ModuleConfigurationService $modules,
        private readonly TranslatorInterface $translator,
        private readonly ?AuditFindingRepository $findings = null,
        private readonly ?IncidentRepository $incidents = null,
        private readonly ?BCExerciseRepository $exercises = null,
    ) {
    }

    /** @return list<RegisterProposal> */
    public function proposalsFor(Tenant $tenant, RegisterType $type): array
    {
        $proposals = [...$this->fromContextText($tenant, $type), ...match ($type) {
            RegisterType::ROLE => $this->fromPersonaRoles($tenant),
            RegisterType::COMPETENCE => $this->fromRoleEntries($tenant),
            RegisterType::LEGAL_REQUIREMENT => [...$this->fromFrameworks(), ...$this->fromPartyLegalRequirements($tenant)],
            RegisterType::SCOPE_INTERFACE => $this->fromCriticalSuppliers($tenant),
            RegisterType::MONITORING_SOURCE => $this->fromCloudSuppliers($tenant),
            RegisterType::IMPROVEMENT => [
                ...$this->fromManagementReview($tenant),
                ...$this->fromAuditOpportunities($tenant),
                ...$this->fromLessonsLearned($tenant),
            ],
            default => [],
        }];

        $taken = $this->entries->origins($tenant, $type);
        if ($type === RegisterType::IMPROVEMENT) {
            // review items adopted as opportunities before the improvement register existed
            $taken = [...$taken, ...$this->entries->origins($tenant, RegisterType::OPPORTUNITY)];
        }
        $taken = array_flip($taken);
        $unique = [];
        foreach ($proposals as $proposal) {
            if (!isset($taken[$proposal->origin]) && !isset($unique[$proposal->origin])) {
                $unique[$proposal->origin] = $proposal;
            }
        }

        return array_values($unique);
    }

    /**
     * Role entries taken over from a persona role whose user no longer holds
     * it (role withdrawn, account deactivated or removed): the register still
     * names a holder the organisation has not got (ISO 27001 5.3).
     *
     * @return list<IsmsRegisterEntry>
     */
    public function vacatedRoles(Tenant $tenant): array
    {
        $held = [];
        foreach ($this->users->findActiveByTenant($tenant) as $user) {
            foreach ($this->personas->assignedPersonasOf($user) as $persona) {
                $held[self::personaOrigin($persona, $user)] = true;
            }
        }
        $vacated = [];
        foreach ($this->entries->findByType($tenant, RegisterType::ROLE) as $entry) {
            $origin = (string) $entry->getOrigin();
            if (preg_match('/^persona\.[a-z_]+\.user\.\d+$/', $origin) === 1 && !isset($held[$origin])) {
                $vacated[] = $entry;
            }
        }

        return $vacated;
    }

    public function countFor(Tenant $tenant, RegisterType $type): int
    {
        return count($this->proposalsFor($tenant, $type));
    }

    /**
     * Paragraphs of a free text: one per line, bullets and numbering removed.
     *
     * @return list<array{title: string, description: ?string, hash: string}>
     */
    public static function paragraphs(?string $text): array
    {
        $result = [];
        foreach (preg_split('/\R+/u', (string) $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/^\s*(?:[-*•–·]|\d+[.)])\s*/u', '', $line));
            if (mb_strlen($line) < 3) {
                continue;
            }
            $title = $line;
            $description = null;
            $colon = mb_strpos($line, ':');
            if ($colon !== false && $colon >= 3 && $colon <= 80 && mb_strlen($line) > $colon + 1) {
                $title = trim(mb_substr($line, 0, $colon));
                $description = trim(mb_substr($line, $colon + 1));
            } elseif (mb_strlen($line) > 120) {
                $title = rtrim(mb_substr($line, 0, 117)) . '…';
                $description = $line;
            }
            $result[] = [
                'title' => $title,
                'description' => $description,
                'hash' => substr(sha1(mb_strtolower($line)), 0, 12),
            ];
        }

        return $result;
    }

    /** @return list<RegisterProposal> */
    private function fromContextText(Tenant $tenant, RegisterType $type): array
    {
        $context = $this->contexts->findOneBy(['tenant' => $tenant]);
        if (!$context instanceof ISMSContext) {
            return [];
        }
        $proposals = [];
        foreach (self::CONTEXT_FIELDS as $field => [$fieldType, $category]) {
            if ($fieldType !== $type) {
                continue;
            }
            $text = match ($field) {
                'externalIssues' => $context->getExternalIssues(),
                'internalIssues' => $context->getInternalIssues(),
                'rolesAndResponsibilities' => $context->getRolesAndResponsibilities(),
                'legalRequirements' => $context->getLegalRequirements(),
                'regulatoryRequirements' => $context->getRegulatoryRequirements(),
                default => $context->getContractualObligations(),
            };
            foreach (self::paragraphs($text) as $p) {
                $proposals[] = new RegisterProposal(
                    $type,
                    $p['title'],
                    $p['description'],
                    'context.' . $field . '#' . $p['hash'],
                    'register.source.context.' . $field,
                    category: $category,
                );
            }
        }

        return $proposals;
    }

    /** @return list<RegisterProposal> */
    private function fromPersonaRoles(Tenant $tenant): array
    {
        $proposals = [];
        $dpoUserFound = false;
        foreach ($this->users->findActiveByTenant($tenant) as $user) {
            foreach ($this->personas->assignedPersonasOf($user) as $persona) {
                if (!in_array($persona, self::ROLE_PERSONAS, true)) {
                    continue;
                }
                $dpoUserFound = $dpoUserFound || $persona === Persona::DPO;
                $proposals[] = new RegisterProposal(
                    RegisterType::ROLE,
                    $this->roleTitle($persona),
                    $this->translator->trans('register.persona_duty.' . $persona->value, [], 'register'),
                    self::personaOrigin($persona, $user),
                    'register.source.persona',
                    ownerUserId: $user->getId(),
                );
            }
        }
        $dpoName = $tenant->getDpoContactName();
        if (!$dpoUserFound && $dpoName !== null && trim($dpoName) !== '' && $this->modules->isModuleActive('privacy')) {
            $proposals[] = new RegisterProposal(
                RegisterType::ROLE,
                $this->roleTitle(Persona::DPO) . ' · ' . trim($dpoName),
                $this->translator->trans('register.persona_duty.dpo', [], 'register'),
                'tenant.dpo',
                'register.source.tenant_dpo',
            );
        }

        return $proposals;
    }

    /** @return list<RegisterProposal> one required competence per persona role present in the role register */
    private function fromRoleEntries(Tenant $tenant): array
    {
        $rolesByPersona = [];
        foreach ($this->entries->findByType($tenant, RegisterType::ROLE) as $role) {
            if (preg_match('/^persona\.([a-z_]+)\./', (string) $role->getOrigin(), $m) === 1) {
                $rolesByPersona[$m[1]][] = (int) $role->getId();
            }
        }
        $proposals = [];
        foreach ($rolesByPersona as $persona => $roleIds) {
            if (!isset(self::PERSONA_COMPETENCES[$persona])) {
                continue;
            }
            [$level, $reference] = self::PERSONA_COMPETENCES[$persona];
            $proposals[] = new RegisterProposal(
                RegisterType::COMPETENCE,
                $this->translator->trans('register.persona_competence.' . $persona, [], 'register'),
                null,
                'persona_competence.' . $persona,
                'register.source.role_register',
                category: $level,
                reference: $reference,
                backLinks: ['competence_entry' => $roleIds],
            );
        }

        return $proposals;
    }

    /** @return list<RegisterProposal> */
    private function fromFrameworks(): array
    {
        if (!$this->modules->isModuleActive('compliance')) {
            return [];
        }
        $proposals = [];
        foreach ($this->frameworks->findActiveFrameworks() as $framework) {
            if ($framework->isMandatory() !== true) {
                continue;
            }
            $proposals[] = new RegisterProposal(
                RegisterType::LEGAL_REQUIREMENT,
                (string) $framework->getName(),
                $framework->getScopeDescription(),
                'framework.' . $framework->getCode(),
                'register.source.framework',
                category: 'law',
                reference: $framework->getRegulatoryBody(),
                links: ['compliance_framework' => [(int) $framework->getId()]],
            );
        }

        return $proposals;
    }

    /** @return list<RegisterProposal> */
    private function fromPartyLegalRequirements(Tenant $tenant): array
    {
        if (!$this->modules->isModuleActive('interested_parties')) {
            return [];
        }
        $proposals = [];
        foreach ($this->parties->findBy(['tenant' => $tenant]) as $party) {
            foreach ($party->getLegalRequirements() ?? [] as $item) {
                $text = is_array($item)
                    ? (string) ($item['title'] ?? $item['name'] ?? $item['requirement'] ?? $item['description'] ?? '')
                        . (($item['reference'] ?? '') !== '' ? ' (' . $item['reference'] . ')' : '')
                    : (string) $item;
                foreach (self::paragraphs($text) as $p) {
                    $proposals[] = new RegisterProposal(
                        RegisterType::LEGAL_REQUIREMENT,
                        $p['title'],
                        $p['description'],
                        'party.' . $party->getId() . '#' . $p['hash'],
                        'register.source.interested_party',
                        category: 'contract',
                        links: ['interested_party' => [(int) $party->getId()]],
                    );
                }
            }
        }

        return $proposals;
    }

    /** @return list<RegisterProposal> */
    private function fromCriticalSuppliers(Tenant $tenant): array
    {
        if (!$this->modules->isModuleActive('suppliers')) {
            return [];
        }
        $proposals = [];
        foreach ($this->suppliers->findCriticalSuppliersByTenant($tenant) as $supplier) {
            $proposals[] = new RegisterProposal(
                RegisterType::SCOPE_INTERFACE,
                $this->translator->trans('register.proposal.interface_title', ['%name%' => $supplier->getName()], 'register'),
                $supplier->getServiceProvided(),
                'supplier.' . $supplier->getId(),
                'register.source.supplier',
                category: 'supplier',
                links: ['supplier' => [(int) $supplier->getId()]],
            );
        }

        return $proposals;
    }

    /**
     * A.8.16: every cloud service in use (A.5.23) is a source to monitor — the
     * provider's logs and alerts reach the organisation, someone evaluates them.
     *
     * @return list<RegisterProposal>
     */
    private function fromCloudSuppliers(Tenant $tenant): array
    {
        if (!$this->modules->isModuleActive('suppliers')) {
            return [];
        }
        $proposals = [];
        foreach ($this->suppliers->findBy(['tenant' => $tenant, 'isCloudService' => true]) as $supplier) {
            if (!in_array($supplier->getStatus(), ['active', 'evaluation'], true)) {
                continue;
            }
            $proposals[] = new RegisterProposal(
                RegisterType::MONITORING_SOURCE,
                $this->translator->trans('register.proposal.monitoring_title', ['%name%' => $supplier->getName()], 'register'),
                $supplier->getServiceProvided(),
                'supplier.' . $supplier->getId(),
                'register.source.supplier',
                category: 'cloud',
                links: ['supplier' => [(int) $supplier->getId()]],
            );
        }

        return $proposals;
    }

    /** @return list<RegisterProposal> */
    private function fromManagementReview(Tenant $tenant): array
    {
        $review = $this->reviews->createQueryBuilder('m')
            ->where('m.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('m.reviewDate', \SortDirection::Descending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        if (!$review instanceof ManagementReview) {
            return [];
        }
        $proposals = [];
        foreach (self::paragraphs($review->getOpportunitiesForImprovement()) as $p) {
            $proposals[] = new RegisterProposal(
                RegisterType::IMPROVEMENT,
                $p['title'],
                $p['description'],
                'management_review.' . $review->getId() . '#' . $p['hash'],
                'register.source.management_review',
                category: 'management_review',
            );
        }

        return $proposals;
    }

    /** @return list<RegisterProposal> audit findings that are an opportunity for improvement */
    private function fromAuditOpportunities(Tenant $tenant): array
    {
        if ($this->findings === null || !$this->modules->isModuleActive('audits')) {
            return [];
        }
        $proposals = [];
        foreach ($this->findings->findBy(['tenant' => $tenant, 'type' => AuditFinding::TYPE_OPPORTUNITY]) as $finding) {
            $proposals[] = new RegisterProposal(
                RegisterType::IMPROVEMENT,
                (string) $finding->getTitle(),
                $finding->getDescription(),
                'audit_finding.' . $finding->getId(),
                'register.source.audit_finding',
                category: 'audit',
            );
        }

        return $proposals;
    }

    /** @return list<RegisterProposal> lessons learned of the last 12 months, one per paragraph */
    private function fromLessonsLearned(Tenant $tenant): array
    {
        $since = new \DateTimeImmutable('-12 months');
        $sources = [];
        if ($this->incidents !== null && $this->modules->isModuleActive('incidents')) {
            foreach ($this->incidents->findBy(['tenant' => $tenant]) as $incident) {
                if ($incident->getDetectedAt() === null || $incident->getDetectedAt() >= $since) {
                    $sources[] = ['incident.' . $incident->getId(), $incident->getLessonsLearned(), 'incident'];
                }
            }
        }
        if ($this->exercises !== null && $this->modules->isModuleActive('bcm')) {
            foreach ($this->exercises->findBy(['tenant' => $tenant]) as $exercise) {
                if ($exercise->getExerciseDate() === null || $exercise->getExerciseDate() >= $since) {
                    $sources[] = ['bc_exercise.' . $exercise->getId(), $exercise->getLessonsLearned(), 'exercise'];
                }
            }
        }
        $proposals = [];
        foreach ($sources as [$origin, $text, $category]) {
            foreach (self::paragraphs($text) as $p) {
                $proposals[] = new RegisterProposal(
                    RegisterType::IMPROVEMENT,
                    $p['title'],
                    $p['description'],
                    $origin . '#' . $p['hash'],
                    'register.source.' . $category,
                    category: $category,
                );
            }
        }

        return $proposals;
    }

    private function roleTitle(Persona $persona): string
    {
        return $this->translator->trans('register.persona_role.' . $persona->value, [], 'register');
    }

    public static function personaOrigin(Persona $persona, User $user): string
    {
        return sprintf('persona.%s.user.%d', $persona->value, (int) $user->getId());
    }
}
