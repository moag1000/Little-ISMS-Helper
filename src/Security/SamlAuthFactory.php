<?php

declare(strict_types=1);

namespace App\Security;

use App\Exception\BusinessRule\BusinessRuleException;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Error;
use OneLogin\Saml2\Utils;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The one place that builds the OneLogin SAML Auth object (login, ACS,
 * metadata, SLO) for the env-configured SAML login.
 *
 * Entity ID and ACS/SLS URLs come from DEFAULT_URI, never from the request's
 * Host header (S9): a forged Host would otherwise change the URLs the SP signs
 * into its requests and the destination OneLogin checks a response against.
 * OneLogin's own "current URL" (destination check) is pinned to the same base.
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
        #[Autowire('%env(default::DEFAULT_URI)%')]
        private readonly ?string $defaultUri = null,
    ) {
    }

    /**
     * @throws Error
     * @throws BusinessRuleException when DEFAULT_URI does not name this installation
     */
    public function createAuth(): Auth
    {
        $baseUrl = $this->baseUrl();
        $this->pinOneLoginSelfUrl($baseUrl);

        return new Auth([
            'strict' => true,
            'debug' => $this->environment === 'dev',
            'sp' => $this->getServiceProviderConfig($baseUrl),
            'idp' => $this->getIdentityProviderConfig(),
            'security' => $this->getSecurityConfig(),
        ]);
    }

    /**
     * Scheme, host and port of DEFAULT_URI (no path, no trailing slash) — the
     * same shape the request-based base URL had, so existing IdP registrations
     * keep their entity id when DEFAULT_URI names the public address.
     */
    public function baseUrl(): string
    {
        $parts = parse_url(trim((string) $this->defaultUri));
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new BusinessRuleException('SAML needs DEFAULT_URI (public address of this installation, e.g. https://isms.example.org).', 'saml_default_uri');
        }
        if ($this->environment === 'prod' && in_array($host, ['localhost', '127.0.0.1', '::1', 'yourdomain.com'], true)) {
            throw new BusinessRuleException('SAML needs DEFAULT_URI set to the public address of this installation (it is still "' . $host . '").', 'saml_default_uri');
        }
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }

    private function pinOneLoginSelfUrl(string $baseUrl): void
    {
        $parts = (array) parse_url($baseUrl);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        Utils::setSelfProtocol($scheme);
        Utils::setSelfHost((string) ($parts['host'] ?? ''));
        Utils::setSelfPort(isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80));
    }

    /**
     * @return array<string, mixed>
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
     * @return array<string, mixed>
     */
    private function getIdentityProviderConfig(): array
    {
        $tenantId = $this->azureTenantId ?? '';

        return [
            'entityId' => $this->samlIdpEntityId ?: "https://sts.windows.net/{$tenantId}/",
            'singleSignOnService' => [
                'url' => $this->samlIdpSsoUrl ?: "https://login.microsoftonline.com/{$tenantId}/saml2",
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
            ],
            'singleLogoutService' => [
                'url' => $this->samlIdpSloUrl ?: "https://login.microsoftonline.com/{$tenantId}/saml2",
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
            ],
            'x509cert' => $this->samlIdpCert,
        ];
    }

    /**
     * @return array<string, mixed>
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
            // A response answering a request is only accepted together with
            // that request's id (SamlMessageGuard passes it to processResponse()).
            'rejectUnsolicitedResponsesWithInResponseTo' => true,
        ];
    }
}
