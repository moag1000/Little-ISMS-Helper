<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresModule;
use App\Entity\Tenant;
use App\Entity\User;
use App\Privacy\GdprTransferZones;
use App\Service\Location\LocationEventView;
use App\Service\Location\LocationGroupView;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use App\Service\Location\LocationBulkChoices;
use App\Service\Location\LocationBulkService;
use App\Service\Location\LocationCoordinateUpdater;
use App\Service\Location\LocationMapExporter;
use App\Service\Location\LocationMapView;
use App\Service\Location\LocationGeocoder;
use App\Service\Location\LocationPostureService;
use App\Service\Location\OnlineGeocoder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Location map ("Standort-Lagebild"): one pin per site, sized by protection
 * need, shaped and coloured by current risk or open gaps. ISO 27001 A.7.
 */
#[RequiresModule('locations')]
#[IsGranted('ROLE_USER')]
final class LocationMapController extends AbstractController
{
    public const array LENSES = ['ciso', 'isb', 'cm', 'bcm', 'dpo', 'auditor', 'owner', 'group'];
    private const string LENS_SESSION_KEY = 'location_map_lens';

    public function __construct(
        private readonly LocationMapView $mapView,
        private readonly LocationBulkService $bulkService,
        private readonly LocationBulkChoices $bulkChoices,
        private readonly LocationMapExporter $exporter,
        private readonly OnlineGeocoder $onlineGeocoder,
        private readonly LocationCoordinateUpdater $coordinateUpdater,
        private readonly LocationGroupView $groupView,
        private readonly LocationEventView $eventView,
        private readonly ModuleConfigurationService $modules,
        private readonly TenantContext $tenantContext,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/location/map', name: 'app_location_map', methods: ['GET'])]
    public function map(Request $request): Response
    {
        $user = $this->getUser();
        $tenant = $user instanceof User ? $user->getTenant() : null;
        $view = $tenant instanceof Tenant
            ? $this->mapView->build($tenant, $user)
            : ['sites' => [], 'unsaved' => 0, 'peers' => [], 'objectives' => [], 'levers' => [], 'money' => false,
                'peerMode' => 'off', 'layers' => ['risk', 'gaps'], 'suppliers' => null, 'sample' => ['size' => 0, 'ids' => []]];
        $sites = $view['sites'];

        $hasOwnSite = array_filter($sites, static fn (array $s): bool => $s['mine']) !== [];
        $lenses = $this->availableLenses($hasOwnSite, $tenant);
        // The chosen lens is kept for the session; without a choice the role decides.
        $session = $request->getSession();
        $lens = (string) $request->query->get('lens', (string) $session->get(self::LENS_SESSION_KEY, ''));
        if (!in_array($lens, $lenses, true)) {
            $lens = $this->defaultLens($lenses);
        } elseif ($request->query->has('lens')) {
            $session->set(self::LENS_SESSION_KEY, $lens);
        }

        // Group view: every organisation of the holding's subtree, read-only.
        $group = $lens === 'group' ? $this->groupView->build($this->tenantContext->getAccessibleTenants()) : null;
        $mapSites = $group['sites'] ?? $sites;

        $unplaced = array_values(array_filter(
            $sites,
            static fn (array $s): bool => $s['lat'] === null && $s['area'] !== LocationGeocoder::AREA_WORLD,
        ));
        $coarse = array_values(array_filter(
            $sites,
            static fn (array $s): bool => $s['geoSource'] === LocationGeocoder::SOURCE_COUNTRY,
        ));

        // Event mode (BCM lens): which sites, assets, processes, plans and
        // crisis teams a country-wide event hits.
        $eventCountries = $tenant instanceof Tenant && $lens === 'bcm'
            ? $this->eventView->countries($sites) : [];
        $eventCode = strtoupper((string) $request->query->get('event', ''));
        $event = $tenant instanceof Tenant && isset($eventCountries[$eventCode])
            ? $this->eventView->forCountry($tenant, $sites, $eventCode) : null;

        return $this->render('location/map.html.twig', [
            'sites' => $mapSites,
            'lens' => $lens,
            'lenses' => $lenses,
            'layers' => $group !== null ? ['risk', 'gaps'] : $view['layers'],
            'group' => $group,
            'unplaced' => $unplaced,
            'coarse' => $coarse,
            'unsaved' => $view['unsaved'],
            'peers' => $view['peers'],
            'peer_mode' => $view['peerMode'],
            'objectives' => $view['objectives'],
            'levers' => $group !== null ? [] : LocationMapView::levers($lens === 'owner' ? array_values(array_filter($sites, static fn (array $s): bool => $s['mine'])) : $sites),
            'money' => $view['money'],
            'suppliers' => $group !== null ? null : $view['suppliers'],
            'sample' => $view['sample'],
            'zones' => GdprTransferZones::all(),
            'zones_as_of' => GdprTransferZones::asOf(),
            'event_countries' => $eventCountries,
            'event' => $event,
            'bulk' => $group === null && $tenant instanceof Tenant && $this->isGranted('ROLE_MANAGER') ? $this->bulkChoices->forTenant($tenant) : null,
            'online_enabled' => $tenant instanceof Tenant && $this->onlineGeocoder->isEnabled($tenant),
            'check_norms' => LocationPostureService::CHECK_NORM,
        ]);
    }

    /**
     * Close the same gap at several sites in one step: owner, deputy or peer
     * group (tag) for the selected sites.
     */
    #[Route('/location/map/bulk', name: 'app_location_map_bulk', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    #[IsCsrfTokenValid('location_map_bulk')]
    public function bulk(Request $request): Response
    {
        $user = $this->getUser();
        $tenant = $user instanceof User ? $user->getTenant() : null;
        [$action, $target] = explode(':', (string) $request->request->get('target'), 2) + ['', '0'];
        $target = (int) $target;
        $ids = array_values(array_filter(array_map('intval', $request->request->all('ids'))));
        if (!$user instanceof User || !$tenant instanceof Tenant || $ids === [] || $target === 0) {
            $this->addFlash('warning', $this->translator->trans('location.map.bulk.flash.empty', [], 'locations'));
            return $this->redirectToRoute('app_location_map', ['lens' => $request->request->get('lens')]);
        }

        $changed = $this->bulkService->apply($tenant, $user, $action, $ids, $target);
        $this->addFlash('success', $this->translator->trans('location.map.bulk.flash.done', ['%count%' => $changed], 'locations'));
        return $this->redirectToRoute('app_location_map', ['lens' => $request->request->get('lens')]);
    }

    /** Situation report of the selected (or all) sites as Excel or PDF one-pager. */
    // POST from the selection form, so the token stays out of URLs and logs.
    #[Route('/location/map/export.{format}', name: 'app_location_map_export', requirements: ['format' => 'xlsx|pdf'], methods: ['POST'])]
    #[IsCsrfTokenValid('location_map_bulk')]
    public function export(Request $request, string $format): Response
    {
        $user = $this->getUser();
        $tenant = $user instanceof User ? $user->getTenant() : null;
        if (!$user instanceof User || !$tenant instanceof Tenant) {
            return $this->redirectToRoute('app_location_map');
        }
        $ids = array_values(array_filter(array_map('intval', $request->request->all('ids'))));
        $request->getSession()->save();

        return $format === 'pdf'
            ? $this->exporter->pdf($tenant, $user, $ids)
            : $this->exporter->excel($tenant, $user, $ids);
    }

    /**
     * Store coordinates for every site from the offline tables and, when an
     * admin enabled it, refine coarse ones online (postal code, city, country
     * only).
     */
    #[Route('/location/map/geocode', name: 'app_location_map_geocode', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    #[IsCsrfTokenValid('location_map_geocode')]
    public function geocode(): Response
    {
        $user = $this->getUser();
        $tenant = $user instanceof User ? $user->getTenant() : null;
        if (!$tenant instanceof Tenant) {
            return $this->redirectToRoute('app_location_map');
        }

        $placed = $this->coordinateUpdater->storeForTenant($tenant);
        $this->addFlash('success', $this->translator->trans('location.map.flash.geocoded', ['%count%' => $placed], 'locations'));
        return $this->redirectToRoute('app_location_map');
    }

    /**
     * Lenses this user may pick: each persona lens behind its module, the
     * auditor lens for auditors, the owner lens with an own site, the group
     * view for holding roles with at least one subsidiary.
     *
     * @return list<string>
     */
    private function availableLenses(bool $hasOwnSite, ?Tenant $tenant): array
    {
        $available = [
            'ciso' => true,
            'isb' => true,
            'cm' => true,
            'bcm' => $this->modules->isModuleActive('bcm'),
            'dpo' => $this->modules->isModuleActive('privacy'),
            'auditor' => $this->modules->isModuleActive('audits') && $this->isGranted('ROLE_AUDITOR'),
            'owner' => $hasOwnSite,
            'group' => $tenant instanceof Tenant
                && ($this->isGranted('ROLE_GROUP_CISO') || $this->isGranted('ROLE_KONZERN_AUDITOR'))
                && $tenant->getSubsidiaries()->count() > 0,
        ];

        return array_values(array_filter(self::LENSES, static fn (string $l): bool => $available[$l]));
    }

    /** @param list<string> $lenses */
    private function defaultLens(array $lenses): string
    {
        $byRole = [
            'ROLE_CISO' => 'ciso',
            'ROLE_DPO' => 'dpo',
            'ROLE_BCM_OFFICER' => 'bcm',
            'ROLE_COMPLIANCE_MANAGER' => 'cm',
            'ROLE_MANAGER' => 'isb',
            'ROLE_AUDITOR' => 'auditor',
        ];
        foreach ($byRole as $role => $lens) {
            if (in_array($lens, $lenses, true) && $this->isGranted($role)) {
                return $lens;
            }
        }

        return in_array('owner', $lenses, true) ? 'owner' : 'ciso';
    }
}
