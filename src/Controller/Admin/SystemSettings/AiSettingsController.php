<?php

declare(strict_types=1);

namespace App\Controller\Admin\SystemSettings;

use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SuggestionOrigin;
use App\Service\Ai\AiAssistant;
use App\Service\Ai\AiSettings;
use App\Service\AuditLogger;
use App\Service\Suggestion\SuggestionQueue;
use App\Service\TenantContext;
use App\Validator\Constraint\NoInternalIp;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * AI suggestions (Paket 5). Off by default. The operator (SUPER_ADMIN) sets
 * up the local model once for the instance; an organisation admin switches
 * AI on for the organisation — local model, or an external OpenAI-compatible
 * API such as Mistral with its own key — and decides whether personal data
 * may leave the house and which use cases run. The AI only ever fills the
 * suggestion queue; a person decides each proposal.
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/admin/settings/ai')]
#[IsGranted('ROLE_ADMIN')]
class AiSettingsController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AiSettings $settings,
        private readonly AiAssistant $assistant,
        private readonly SuggestionQueue $queue,
        private readonly AuditLogger $auditLogger,
        private readonly ValidatorInterface $validator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'admin_settings_ai', methods: ['GET'])]
    public function edit(): Response
    {
        $tenant = $this->tenant();
        $useCases = array_values(array_filter(
            $this->queue->kinds(),
            fn ($kind): bool => $kind->origin() === SuggestionOrigin::Ai && $this->queue->isKindActive($kind, $tenant),
        ));

        return $this->render('admin/system_settings/ai.html.twig', [
            'instance' => $this->settings->instance(),
            'org' => $this->settings->tenant($tenant),
            'use_cases' => array_map(static fn ($k): string => $k->key(), $useCases),
            'default_local_endpoint' => AiSettings::DEFAULT_LOCAL_ENDPOINT,
            'default_external_endpoint' => AiSettings::DEFAULT_EXTERNAL_ENDPOINT,
        ]);
    }

    #[Route('', name: 'admin_settings_ai_save', methods: ['POST'])]
    #[IsCsrfTokenValid('admin_settings_ai')]
    public function save(Request $request): Response
    {
        $tenant = $this->tenant();
        $mode = (string) $request->request->get('mode', AiSettings::MODE_OFF);
        $endpoint = trim((string) $request->request->get('endpoint', '')) ?: AiSettings::DEFAULT_EXTERNAL_ENDPOINT;
        $model = trim((string) $request->request->get('model', '')) ?: AiSettings::DEFAULT_EXTERNAL_MODEL;

        if ($mode === AiSettings::MODE_EXTERNAL && !$this->isPublicHttps($endpoint)) {
            $this->addFlash('danger', 'suggestions.ai.flash.endpoint_invalid');

            return $this->redirectToRoute('admin_settings_ai');
        }
        $enabled = $request->request->all('use_cases');
        $all = array_map(static fn ($k): string => $k->key(), array_filter(
            $this->queue->kinds(),
            static fn ($k): bool => $k->origin() === SuggestionOrigin::Ai,
        ));

        $stored = $this->settings->saveTenant(
            $tenant,
            $mode,
            $endpoint,
            $model,
            (string) $request->request->get('api_key', '') ?: null,
            $request->request->getBoolean('clear_key'),
            $request->request->getBoolean('allow_personal_data'),
            array_values(array_diff($all, array_map('strval', $enabled))),
        );
        $this->auditLogger->logUpdate(
            entityType: 'Tenant',
            entityId: $tenant->getId(),
            oldValues: [],
            newValues: ['ai' => $stored],
            description: 'AI suggestion settings updated',
        );
        $this->addFlash('success', 'suggestions.ai.flash.saved');

        return $this->redirectToRoute('admin_settings_ai');
    }

    #[Route('/instance', name: 'admin_settings_ai_instance', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    #[IsCsrfTokenValid('admin_settings_ai_instance')]
    public function saveInstance(Request $request): Response
    {
        $endpoint = trim((string) $request->request->get('local_endpoint', ''));
        $parts = $endpoint !== '' ? parse_url($endpoint) : false;
        if ($endpoint !== '' && (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']))) {
            $this->addFlash('danger', 'suggestions.ai.flash.endpoint_invalid');

            return $this->redirectToRoute('admin_settings_ai');
        }
        $user = $this->getUser();
        $this->settings->saveInstance(
            $endpoint,
            trim((string) $request->request->get('local_model', '')) ?: AiSettings::DEFAULT_LOCAL_MODEL,
            (string) $request->request->get('local_api_key', '') ?: null,
            $request->request->getBoolean('clear_local_key'),
            $user instanceof User ? (string) $user->getEmail() : 'admin',
        );
        $this->auditLogger->logCustom(
            'ai.instance_settings_updated',
            'SystemSettings',
            null,
            null,
            ['host' => $endpoint !== '' ? (string) parse_url($endpoint, PHP_URL_HOST) : null],
            'Local AI endpoint updated',
        );
        $this->addFlash('success', 'suggestions.ai.flash.saved');

        return $this->redirectToRoute('admin_settings_ai');
    }

    #[Route('/test', name: 'admin_settings_ai_test', methods: ['POST'])]
    #[IsCsrfTokenValid('admin_settings_ai_test')]
    public function test(): Response
    {
        $result = $this->assistant->test($this->tenant());
        if ($result['ok']) {
            $this->addFlash('success', $this->translator->trans('suggestions.ai.flash.test_ok', ['%model%' => (string) $result['model']], 'suggestions'));
        } else {
            $this->addFlash('danger', $this->translator->trans(
                $result['error'] === 'not_configured' ? 'suggestions.ai.flash.test_not_configured' : 'suggestions.ai.flash.test_failed',
                ['%error%' => (string) $result['error']],
                'suggestions',
            ));
        }

        return $this->redirectToRoute('admin_settings_ai');
    }

    /** External endpoints: https, no credentials in the URL, public address (SSRF). */
    private function isPublicHttps(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return \count($this->validator->validate($url, [new NoInternalIp()])) === 0;
    }

    private function tenant(): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            throw $this->createNotFoundException('No organisation context.');
        }

        return $tenant;
    }
}
