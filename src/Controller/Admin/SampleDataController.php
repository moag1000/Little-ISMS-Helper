<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\SampleDataImportRepository;
use App\Service\DataImportService;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admin UI für Beispieldaten-Import/-Entfernung.
 * Jedes Sample-Dataset lässt sich nach Import gezielt wieder entfernen,
 * ohne User-Daten zu gefährden (tracked via SampleDataImport).
 */
#[IsGranted('ROLE_ADMIN')]
class SampleDataController extends AbstractController
{
    public function __construct(
        private readonly DataImportService $dataImportService,
        private readonly ModuleConfigurationService $moduleConfigurationService,
        private readonly SampleDataImportRepository $sampleImportRepository,
        private readonly TenantContext $tenantContext,
        private readonly TranslatorInterface $translator,
        private readonly \Doctrine\ORM\EntityManagerInterface $em,
    ) {
    }

    #[Route('/admin/sample-data', name: 'admin_sample_data_index')]
    public function index(): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            $this->addFlash('warning', $this->translator->trans('admin.sample_data.no_tenant', [], 'admin'));
            return $this->redirectToRoute('admin_dashboard');
        }

        $activeModules = $this->moduleConfigurationService->getActiveModules();
        $availableSamples = $this->moduleConfigurationService->getSampleData();
        $importedCounts = $this->sampleImportRepository->countsByKey($tenant);
        // Defensive: PHP coerces numeric string keys to int when storing in
        // arrays, but if the DB driver returns sample_key as a non-numeric
        // string for any reason the lookup `$importedCounts[$key]` (with
        // int $key from foreach) misses. Build a string-keyed mirror so both
        // forms work.
        $countsByStringKey = [];
        foreach ($importedCounts as $k => $v) {
            $countsByStringKey[(string) $k] = (int) $v;
        }

        // Command-basierte Samples (TISAX, DORA) erzeugen keine Tracking-Rows.
        // Status-Erkennung: Framework-Existenz im DB prüfen. Map command →
        // ComplianceFramework-Code.
        $commandFrameworkMap = [
            'app:load-tisax-requirements' => 'TISAX',
            'app:load-dora-requirements'  => 'DORA',
        ];
        $frameworkRepo = $this->em->getRepository(\App\Entity\ComplianceFramework::class);
        $reqRepo       = $this->em->getRepository(\App\Entity\ComplianceRequirement::class);

        // Ein normalisiertes Array pro Sample (Key, name, description, required-modules,
        // bereits-importiert-Flag, Entry-Count, Remove-fähig).
        $samples = [];
        foreach ($availableSamples as $key => $data) {
            $requiredModules = $data['required_modules'] ?? [];
            $modulesOk = true;
            foreach ($requiredModules as $m) {
                if (!in_array($m, $activeModules, true)) {
                    $modulesOk = false;
                    break;
                }
            }

            $rawCount = $importedCounts[$key] ?? $countsByStringKey[(string) $key] ?? 0;
            $imported = $rawCount > 0;

            // Command-Sample? Status statt aus Tracking aus Framework-Existenz lesen.
            if (isset($data['command']) && isset($commandFrameworkMap[$data['command']])) {
                $framework = $frameworkRepo->findOneBy(['code' => $commandFrameworkMap[$data['command']]]);
                if ($framework !== null) {
                    $reqCount = $reqRepo->count(['framework' => $framework]);
                    $rawCount = $reqCount;
                    $imported = $reqCount > 0;
                }
            }

            // Removable: file-Samples via Tracking-Rows, Command-Samples
            // (TISAX/DORA) via Framework-Cascade-Delete (siehe remove()).
            $isCommandWithMappedFramework = isset($data['command'])
                && isset($commandFrameworkMap[$data['command']]);

            $samples[$key] = [
                'key' => (string) $key,
                'name' => $data['name'] ?? (string) $key,
                'description' => $data['description'] ?? '',
                'required_modules' => $requiredModules,
                'modules_ok' => $modulesOk,
                'count' => $rawCount,
                'imported' => $imported,
                'removable' => isset($data['file']) || ($isCommandWithMappedFramework && $imported),
            ];
        }

        return $this->render('admin/sample_data/index.html.twig', [
            'samples' => $samples,
            'tenant' => $tenant,
            'totalImported' => array_sum($importedCounts),
        ]);
    }

    #[Route('/admin/sample-data/import', name: 'admin_sample_data_import', methods: ['POST'])]
    #[IsCsrfTokenValid('sample_data_import', tokenKey: '_token')]
    public function import(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            $this->addFlash('error', $this->translator->trans('admin.sample_data.no_tenant', [], 'admin'));
            return $this->redirectToRoute('admin_sample_data_index');
        }

        $selectedSamples = $request->request->all('samples') ?: [];
        if (empty($selectedSamples)) {
            $this->addFlash('warning', $this->translator->trans('admin.sample_data.nothing_selected', [], 'admin'));
            return $this->redirectToRoute('admin_sample_data_index');
        }

        $activeModules = $this->moduleConfigurationService->getActiveModules();
        /** @var User|null $user */
        $user = $this->getUser();

        $result = $this->dataImportService->importSampleData($selectedSamples, $activeModules, $tenant, $user);

        foreach ($result['results'] as $entry) {
            $severity = match ($entry['status']) {
                'success' => 'success',
                'skipped' => 'warning',
                default => 'error',
            };
            $this->addFlash($severity, sprintf('%s: %s', $entry['name'], $entry['message']));
        }

        return $this->redirectToRoute('admin_sample_data_index');
    }

    #[Route('/admin/sample-data/remove/{sampleKey}', name: 'admin_sample_data_remove', methods: ['POST'])]
    public function remove(Request $request, string $sampleKey): Response
    {
        if (!$this->isCsrfTokenValid('sample_data_remove_' . $sampleKey, $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error'));
            return $this->redirectToRoute('admin_sample_data_index');
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            $this->addFlash('error', $this->translator->trans('admin.sample_data.no_tenant', [], 'admin'));
            return $this->redirectToRoute('admin_sample_data_index');
        }

        // Command-Samples (TISAX/DORA): Framework cascade-deletet alle
        // ComplianceRequirements (entity has cascade=remove on the OneToMany).
        $availableSamples = $this->moduleConfigurationService->getSampleData();
        $sampleConfig = $availableSamples[$sampleKey] ?? $availableSamples[(int) $sampleKey] ?? null;
        $commandFrameworkMap = [
            'app:load-tisax-requirements' => 'TISAX',
            'app:load-dora-requirements'  => 'DORA',
        ];
        if ($sampleConfig !== null && isset($sampleConfig['command'])
            && isset($commandFrameworkMap[$sampleConfig['command']])) {
            $code = $commandFrameworkMap[$sampleConfig['command']];
            $framework = $this->em->getRepository(\App\Entity\ComplianceFramework::class)
                ->findOneBy(['code' => $code]);
            if ($framework === null) {
                $this->addFlash('warning', sprintf('Framework %s nicht gefunden — nichts zu entfernen.', $code));
                return $this->redirectToRoute('admin_sample_data_index');
            }
            $reqCount = $this->em->getRepository(\App\Entity\ComplianceRequirement::class)
                ->count(['framework' => $framework]);
            try {
                $this->em->remove($framework);
                $this->em->flush();
                $this->addFlash('success', sprintf(
                    '%s entfernt: Framework + %d Anforderungen.',
                    $code, $reqCount
                ));
            } catch (\Throwable $e) {
                $this->addFlash('error', sprintf(
                    'Konnte %s nicht entfernen: %s', $code, $e->getMessage()
                ));
            }
            return $this->redirectToRoute('admin_sample_data_index');
        }

        $result = $this->dataImportService->removeSampleData($sampleKey, $tenant);

        if ($result['success']) {
            $this->addFlash('success', $this->translator->trans(
                'admin.sample_data.removed',
                ['%count%' => $result['removed'], '%key%' => $sampleKey],
                'admin',
            ));
        } else {
            $this->addFlash('warning', $this->translator->trans(
                'admin.sample_data.remove_partial',
                ['%count%' => $result['removed'], '%errors%' => count($result['errors'])],
                'admin',
            ));
            foreach (array_slice($result['errors'], 0, 5) as $err) {
                $this->addFlash('error', $err);
            }
        }

        return $this->redirectToRoute('admin_sample_data_index');
    }
}
