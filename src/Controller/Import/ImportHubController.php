<?php

declare(strict_types=1);

namespace App\Controller\Import;

use App\Service\Import\Schema\ImportSchemaRegistry;
use App\Service\ModuleConfigurationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Central bulk-import hub — every importable entity in one place, grouped by
 * area. Each tile opens the shared import wizard for that entity type.
 *
 * Module-aware: an entity whose schema declares a module is only listed when
 * that module is active for the tenant, so users never see imports for features
 * they don't have.
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/data-import')]
#[IsGranted('ROLE_MANAGER')]
final class ImportHubController extends AbstractController
{
    /**
     * Area → ordered list of entity-type URL slugs. Slugs map 1:1 to the
     * /import/{entityType} wizard and to the import.entity.<slug> i18n key.
     */
    private const GROUPS = [
        'governance' => ['isms_context', 'isms_objective', 'interested_party', 'management_review', 'internal_audit', 'audit_finding', 'corrective_action', 'change_request', 'control'],
        'assets_risk' => ['asset', 'risk', 'vulnerability', 'patch', 'threat_intelligence'],
        'suppliers_people' => ['supplier', 'person', 'location', 'training'],
        'continuity' => ['business_process', 'business_continuity_plan', 'incident', 'data_breach'],
        'privacy' => ['processing_activity', 'data_subject_request', 'consent', 'data_protection_impact_assessment'],
    ];

    public function __construct(
        private readonly ImportSchemaRegistry $schemaRegistry,
        private readonly ModuleConfigurationService $moduleConfiguration,
    ) {
    }

    #[Route('', name: 'app_import_hub', methods: ['GET'])]
    public function index(): Response
    {
        $groups = [];
        foreach (self::GROUPS as $area => $slugs) {
            $entities = [];
            foreach ($slugs as $slug) {
                $module = $this->schemaRegistry->getSchemaFor($this->toPascalCase($slug))?->module;
                if ($module !== null && !$this->moduleConfiguration->isModuleActive($module)) {
                    continue; // module inactive → not offered
                }
                $entities[] = $slug;
            }
            if ($entities !== []) {
                $groups[$area] = $entities;
            }
        }

        return $this->render('import_hub/index.html.twig', [
            'groups' => $groups,
        ]);
    }

    private function toPascalCase(string $slug): string
    {
        $pascal = implode('', array_map('ucfirst', explode('_', $slug)));

        return preg_replace('/^Isms/', 'ISMS', $pascal) ?? $pascal;
    }
}
