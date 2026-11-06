<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresModule;
use App\Entity\Tenant;
use App\Entity\User;
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
    public const array LENSES = ['ciso', 'isb', 'cm', 'owner'];

    public function __construct(
        private readonly LocationMapView $mapView,
        private readonly LocationBulkService $bulkService,
        private readonly LocationBulkChoices $bulkChoices,
        private readonly LocationMapExporter $exporter,
        private readonly OnlineGeocoder $onlineGeocoder,
        private readonly LocationCoordinateUpdater $coordinateUpdater,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/location/map', name: 'app_location_map', methods: ['GET'])]
    public function map(Request $request): Response
    {
        $user = $this->getUser();
        $tenant = $user instanceof User ? $user->getTenant() : null;
        $view = $tenant instanceof Tenant
            ? $this->mapView->build($tenant, $user instanceof User ? $user : null)
            : ['sites' => [], 'unsaved' => 0, 'peers' => [], 'objectives' => [], 'levers' => [], 'money' => false];
        $sites = $view['sites'];

        $hasOwnSite = array_filter($sites, static fn (array $s): bool => $s['mine']) !== [];
        $lens = (string) $request->query->get('lens', '');
        if (!in_array($lens, self::LENSES, true) || ($lens === 'owner' && !$hasOwnSite)) {
            $lens = $this->defaultLens($hasOwnSite);
        }

        $unplaced = array_values(array_filter(
            $sites,
            static fn (array $s): bool => $s['lat'] === null && $s['area'] !== LocationGeocoder::AREA_WORLD,
        ));
        $coarse = array_values(array_filter(
            $sites,
            static fn (array $s): bool => $s['geoSource'] === LocationGeocoder::SOURCE_COUNTRY,
        ));

        return $this->render('location/map.html.twig', [
            'sites' => $sites,
            'lens' => $lens,
            'lenses' => $hasOwnSite ? self::LENSES : array_values(array_diff(self::LENSES, ['owner'])),
            'unplaced' => $unplaced,
            'coarse' => $coarse,
            'unsaved' => $view['unsaved'],
            'peers' => $view['peers'],
            'objectives' => $view['objectives'],
            'levers' => LocationMapView::levers($lens === 'owner' ? array_values(array_filter($sites, static fn (array $s): bool => $s['mine'])) : $sites),
            'money' => $view['money'],
            'bulk' => $tenant instanceof Tenant && $this->isGranted('ROLE_MANAGER') ? $this->bulkChoices->forTenant($tenant) : null,
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
    #[Route('/location/map/export.{format}', name: 'app_location_map_export', requirements: ['format' => 'xlsx|pdf'], methods: ['GET'])]
    public function export(Request $request, string $format): Response
    {
        $user = $this->getUser();
        $tenant = $user instanceof User ? $user->getTenant() : null;
        if (!$user instanceof User || !$tenant instanceof Tenant) {
            return $this->redirectToRoute('app_location_map');
        }
        $ids = array_values(array_filter(array_map('intval', $request->query->all('ids'))));
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

    private function defaultLens(bool $hasOwnSite): string
    {
        return match (true) {
            $this->isGranted('ROLE_CISO') => 'ciso',
            $this->isGranted('ROLE_COMPLIANCE_MANAGER') => 'cm',
            $this->isGranted('ROLE_MANAGER') => 'isb',
            $hasOwnSite => 'owner',
            default => 'ciso',
        };
    }
}
