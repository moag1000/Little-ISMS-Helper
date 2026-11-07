<?php

declare(strict_types=1);

namespace App\Controller\Admin\SystemSettings;

use App\Entity\Tenant;
use App\Attribute\RequiresModule;
use App\Service\Location\LocationCoordinateUpdater;
use App\Service\Location\LocationMapSettings;
use App\Service\Location\LocationPostureService;
use App\Service\Location\OnlineGeocoder;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Location map settings (Tenant.settings['location_map']): peer comparison,
 * position precision, active gap checks and opt-in online geocoding.
 *
 * Off by default. When on, only postal code, city and country code of a
 * location are sent to the configured Nominatim-compatible endpoint, and only
 * for locations the offline tables place at country level.
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/admin/settings/location-map')]
#[RequiresModule('locations')]
#[IsGranted('ROLE_ADMIN')]
class LocationMapSettingsController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly LocationCoordinateUpdater $coordinateUpdater,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'admin_settings_location_map', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            throw $this->createNotFoundException('No tenant context.');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_settings_location_map', (string) $request->request->get('_token', ''))) {
                $this->addFlash('danger', $this->translator->trans('admin.location_map.csrf_invalid', [], 'admin'));
                return $this->redirectToRoute('admin_settings_location_map');
            }
            $endpoint = trim((string) $request->request->get('endpoint', ''));
            if ($endpoint !== '' && !self::isValidEndpoint($endpoint)) {
                $this->addFlash('danger', $this->translator->trans('admin.location_map.endpoint_invalid', [], 'admin'));
                return $this->redirectToRoute('admin_settings_location_map');
            }
            $this->coordinateUpdater->saveSettings(
                $tenant,
                $request->request->getBoolean('online_geocoding'),
                $endpoint !== '' ? $endpoint : OnlineGeocoder::DEFAULT_ENDPOINT,
                LocationMapSettings::fromArray([
                    'peer_mode' => $request->request->get('peer_mode'),
                    'precision' => $request->request->get('precision'),
                    // The form lists active checks; everything optional not ticked is off.
                    'disabled_checks' => array_values(array_diff(
                        LocationMapSettings::OPTIONAL_CHECKS,
                        $request->request->all('active_checks'),
                    )),
                ]),
            );

            $this->addFlash('success', $this->translator->trans('admin.location_map.saved', [], 'admin'));
            return $this->redirectToRoute('admin_settings_location_map');
        }

        return $this->render('admin/system_settings/location_map.html.twig', [
            'current' => OnlineGeocoder::settings($tenant),
            'default_endpoint' => OnlineGeocoder::DEFAULT_ENDPOINT,
            'map' => LocationMapSettings::of($tenant),
            'peer_modes' => LocationMapSettings::PEER_MODES,
            'precisions' => LocationMapSettings::PRECISIONS,
            'optional_checks' => LocationMapSettings::OPTIONAL_CHECKS,
            'check_norms' => LocationPostureService::CHECK_NORM,
        ]);
    }

    /** HTTPS only, no credentials, no port tricks: the URL is called server-side. */
    public static function isValidEndpoint(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        return ($parts['scheme'] ?? '') === 'https'
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && isset($parts['host']);
    }
}
