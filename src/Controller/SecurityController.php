<?php

namespace App\Controller;

use Symfony\Component\Security\Core\User\UserInterface;
use LogicException;
use Exception;
use App\Security\SamlAuthFactory;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Contracts\Translation\TranslatorInterface;

class SecurityController extends AbstractController
{
    public function __construct(
        private readonly RateLimiterFactory $rateLimiterFactory,
        private readonly SamlAuthFactory $samlAuthFactory,
        private readonly TranslatorInterface $translator
    ) {}

    #[Route('/login', name: 'app_login')]
    public function login(Request $request, AuthenticationUtils $authenticationUtils): Response
    {
        // Security: Rate limit login attempts to prevent brute force attacks
        $limiter = $this->rateLimiterFactory->create($request->getClientIp());

        if (false === $limiter->consume(1)->isAccepted()) {
            $this->addFlash('error', $this->translator->trans('security.error.too_many_attempts'));

            $response = $this->render('security/login.html.twig', [
                'last_username' => '',
                'error' => null,
                'rate_limited' => true,
            ]);

            // Prevent caching of login page to ensure fresh CSRF tokens
            $response->setSharedMaxAge(0);
            $response->headers->addCacheControlDirective('no-cache', true);
            $response->headers->addCacheControlDirective('no-store', true);
            $response->headers->addCacheControlDirective('must-revalidate', true);

            return $response;
        }

        // Store locale preference from query parameter or browser preference
        $locale = $request->query->get('locale')
            ?? $request->getSession()->get('_locale')
            ?? $request->getPreferredLanguage(['de', 'en'])
            ?? 'de';

        $request->getSession()->set('_locale', $locale);
        $request->setLocale($locale);

        // If user is already logged in, redirect to dashboard with locale
        if ($this->getUser() instanceof UserInterface) {
            return $this->redirectToRoute('app_dashboard', ['_locale' => $locale]);
        }

        // Get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();

        // Last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        $response = $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
            'rate_limited' => false,
        ]);

        // Prevent caching of login page to ensure fresh CSRF tokens
        $response->setSharedMaxAge(0);
        $response->headers->addCacheControlDirective('no-cache', true);
        $response->headers->addCacheControlDirective('no-store', true);
        $response->headers->addCacheControlDirective('must-revalidate', true);

        return $response;
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        // This method can be blank - it will be intercepted by the logout key on your firewall
        throw new LogicException('This method should never be reached.');
    }

    /**
     * Link to this controller to start the "connect" process for Azure OAuth
     */
    #[Route('/oauth/azure/connect', name: 'oauth_azure_connect')]
    public function connectAzure(ClientRegistry $clientRegistry): Response
    {
        return $clientRegistry
            ->getClient('azure')
            ->redirect([
                'openid', 'email', 'profile'
            ]);
    }

    /**
     * After going to Azure, you're redirected back here
     * because this is the "redirect_route" you configured
     * in config/packages/knpu_oauth2_client.yaml
     */
    #[Route('/oauth/azure/check', name: 'oauth_azure_check')]
    public function connectAzureCheck(): Response
    {
        // This route will never be reached - the AzureOAuthAuthenticator will intercept it
        return new Response('This should never be reached');
    }

    /**
     * SAML Login - Initiate SSO
     */
    #[Route('/saml/login', name: 'saml_login')]
    public function samlLogin(Request $request): Response
    {
        try {
            $samlAuth = $this->samlAuthFactory->createAuth($request);
            $samlAuth->login();

            // This will never be reached as login() redirects
            return new Response('Redirecting to SAML IdP...');
        } catch (Exception $e) {
            $this->addFlash('error', $this->translator->trans('security.error.saml_login_error') . ': ' . $e->getMessage());
            return $this->redirectToRoute('app_login');
        }
    }

    /**
     * SAML Assertion Consumer Service - handles SAML response
     */
    #[Route('/saml/acs', name: 'saml_acs', methods: ['POST'])]
    public function samlAcs(): Response
    {
        // This route will be intercepted by AzureSamlAuthenticator
        return new Response('This should never be reached');
    }

    /**
     * SAML Metadata
     */
    #[Route('/saml/metadata', name: 'saml_metadata')]
    public function samlMetadata(Request $request): Response
    {
        try {
            $samlAuth = $this->samlAuthFactory->createAuth($request);
            $settings = $samlAuth->getSettings();
            $metadata = $settings->getSPMetadata();
            $errors = $settings->validateMetadata($metadata);

            if (!empty($errors)) {
                throw new Exception('Invalid SP metadata: ' . implode(', ', $errors));
            }

            $response = new Response($metadata);
            $response->headers->set('Content-Type', 'text/xml');

            return $response;
        } catch (Exception $e) {
            throw $this->createNotFoundException('Metadata generation failed: ' . $e->getMessage());
        }
    }

    /**
     * SAML Single Logout Service
     */
    #[Route('/saml/sls', name: 'saml_sls')]
    public function samlSls(Request $request): Response
    {
        try {
            $samlAuth = $this->samlAuthFactory->createAuth($request);
            $samlAuth->processSLO();

            $errors = $samlAuth->getErrors();
            if (!empty($errors)) {
                throw new Exception('SAML SLO Error: ' . implode(', ', $errors));
            }

            return $this->redirectToRoute('app_login');
        } catch (Exception $e) {
            $this->addFlash('error', $this->translator->trans('security.error.saml_logout_error') . ': ' . $e->getMessage());
            return $this->redirectToRoute('app_login');
        }
    }

    // Note: SAML Auth creation moved to SamlAuthFactory service (Symfony best practice)
    // This separates configuration logic from controller and enables proper parameter injection
}
