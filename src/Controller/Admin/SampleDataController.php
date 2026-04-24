<?php

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

            $samples[$key] = [
                'key' => (string) $key,
                'name' => $data['name'] ?? (string) $key,
                'description' => $data['description'] ?? '',
                'required_modules' => $requiredModules,
                'modules_ok' => $modulesOk,
                'count' => $importedCounts[$key] ?? 0,
                'imported' => ($importedCounts[$key] ?? 0) > 0,
                'removable' => isset($data['file']),  // Commands können wir (noch) nicht tracken
            ];
        }

        return $this->render('admin/sample_data/index.html.twig', [
            'samples' => $samples,
            'tenant' => $tenant,
            'totalImported' => array_sum($importedCounts),
        ]);
    }

    #[Route('/admin/sample-data/import', name: 'admin_sample_data_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('sample_data_import', $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error'));
            return $this->redirectToRoute('admin_sample_data_index');
        }

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
