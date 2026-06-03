<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Global;

use App\AlvaHint\AbstractGlobalAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ComplianceFrameworkRepository;
use App\Service\Import\Mapper\TisaxRequirementMapper;
use Symfony\Component\Yaml\Yaml;

/**
 * Tier-3 info hint: a newer library YAML version exists for an imported framework.
 *
 * Fires when a tenant has imported framework X (BSI-GRUNDSCHUTZ-2024 or TISAX)
 * but the local YAML fixture has a higher version than the DB record. This signals
 * that the admin should re-run the library import to pick up new Bausteine / controls.
 *
 * Trigger  : admin_library_index (and admin_hub_index as fallback)
 * Module   : compliance
 * Role     : ROLE_ADMIN
 * Tier     : 3 (info, dismissible)
 */
class LibraryUpdatedRule extends AbstractGlobalAlvaHintRule
{
    /**
     * Map of framework codes to their local YAML fixture paths (relative to project root).
     * Key = canonical framework code (post-consolidation).
     *
     * @var array<string, string>
     */
    private const array FRAMEWORK_YAML_PATHS = [
        'BSI-GRUNDSCHUTZ-2024' => 'fixtures/library/frameworks/bsi-it-grundschutz-2024.yaml',
        // 'TISAX' is the canonical code (§4.1 consolidation, 2026-06-01).
        // The YAML skeleton still contains code='TISAX-VDA-ISA-6' until the fixture
        // is updated; VdaIsaImporter normalises it to 'TISAX' at import time.
        TisaxRequirementMapper::FRAMEWORK_CODE => 'fixtures/library/frameworks/vda-isa-tisax-v6.yaml',
    ];

    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly string $projectDir,
    ) {
    }

    public function key(): string
    {
        return 'global.library_updated';
    }

    public function priorityTier(): int
    {
        return 3;
    }

    public function requiredModules(): array
    {
        return ['compliance'];
    }

    public function appliesToPages(): array
    {
        return ['admin_library_index', 'admin_hub_index'];
    }

    public function evaluate(Tenant $tenant, ?User $user): ?AlvaHint
    {
        foreach (self::FRAMEWORK_YAML_PATHS as $code => $relativePath) {
            $yamlPath = $this->projectDir . '/' . $relativePath;

            if (!file_exists($yamlPath)) {
                continue;
            }

            /** @var array<string, mixed> $data */
            $data = Yaml::parseFile($yamlPath);
            $yamlVersion = (string) ($data['metadata']['version'] ?? '');

            if ($yamlVersion === '') {
                continue;
            }

            $framework = $this->frameworkRepository->findOneBy(['code' => $code]);

            // Deprecation-window fallback (§4.1, 2026-06-01): if the canonical code
            // is not yet in DB (tenant has not run the P3 consolidation migration),
            // try the legacy alias codes so the hint still fires and prompts re-import.
            if ($framework === null) {
                $reverseLegacyCodes = array_keys(
                    array_filter(
                        TisaxRequirementMapper::LEGACY_CODE_ALIASES,
                        static fn (string $canonical): bool => $canonical === $code,
                    ),
                );
                foreach ($reverseLegacyCodes as $legacyCode) {
                    $framework = $this->frameworkRepository->findOneBy(['code' => $legacyCode]);
                    if ($framework !== null) {
                        break;
                    }
                }
            }

            if ($framework === null) {
                // Not yet imported — different hint type; not our concern here.
                continue;
            }

            $dbVersion = $framework->getVersion() ?? '';

            if ($dbVersion === $yamlVersion) {
                continue;
            }

            // Version mismatch detected — newer YAML available
            return new AlvaHint(
                key: $this->key(),
                titleTranslationKey: 'global.library_updated.title',
                bodyTranslationKey: 'global.library_updated.body',
                bodyTranslationParams: [
                    '%code%' => $code,
                    '%yaml_version%' => $yamlVersion,
                    '%db_version%' => $dbVersion,
                ],
                translationDomain: 'alva',
                variant: 'info',
                priorityTier: 3,
                dismissible: true,
                entityType: 'Tenant',
                entityId: $tenant->getId() ?? 0,
                actionLabelTranslationKey: 'global.library_updated.action',
                actionRoute: 'admin_library_index',
                actionRouteParams: [],
                actionMethod: 'GET',
                requiredRoles: ['ROLE_ADMIN'],
                mood: 'thinking',
                version: 1,
            );
        }

        return null;
    }
}
