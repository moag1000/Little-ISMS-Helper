<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\PublicUrlGenerator;
use App\Security\Voter\TenantScopedAdminVoter;
use App\Service\AuditLogger;
use App\Service\EnvironmentWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Diese Adresse übernehmen" on the health page: writes DEFAULT_URI (and, on request,
 * SYMFONY_TRUSTED_HOSTS) into `.env.local`, so an operator without shell access fixes
 * the public address in the UI. The address is the one the super admin is using right
 * now and sees on the button before confirming.
 *
 * Instance setting for every organisation: super admin only (ADMIN_GLOBAL_OP).
 */
#[IsGranted(TenantScopedAdminVoter::ADMIN_GLOBAL_OP)]
final class PublicAddressController extends AbstractController
{
    public function __construct(
        private readonly EnvironmentWriter $environmentWriter,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('/admin/monitoring/public-address', name: 'admin_public_address_apply', methods: ['POST'])]
    #[IsCsrfTokenValid('public_address_apply')]
    public function apply(Request $request): Response
    {
        $address = self::addressOf($request);
        $variables = ['DEFAULT_URI' => $address];
        if ($request->request->getBoolean('trusted_hosts')) {
            $variables['SYMFONY_TRUSTED_HOSTS'] = self::trustedHostsFor($request->getHost());
        }

        try {
            $this->environmentWriter->writeEnvVariables($variables);
            $this->auditLogger->logCustom(
                'instance.public_address_set',
                'Instance',
                null,
                null,
                $variables,
                'DEFAULT_URI / SYMFONY_TRUSTED_HOSTS set from the health page',
            );
            $this->addFlash('success', 'monitoring.public_address.flash.saved');
        } catch (\Throwable) {
            $this->addFlash('danger', 'monitoring.public_address.flash.failed');
        }

        return $this->redirectToRoute('monitoring_health');
    }

    /** The address shown on the button: scheme, host, port and base path of this request. */
    public static function addressOf(Request $request): string
    {
        return rtrim($request->getSchemeAndHttpHost() . $request->getBasePath(), '/');
    }

    /** Anchored pattern for this host plus localhost (container health check). */
    public static function trustedHostsFor(string $host): string
    {
        $patterns = ['^' . preg_quote(strtolower($host), null) . '$'];
        if (!in_array(strtolower($host), ['localhost', '127.0.0.1'], true)) {
            $patterns[] = '^localhost$';
            $patterns[] = '^127\.0\.0\.1$';
        }

        return implode(',', $patterns);
    }
}
