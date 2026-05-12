<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\IdentityProvider;
use App\Form\Step\Sso\SsoDiscoveryStepType;
use App\Form\Step\Sso\SsoPresetStepType;
use App\Form\Step\Sso\SsoTestStepType;
use App\Repository\IdentityProviderRepository;
use App\Service\AuditLogger;
use App\Service\ModuleConfigurationService;
use App\Service\Sso\OidcDiscoveryService;
use App\Service\Sso\SsoProviderRegistry;
use App\Service\Sso\SsoSecretEncryption;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * 3-step SSO Identity Provider wizard.
 *
 * Step 1: Preset selection (entra_id / google / keycloak / okta / auth0 / generic)
 * Step 2: Discovery URL + credentials
 * Step 3: Test connection + domain bindings + activate
 *
 * The wizard builds a session-backed draft IdentityProvider, persisting only on step 3 confirm.
 */
#[Route('/admin/sso/wizard', name: 'admin_sso_wizard_')]
#[IsGranted('ROLE_ADMIN')]
final class SsoWizardController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IdentityProviderRepository $repo,
        private readonly SsoProviderRegistry $registry,
        private readonly SsoSecretEncryption $secrets,
        private readonly OidcDiscoveryService $discovery,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $audit,
        private readonly ModuleConfigurationService $moduleService,
    ) {
    }

    /** Step 1: choose preset */
    #[Route('/step1', name: 'step1', methods: ['GET', 'POST'])]
    public function step1(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('authentication')) {
            return $redirect;
        }

        $form = $this->createForm(SsoPresetStepType::class, null, []);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $presetKey = (string) $form->get('presetType')->getData();
            $request->getSession()->set('sso_wizard_preset', $presetKey);

            return $this->redirectToRoute('admin_sso_wizard_step2');
        }

        return $this->render('admin/sso/wizard/step1_preset.html.twig', [
            'form' => $form,
            'presets' => $this->registry->getAllPresets(),
        ]);
    }

    /** Step 2: discovery URL + credentials */
    #[Route('/step2', name: 'step2', methods: ['GET', 'POST'])]
    public function step2(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('authentication')) {
            return $redirect;
        }

        $presetKey = (string) $request->getSession()->get('sso_wizard_preset', 'generic');
        $provider = $this->getDraftProvider($request);

        if ($provider->getPresetType() === null && $presetKey !== '') {
            $this->registry->applyPresetToProvider($provider, $presetKey);
        }

        $allowGlobal = $this->isGranted('ROLE_SUPER_ADMIN');
        $form = $this->createForm(SsoDiscoveryStepType::class, $provider, [
            'allow_global' => $allowGlobal,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $secretPlain = $form->get('clientSecretPlain')->getData();
            if (is_string($secretPlain) && $secretPlain !== '') {
                $provider->setClientSecretEncrypted($this->secrets->encrypt($secretPlain));
            }
            if (!$allowGlobal) {
                $provider->setTenant($this->tenantContext->getCurrentTenant());
            }
            $this->storeDraftProvider($request, $provider);

            return $this->redirectToRoute('admin_sso_wizard_step3');
        }

        $callbackUrl = $this->generateUrl(
            'app_sso_callback',
            ['slug' => $provider->getSlug() ?: '{slug}'],
            \Symfony\Component\Routing\RouterInterface::ABSOLUTE_URL
        );

        return $this->render('admin/sso/wizard/step2_discovery.html.twig', [
            'form' => $form,
            'provider' => $provider,
            'preset' => $this->registry->getPreset($presetKey),
            'callbackUrl' => $callbackUrl,
        ]);
    }

    /** Step 3: test connection + activate */
    #[Route('/step3', name: 'step3', methods: ['GET', 'POST'])]
    public function step3(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('authentication')) {
            return $redirect;
        }

        $provider = $this->getDraftProvider($request);
        $form = $this->createForm(SsoTestStepType::class, $provider, [
            'domain_bindings_initial' => $provider->getDomainBindings(),
        ]);
        $form->handleRequest($request);

        $discoveryStatus = null;
        if ($provider->getDiscoveryUrl() !== null && $provider->getDiscoveryUrl() !== '') {
            try {
                $this->discovery->applyDiscoveryToProvider($provider);
                $discoveryStatus = 'ok';
            } catch (\Throwable $e) {
                $discoveryStatus = $e->getMessage();
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $bindingsCsv = (string) $form->get('domainBindingsCsv')->getData();
            $provider->setDomainBindings($this->parseList($bindingsCsv));

            $this->em->persist($provider);
            $this->em->flush();

            $this->audit->logCustom(
                'sso.provider.create',
                'IdentityProvider',
                $provider->getId(),
                null,
                ['slug' => $provider->getSlug(), 'preset' => $provider->getPresetType()]
            );

            $request->getSession()->remove('sso_wizard_preset');
            $request->getSession()->remove('sso_wizard_draft');
            $this->addFlash('success', 'sso.wizard.success');

            return $this->redirectToRoute('admin_sso_index');
        }

        $callbackUrl = $this->generateUrl(
            'app_sso_callback',
            ['slug' => $provider->getSlug() ?: '{slug}'],
            \Symfony\Component\Routing\RouterInterface::ABSOLUTE_URL
        );

        return $this->render('admin/sso/wizard/step3_test.html.twig', [
            'form' => $form,
            'provider' => $provider,
            'discoveryStatus' => $discoveryStatus,
            'callbackUrl' => $callbackUrl,
        ]);
    }

    private function getDraftProvider(Request $request): IdentityProvider
    {
        $session = $request->getSession();
        $draft = $session->get('sso_wizard_draft');
        if ($draft instanceof IdentityProvider) {
            return $draft;
        }

        $provider = new IdentityProvider();
        if (!$this->isGranted('ROLE_SUPER_ADMIN')) {
            $provider->setTenant($this->tenantContext->getCurrentTenant());
        }
        return $provider;
    }

    private function storeDraftProvider(Request $request, IdentityProvider $provider): void
    {
        $request->getSession()->set('sso_wizard_draft', $provider);
    }

    /** @return list<string> */
    private function parseList(string $csv): array
    {
        $parts = preg_split('/[\s,;]+/', $csv) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return $out;
    }
}
