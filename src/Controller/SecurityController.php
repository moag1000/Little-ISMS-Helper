<?php

namespace App\Controller;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use OneLogin\Saml2\Auth;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    public function __construct(
        private readonly RateLimiterFactory $loginLimiter
    ) {}

    #[Route('/login', name: 'app_login')]
    public function login(Request $request, AuthenticationUtils $authenticationUtils): Response
    {
        // Security: Rate limit login attempts to prevent brute force attacks
        $limiter = $this->loginLimiter->create($request->getClientIp());

        if (false === $limiter->consume(1)->isAccepted()) {
            $this->addFlash('error', 'Too many login attempts. Please try again in 15 minutes.');

            return $this->render('security/login.html.twig', [
                'last_username' => '',
                'error' => null,
                'rate_limited' => true,
            ]);
        }

        // If user is already logged in, redirect to home
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        // Get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();

        // Last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
            'rate_limited' => false,
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        // This method can be blank - it will be intercepted by the logout key on your firewall
        throw new \LogicException('This method should never be reached.');
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
            $samlAuth = $this->createSamlAuth($request);
            $samlAuth->login();

            // This will never be reached as login() redirects
            return new Response('Redirecting to SAML IdP...');
        } catch (\Exception $e) {
            $this->addFlash('error', 'SAML Login Error: ' . $e->getMessage());
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
            $samlAuth = $this->createSamlAuth($request);
            $settings = $samlAuth->getSettings();
            $metadata = $settings->getSPMetadata();
            $errors = $settings->validateMetadata($metadata);

            if (!empty($errors)) {
                throw new \Exception('Invalid SP metadata: ' . implode(', ', $errors));
            }

            $response = new Response($metadata);
            $response->headers->set('Content-Type', 'text/xml');

            return $response;
        } catch (\Exception $e) {
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
            $samlAuth = $this->createSamlAuth($request);
            $samlAuth->processSLO();

            $errors = $samlAuth->getErrors();
            if (!empty($errors)) {
                throw new \Exception('SAML SLO Error: ' . implode(', ', $errors));
            }

            return $this->redirectToRoute('app_login');
        } catch (\Exception $e) {
            $this->addFlash('error', 'SAML Logout Error: ' . $e->getMessage());
            return $this->redirectToRoute('app_login');
        }
    }

    private function createSamlAuth(Request $request): Auth
    {
        $baseUrl = $request->getSchemeAndHttpHost();

        $samlSettings = [
            'strict' => true,
            'debug' => $_ENV['APP_ENV'] === 'dev',
            'sp' => [
                'entityId' => $baseUrl . '/saml/metadata',
                'assertionConsumerService' => [
                    'url' => $baseUrl . $this->generateUrl('saml_acs'),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                ],
                'singleLogoutService' => [
                    'url' => $baseUrl . $this->generateUrl('saml_sls'),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'NameIDFormat' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
                'x509cert' => $_ENV['SAML_SP_CERT'] ?? '',
                'privateKey' => $_ENV['SAML_SP_PRIVATE_KEY'] ?? '',
            ],
            'idp' => [
                'entityId' => $_ENV['SAML_IDP_ENTITY_ID'] ?? 'https://sts.windows.net/' . ($_ENV['AZURE_TENANT_ID'] ?? '') . '/',
                'singleSignOnService' => [
                    'url' => $_ENV['SAML_IDP_SSO_URL'] ?? 'https://login.microsoftonline.com/' . ($_ENV['AZURE_TENANT_ID'] ?? '') . '/saml2',
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'singleLogoutService' => [
                    'url' => $_ENV['SAML_IDP_SLO_URL'] ?? 'https://login.microsoftonline.com/' . ($_ENV['AZURE_TENANT_ID'] ?? '') . '/saml2',
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'x509cert' => $_ENV['SAML_IDP_CERT'] ?? '',
            ],
            'security' => [
                'nameIdEncrypted' => false,
                'authnRequestsSigned' => true,
                'logoutRequestSigned' => true,
                'logoutResponseSigned' => true,
                'signMetadata' => true,
                'wantMessagesSigned' => true,
                'wantAssertionsSigned' => true,
                'wantNameIdEncrypted' => false,
                'requestedAuthnContext' => false,
            ],
        ];

        return new Auth($samlSettings);
    }
}
