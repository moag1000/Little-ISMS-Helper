<?php

declare(strict_types=1);

namespace App\Service\Register;

use App\Entity\ISMSContext;
use App\Entity\ManagementReview;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\RegisterType;
use App\Repository\ComplianceFrameworkRepository;
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
 *  - improvement opportunities of the last management review (6.1.1)
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
            RegisterType::OPPORTUNITY => $this->fromManagementReview($tenant),
            default => [],
        }];

        $taken = array_flip($this->entries->origins($tenant, $type));
        $unique = [];
        foreach ($proposals as $proposal) {
            if (!isset($taken[$proposal->origin]) && !isset($unique[$proposal->origin])) {
                $unique[$proposal->origin] = $proposal;
            }
        }

        return array_values($unique);
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
        foreach ([$review->getOpportunitiesForImprovement(), $review->getImprovementOpportunities()] as $text) {
            foreach (self::paragraphs($text) as $p) {
                $proposals[] = new RegisterProposal(
                    RegisterType::OPPORTUNITY,
                    $p['title'],
                    $p['description'],
                    'management_review.' . $review->getId() . '#' . $p['hash'],
                    'register.source.management_review',
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
