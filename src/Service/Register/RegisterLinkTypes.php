<?php

declare(strict_types=1);

namespace App\Service\Register;

use App\Entity\Asset;
use App\Exception\InvalidArgument\InvalidArgumentException;
use App\Entity\BusinessProcess;
use App\Entity\ComplianceFramework;
use App\Entity\Document;
use App\Entity\InterestedParty;
use App\Entity\IsmsRegisterEntry;
use App\Entity\ISMSObjective;
use App\Entity\Location;
use App\Entity\Risk;
use App\Entity\Supplier;
use App\Entity\Training;
use App\Enum\RegisterType;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The records a register entry can link to. One table of link kinds instead
 * of one join table per pair: the entry stores `field` + id, this class knows
 * class, module, detail route and label of each kind.
 */
final class RegisterLinkTypes
{
    /**
     * field => [class, module (null = always), show route, entry types (entry links only)].
     */
    private const array KINDS = [
        'risk' => [Risk::class, 'risks', 'app_risk_show', null],
        'interested_party' => [InterestedParty::class, 'interested_parties', 'app_interested_party_show', null],
        'objective' => [ISMSObjective::class, 'objectives', 'app_objective_show', null],
        'compliance_framework' => [ComplianceFramework::class, 'compliance', 'app_compliance_framework_show', null],
        'supplier' => [Supplier::class, 'suppliers', 'app_supplier_show', null],
        'document' => [Document::class, 'documents', 'app_document_show', null],
        'training' => [Training::class, 'training', 'app_training_show', null],
        'asset' => [Asset::class, 'assets', 'app_asset_show', null],
        'business_process' => [BusinessProcess::class, 'bcm', 'app_business_process_show', null],
        'location' => [Location::class, 'locations', 'app_location_show', null],
        'issue_entry' => [IsmsRegisterEntry::class, null, 'app_register_entry_show', [RegisterType::ISSUE_EXTERNAL, RegisterType::ISSUE_INTERNAL]],
        'competence_entry' => [IsmsRegisterEntry::class, null, 'app_register_entry_show', [RegisterType::COMPETENCE]],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ModuleConfigurationService $modules,
    ) {
    }

    /** @return class-string */
    public static function classOf(string $field): string
    {
        // @intentional-assertion: link fields come from RegisterType, an unknown one is a programming error
        return self::KINDS[$field][0] ?? throw new InvalidArgumentException('Unknown register link field ' . $field);
    }

    public function routeOf(string $field): string
    {
        return self::KINDS[$field][2];
    }

    /** @return list<RegisterType>|null entry types an entry link may point to */
    public function entryTypesOf(string $field): ?array
    {
        return self::KINDS[$field][3];
    }

    public function isAvailable(string $field): bool
    {
        $module = self::KINDS[$field][1] ?? null;
        return $module === null || $this->modules->isModuleActive($module);
    }

    /**
     * Link fields of a register whose module is active.
     *
     * @return list<string>
     */
    public function fieldsFor(RegisterType $type): array
    {
        return array_values(array_filter($type->linkFields(), $this->isAvailable(...)));
    }

    public static function labelOf(object $record): string
    {
        return match (true) {
            $record instanceof Risk, $record instanceof ISMSObjective, $record instanceof Training,
            $record instanceof IsmsRegisterEntry => (string) $record->getTitle(),
            $record instanceof Document => (string) $record->getOriginalFilename(),
            $record instanceof ComplianceFramework => trim($record->getCode() . ' · ' . $record->getName(), ' ·'),
            $record instanceof InterestedParty, $record instanceof Supplier, $record instanceof Asset,
            $record instanceof BusinessProcess, $record instanceof Location => (string) $record->getName(),
            default => '',
        };
    }

    /**
     * Records behind the stored ids (the tenant filter keeps foreign rows out).
     *
     * @param list<int> $ids
     * @return list<object>
     */
    public function load(string $field, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->entityManager->getRepository(self::classOf($field))->findBy(['id' => $ids]);
    }

    /**
     * Links of an entry, grouped for display.
     *
     * @return list<array{field: string, route: string, items: list<array{id: int, label: string}>}>
     */
    public function groupsOf(IsmsRegisterEntry $entry): array
    {
        $groups = [];
        foreach ($this->fieldsFor($entry->getType()) as $field) {
            $records = $this->load($field, $entry->linkedIds($field));
            $groups[] = [
                'field' => $field,
                'route' => $this->routeOf($field),
                'items' => array_map(
                    static fn (object $r): array => ['id' => (int) $r->getId(), 'label' => self::labelOf($r)],
                    $records,
                ),
            ];
        }

        return $groups;
    }

    /** Link field that points to entries of this register type (reverse view), if any. */
    public static function entryFieldFor(RegisterType $type): ?string
    {
        foreach (self::KINDS as $field => [, , , $types]) {
            if ($types !== null && in_array($type, $types, true)) {
                return $field;
            }
        }

        return null;
    }

    /** Link field of a record class (reverse view on the record's page). */
    public static function fieldOfClass(string $class): ?string
    {
        $recordClasses = array_map(static fn (array $kind): ?string => $kind[3] === null ? $kind[0] : null, self::KINDS);
        $field = array_search($class, $recordClasses, true);

        return is_string($field) ? $field : null;
    }
}
