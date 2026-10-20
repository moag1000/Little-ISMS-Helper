<?php

declare(strict_types=1);

namespace App\Service\Setup;

use App\Entity\ComplianceFramework;
use App\Entity\Control;
use App\Entity\Document;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ControlRepository;
use App\Repository\DocumentRepository;
use App\Service\AnnexAControlsBootstrapService;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Applies an industry preset (`fixtures/library/presets/*.yaml`) to a tenant:
 * activates modules and frameworks and creates the initial controls and
 * documents. Shared by {@see \App\Command\LoadIndustryPresetCommand} and
 * {@see \App\Controller\Admin\IndustryPresetController}.
 *
 * Existing records are never changed: a control (tenant + controlId) or a
 * document (tenant + name) that already exists is skipped.
 *
 * `initial_documents` is the preset's list of documents the organisation
 * needs (policies, procedures, BC plans). Each entry becomes a draft
 * placeholder Document in the register — the same content-only "virtual"
 * Document shape the Policy-Wizard uses (synthetic filename, `virtual:`
 * file path, body text instead of an uploaded file). The owner replaces the
 * placeholder body or uploads the final file and moves it through the
 * document_lifecycle as usual.
 */
class IndustryPresetApplier
{
    public const DOCUMENT_ENTITY_TYPE = 'IndustryPreset';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ModuleConfigurationService $moduleConfiguration,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ControlRepository $controlRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly AnnexAControlsBootstrapService $annexAControls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Controls and documents are only created when a tenant is given; modules
     * and frameworks are applied either way. With a tenant the modules are
     * activated for that tenant — not for the tenant in context, which differs
     * when a super-admin applies a preset to another organisation. Without one
     * the module service's default scope applies (context tenant or, in a
     * console run without tenant, the instance default).
     *
     * @param array<string, mixed> $config Parsed preset YAML
     * @return array{modules_activated: list<string>, frameworks_activated: int, controls_created: int, documents_created: int}
     */
    public function apply(string $presetId, array $config, ?Tenant $tenant): array
    {
        $result = [
            'modules_activated' => $this->activateModules($config, $tenant),
            'frameworks_activated' => $this->activateFrameworks($config),
            'controls_created' => 0,
            'documents_created' => 0,
        ];

        if ($tenant instanceof Tenant) {
            $result['controls_created'] = $this->createControls($config, $tenant);
            $result['documents_created'] = $this->createDocuments($presetId, $config, $tenant);
        }

        $this->entityManager->flush();

        return $result;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<string>
     */
    private function activateModules(array $config, ?Tenant $tenant): array
    {
        $presetModules = array_map('strval', $config['modules'] ?? []);
        if ($presetModules === []) {
            return [];
        }

        $current = $tenant instanceof Tenant
            ? $this->moduleConfiguration->getActiveModulesForTenant($tenant)
            : $this->moduleConfiguration->getActiveModules();
        $merged = array_values(array_unique(array_merge($current, $presetModules)));
        if (count($merged) === count($current)) {
            return [];
        }

        if ($tenant instanceof Tenant) {
            $this->moduleConfiguration->saveActiveModulesForTenant($tenant, $merged);
        } else {
            $this->moduleConfiguration->saveActiveModules($merged);
        }

        return array_values(array_diff($merged, $current));
    }

    /**
     * Flips active=true on existing frameworks or creates a skeleton. Mapping
     * and requirement import remain a separate step (mapping library, wizard).
     *
     * @param array<string, mixed> $config
     */
    private function activateFrameworks(array $config): int
    {
        $activated = 0;
        foreach ($config['frameworks'] ?? [] as $framework) {
            $code = (string) ($framework['code'] ?? '');
            if (!($framework['activate'] ?? false) || $code === '') {
                continue;
            }

            $existing = $this->frameworkRepository->findOneBy(['code' => $code]);
            if ($existing instanceof ComplianceFramework) {
                if ($existing->isActive() !== true) {
                    $existing->setActive(true);
                    $activated++;
                }
                continue;
            }

            $fw = new ComplianceFramework();
            $fw->setCode($code);
            $fw->setName($code);
            $fw->setVersion('latest');
            $fw->setApplicableIndustry((string) ($config['name'] ?? 'all'));
            $fw->setRegulatoryBody('—');
            $fw->setMandatory(($framework['priority'] ?? null) === 'primary');
            $fw->setActive(true);
            $this->entityManager->persist($fw);
            $activated++;
        }

        return $activated;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createControls(array $config, Tenant $tenant): int
    {
        $created = 0;
        foreach ($config['initial_controls'] ?? [] as $cdef) {
            $controlId = (string) ($cdef['identifier'] ?? '');
            if ($controlId === '') {
                continue;
            }
            $existing = $this->controlRepository->findOneBy(['tenant' => $tenant, 'controlId' => $controlId]);
            if ($existing instanceof Control) {
                continue;
            }

            // Annex-A controls take their canonical text from the catalog;
            // other frameworks (e.g. BSI modules) fall back to the preset data.
            $catalog = $this->annexAControls->getCatalogEntry($controlId);
            $name = (string) ($cdef['name'] ?? $catalog['name'] ?? $controlId);

            $control = new Control();
            $control->setTenant($tenant);
            $control->setControlId($controlId);
            $control->setName($name);
            $control->setDescription($catalog['description'] ?? $name);
            $control->setCategory($catalog['category'] ?? (string) ($cdef['framework'] ?? 'Other'));
            $control->setImplementationStatus((string) ($cdef['implementation_status'] ?? 'not_started'));
            $control->setApplicable(true);
            $this->entityManager->persist($control);
            $created++;
        }

        return $created;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createDocuments(string $presetId, array $config, Tenant $tenant): int
    {
        $locale = (string) ($config['locale'] ?? 'en');
        $presetName = (string) ($config['name'] ?? $presetId);
        $created = 0;

        foreach ($config['initial_documents'] ?? [] as $ddef) {
            $title = trim((string) ($ddef['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $existing = $this->documentRepository->findOneBy(['tenant' => $tenant, 'originalFilename' => $title]);
            if ($existing instanceof Document) {
                continue;
            }

            $params = ['%title%' => $title, '%preset%' => $presetName];
            $body = $this->translator->trans('industry_preset.document_placeholder.body', $params, 'admin', $locale);
            $filename = sprintf('preset-%s-%s.md', $presetId, substr(hash('sha256', $title), 0, 12));

            // No status from preset data: a new document always starts at the
            // document_lifecycle initial marking (`draft`, entity default) and
            // moves on only through LifecycleService transitions.
            $doc = new Document();
            $doc->setTenant($tenant);
            $doc->setOriginalFilename($title);
            $doc->setFilename($filename);
            $doc->setMimeType('text/markdown');
            $doc->setFileSize(strlen($body));
            $doc->setFilePath('virtual:industry-preset/' . $filename);
            $doc->setCategory((string) ($ddef['type'] ?? 'policy'));
            $doc->setDescription(
                $this->translator->trans('industry_preset.document_placeholder.description', $params, 'admin', $locale),
            );
            $doc->setPolicyBody($body);
            $doc->setEntityType(self::DOCUMENT_ENTITY_TYPE);
            $doc->setSubstitutionVariables(['_title' => $title, '_preset' => $presetId]);
            $this->entityManager->persist($doc);
            $created++;
        }

        return $created;
    }
}
