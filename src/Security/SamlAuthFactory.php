<?php

namespace App\Security;

use OneLogin\Saml2\Auth;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * SAML Authentication Factory
 *
 * Separates SAML configuration from controller logic (Symfony best practice).
 * Centralizes SAML Auth object creation with proper parameter injection.
 *
 * Configuration Sources:
 * - Environment variables (SAML_*, AZURE_TENANT_ID)
 * - Application parameters (app.env)
 * - Request context (base URL, routes)
 *
 * Security Features:
 * - Signature verification on all messages
 * - Assertion signing required
 * - Logout request/response signing
 * - Metadata signing enabled
 */
class SamlAuthFactory
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire('%env(SAML_SP_CERT)%')]
        private readonly string $samlSpCert,
        #[Autowire('%env(SAML_SP_PRIVATE_KEY)%')]
        private readonly string $samlSpPrivateKey,
        #[Autowire('%env(default::SAML_IDP_ENTITY_ID)%')]
        private readonly ?string $samlIdpEntityId = null,
        #[Autowire('%env(default::SAML_IDP_SSO_URL)%')]
        private readonly ?string $samlIdpSsoUrl = null,
        #[Autowire('%env(default::SAML_IDP_SLO_URL)%')]
        private readonly ?string $samlIdpSloUrl = null,
        #[Autowire('%env(SAML_IDP_CERT)%')]
        private readonly string $samlIdpCert = '',
        #[Autowire('%env(default::AZURE_TENANT_ID)%')]
        private readonly ?string $azureTenantId = null,
    ) {
    }

    /**
     * Create SAML Auth instance with proper configuration
     *
     * @param Request $request HTTP request for base URL and route generation
     * @return Auth Configured SAML Auth instance
     */
    public function createAuth(Request $request): Auth
    {
        $baseUrl = $request->getSchemeAndHttpHost();

        $samlSettings = [
            'strict' => true,
            'debug' => $this->environment === 'dev',
            'sp' => $this->getServiceProviderConfig($baseUrl),
            'idp' => $this->getIdentityProviderConfig(),
            'security' => $this->getSecurityConfig(),
        ];

        return new Auth($samlSettings);
    }

    /**
     * Get Service Provider (SP) configuration
     */
    private function getServiceProviderConfig(string $baseUrl): array
    {
        return [
            'entityId' => $baseUrl . '/saml/metadata',
            'assertionConsumerService' => [
                'url' => $baseUrl . $this->urlGenerator->generate('saml_acs'),
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
            ],
            'singleLogoutService' => [
                'url' => $baseUrl . $this->urlGenerator->generate('saml_sls'),
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
            ],
            'NameIDFormat' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
            'x509cert' => $this->samlSpCert,
            'privateKey' => $this->samlSpPrivateKey,
        ];
    }

    /**
     * Get Identity Provider (IdP) configuration
     */
    private function getIdentityProviderConfig(): array
    {
        $tenantId = $this->azureTenantId ?? '';

        return [
            'entityId' => $this->samlIdpEntityId ?? "https://sts.windows.net/{$tenantId}/",
            'singleSignOnService' => [
                'url' => $this->samlIdpSsoUrl ?? "https://login.microsoftonline.com/{$tenantId}/saml2",
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
            ],
            'singleLogoutService' => [
                'url' => $this->samlIdpSloUrl ?? "https://login.microsoftonline.com/{$tenantId}/saml2",
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
            ],
            'x509cert' => $this->samlIdpCert,
        ];
    }

    /**
     * Get SAML security configuration
     */
    private function getSecurityConfig(): array
    {
        return [
            'nameIdEncrypted' => false,
            'authnRequestsSigned' => true,
            'logoutRequestSigned' => true,
            'logoutResponseSigned' => true,
            'signMetadata' => true,
            'wantMessagesSigned' => true,
            'wantAssertionsSigned' => true,
            'wantNameIdEncrypted' => false,
            'requestedAuthnContext' => false,
        ];
    }
}
