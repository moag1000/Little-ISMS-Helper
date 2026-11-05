<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresModule;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\Location\LocationCoordinateUpdater;
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
        private readonly LocationPostureService $postureService,
        private readonly LocationGeocoder $geocoder,
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
        $sites = $tenant instanceof Tenant ? $this->postureService->forTenant($tenant, $user) : [];

        // Old rows have no coordinates yet: place them for display from the
        // offline tables. Stored on the next save or via "Koordinaten ermitteln".
        $unsaved = 0;
        foreach ($sites as &$site) {
            if ($site['lat'] === null && $site['geoSource'] === null) {
                $point = $site['type'] === 'region'
                    ? $this->geocoder->locateArea($site['country'])
                    : $this->geocoder->locate($site['postalCode'], $site['city'], $site['country']);
                if ($point !== null) {
                    [$site['lat'], $site['lng'], $site['geoSource']] = [$point->latitude, $point->longitude, $point->source];
                    ++$unsaved;
                }
            }
        }
        unset($site);

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
            'unsaved' => $unsaved,
            'online_enabled' => $tenant instanceof Tenant && $this->onlineGeocoder->isEnabled($tenant),
            'check_norms' => LocationPostureService::CHECK_NORM,
        ]);
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
