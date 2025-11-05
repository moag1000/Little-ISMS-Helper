<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Error;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class AzureSamlAuthenticator extends AbstractAuthenticator
{
    private ?Auth $samlAuth = null;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private RouterInterface $router,
        private string $projectDir
    ) {
    }

    public function supports(Request $request): ?bool
    {
        // This authenticator handles SAML ACS (Assertion Consumer Service) route
        return $request->attributes->get('_route') === 'saml_acs';
    }

    public function authenticate(Request $request): Passport
    {
        try {
            $samlAuth = $this->getSamlAuth($request);
            $samlAuth->processResponse();

            if (!$samlAuth->isAuthenticated()) {
                throw new AuthenticationException('SAML authentication failed: ' . implode(', ', $samlAuth->getErrors()));
            }

            $attributes = $samlAuth->getAttributes();
            $nameId = $samlAuth->getNameId();

            // Extract user information from SAML attributes
            $email = $this->getSamlAttribute($attributes, 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress', $nameId);
            $firstName = $this->getSamlAttribute($attributes, 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname', '');
            $lastName = $this->getSamlAttribute($attributes, 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname', '');
            $objectId = $this->getSamlAttribute($attributes, 'http://schemas.microsoft.com/identity/claims/objectidentifier', null);
            $tenantId = $this->getSamlAttribute($attributes, 'http://schemas.microsoft.com/identity/claims/tenantid', null);
            $jobTitle = $this->getSamlAttribute($attributes, 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/jobtitle', null);
            $department = $this->getSamlAttribute($attributes, 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/department', null);

            return new SelfValidatingPassport(
                new UserBadge($email, function() use ($email, $firstName, $lastName, $objectId, $tenantId, $jobTitle, $department, $attributes) {
                    // Find or create user
                    $user = null;

                    if ($objectId) {
                        $user = $this->userRepository->findByAzureObjectId($objectId);
                    }

                    if (!$user && $email) {
                        $user = $this->userRepository->findOneBy(['email' => $email]);
                    }

                    if (!$user) {
                        // Create new user
                        $user = new User();
                        $user->setEmail($email);
                        $user->setIsVerified(true);
                    }

                    // Update user data from SAML
                    if ($objectId) {
                        $user->setAzureObjectId($objectId);
                    }
                    if ($tenantId) {
                        $user->setAzureTenantId($tenantId);
                    }

                    $user->setAuthProvider('azure_saml');
                    $user->setFirstName($firstName ?: 'Unknown');
                    $user->setLastName($lastName ?: 'User');

                    if ($jobTitle) {
                        $user->setJobTitle($jobTitle);
                    }
                    if ($department) {
                        $user->setDepartment($department);
                    }

                    // Store all SAML attributes as metadata
                    $user->setAzureMetadata([
                        'saml_attributes' => $attributes,
                        'saml_nameid' => $email,
                    ]);

                    $user->setLastLoginAt(new \DateTimeImmutable());
                    $user->setUpdatedAt(new \DateTimeImmutable());

                    $this->entityManager->persist($user);
                    $this->entityManager->flush();

                    return $user;
                })
            );
        } catch (Error $e) {
            throw new AuthenticationException('SAML Error: ' . $e->getMessage());
        }
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Redirect to dashboard after successful login
        $targetUrl = $this->router->generate('app_home');
        return new RedirectResponse($targetUrl);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $message = strtr($exception->getMessageKey(), $exception->getMessageData());

        return new RedirectResponse(
            $this->router->generate('app_login', ['error' => $message])
        );
    }

    private function getSamlAuth(Request $request): Auth
    {
        if ($this->samlAuth !== null) {
            return $this->samlAuth;
        }

        // Build SAML settings
        $samlSettings = $this->buildSamlSettings($request);

        $this->samlAuth = new Auth($samlSettings);
        return $this->samlAuth;
    }

    private function buildSamlSettings(Request $request): array
    {
        $baseUrl = $request->getSchemeAndHttpHost();

        return [
            'strict' => true,
            'debug' => $_ENV['APP_ENV'] === 'dev',
            'sp' => [
                'entityId' => $baseUrl . '/saml/metadata',
                'assertionConsumerService' => [
                    'url' => $baseUrl . $this->router->generate('saml_acs'),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                ],
                'singleLogoutService' => [
                    'url' => $baseUrl . $this->router->generate('saml_sls'),
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
    }

    private function getSamlAttribute(array $attributes, string $key, mixed $default = null): mixed
    {
        if (!isset($attributes[$key])) {
            return $default;
        }

        $value = $attributes[$key];

        if (is_array($value) && count($value) === 1) {
            return $value[0];
        }

        return $value;
    }
}
