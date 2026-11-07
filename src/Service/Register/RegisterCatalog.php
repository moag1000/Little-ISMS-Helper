<?php

declare(strict_types=1);

namespace App\Service\Register;

use App\Entity\Asset;
use App\Entity\BusinessProcess;
use App\Entity\Control;
use App\Entity\Document;
use App\Entity\InterestedParty;
use App\Entity\ISMSObjective;
use App\Entity\Location;
use App\Entity\ProcessingActivity;
use App\Entity\Risk;
use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Entity\Training;
use App\Enum\RegisterType;
use App\Repository\IsmsRegisterEntryRepository;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Every register ISO 27001 and NIS-2 expect, in one list: the generic
 * registers ({@see RegisterType}) and the registers that are a module of
 * their own. One row per register with norm reference, maintaining persona,
 * entry count, overdue reviews and open proposals — the auditor's index and
 * the ISB's to-do in one place.
 */
final class RegisterCatalog
{
    /**
     * Module registers: key => [class, module, index route, norm reference, maintaining persona, clause order].
     */
    private const array MODULE_REGISTERS = [
        'interested_parties' => [InterestedParty::class, 'interested_parties', 'app_interested_party_index', 'ISO 27001 4.2', 'isb', '4.2'],
        'risks' => [Risk::class, 'risks', 'app_risk_index', 'ISO 27001 6.1.2 · NIS-2 Art. 21 (2) a', 'risk_manager', '6.1.2'],
        'soa' => [Control::class, 'controls', 'app_soa_index', 'ISO 27001 6.1.3 d', 'isb', '6.1.3'],
        'objectives' => [ISMSObjective::class, 'objectives', 'app_objective_index', 'ISO 27001 6.2', 'ciso', '6.2'],
        'trainings' => [Training::class, 'training', 'app_training_index', 'ISO 27001 7.2 · 7.3 · NIS-2 Art. 21 (2) g', 'isb', '7.3'],
        'documents' => [Document::class, 'documents', 'app_document_index', 'ISO 27001 7.5', 'isb', '7.5'],
        'assets' => [Asset::class, 'assets', 'app_asset_index', 'ISO 27001 A.5.9 · NIS-2 Art. 21 (2) i', 'isb', 'A.5.09'],
        'locations' => [Location::class, 'locations', 'app_location_index', 'ISO 27001 4.3 · A.7', 'isb', '4.3'],
        'business_processes' => [BusinessProcess::class, 'bcm', 'app_business_process_index', 'ISO 22301 8.2 · NIS-2 Art. 21 (2) c', 'bcm_officer', 'A.5.30'],
        'suppliers' => [Supplier::class, 'suppliers', 'app_supplier_index', 'ISO 27001 A.5.19–A.5.22 · NIS-2 Art. 21 (2) d', 'compliance_manager', 'A.5.19'],
        'processing_activities' => [ProcessingActivity::class, 'privacy', 'app_processing_activity_index', 'DSGVO Art. 30', 'dpo', 'A.5.34'],
    ];

    /** Clause order of the generic registers, for sorting next to the module registers. */
    private const array TYPE_ORDER = [
        'issue_external' => '4.1', 'issue_internal' => '4.1', 'legal_requirement' => '4.2',
        'scope_interface' => '4.3', 'role' => '5.3', 'opportunity' => '6.1.1',
        'competence' => '7.2', 'communication' => '7.4',
    ];

    public function __construct(
        private readonly IsmsRegisterEntryRepository $entries,
        private readonly RegisterProposalProvider $proposals,
        private readonly CompetenceGapAnalysis $competences,
        private readonly ModuleConfigurationService $modules,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<array{key: string, type: ?RegisterType, route: string, routeParams: array<string, string>,
     *     norm: string, persona: string, count: int, overdue: int, proposals: int, changed: ?\DateTimeImmutable}>
     */
    public function rows(Tenant $tenant): array
    {
        $stats = $this->entries->statsByType($tenant);
        $rows = [];
        foreach (RegisterType::cases() as $type) {
            $rows[] = [
                'key' => $type->value,
                'type' => $type,
                'route' => 'app_register_list',
                'routeParams' => ['type' => $type->value],
                'norm' => $type->normReference(),
                'persona' => self::personaOfRole($type->maintainerRole()),
                'count' => $stats[$type->value]['active'] ?? 0,
                'overdue' => ($stats[$type->value]['overdue'] ?? 0)
                    + ($type === RegisterType::ROLE ? count($this->competences->gaps($tenant)) : 0),
                'proposals' => $this->proposals->countFor($tenant, $type),
                'changed' => $stats[$type->value]['changed'] ?? null,
                'order' => self::TYPE_ORDER[$type->value],
            ];
        }
        foreach (self::MODULE_REGISTERS as $key => [$class, $module, $route, $norm, $persona, $order]) {
            if (!$this->modules->isModuleActive($module)) {
                continue;
            }
            $rows[] = [
                'key' => $key,
                'type' => null,
                'route' => $route,
                'routeParams' => [],
                'norm' => $norm,
                'persona' => $persona,
                'count' => $this->count($class, $tenant),
                'overdue' => 0,
                'proposals' => 0,
                'changed' => null,
                'order' => $order,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strnatcmp($a['order'], $b['order']));

        return array_map(static function (array $row): array {
            unset($row['order']);
            return $row;
        }, $rows);
    }

    private function count(string $class, Tenant $tenant): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(x.id)')
            ->from($class, 'x')
            ->where('x.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private static function personaOfRole(string $role): string
    {
        return strtolower(substr($role, 5));
    }
}
