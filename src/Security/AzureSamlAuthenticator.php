<?php

declare(strict_types=1);

namespace App\Security;

use DateTimeImmutable;
use App\Entity\User;
use App\Service\Sso\SsoAccountLinkPolicy;
use App\Service\Sso\SsoClaimPolicy;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use App\Repository\TenantRepository;
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
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly RouterInterface $router,
        private readonly TenantRepository $tenantRepository,
        private readonly SamlAuthFactory $samlAuthFactory,
        private readonly SamlMessageGuard $messageGuard,
        #[Autowire('%env(bool:default:app.azure_allow_email_linking_default:AZURE_ALLOW_EMAIL_LINKING)%')]
        private readonly bool $allowEmailLinking = false,
        private readonly ?LoggerInterface $logger = null,
        #[Autowire('%env(default::AZURE_TENANT_ID)%')]
        private readonly ?string $azureTenantId = null,
        #[Autowire('%env(default::AZURE_ALLOWED_TENANT_IDS)%')]
        private readonly ?string $azureAllowedTenantIds = null,
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
            $samlAuth = $this->getSamlAuth();
            // S9: only a response to a request this installation sent, and
            // each assertion once (SamlMessageGuard).
            $requestId = $this->messageGuard->consumeRequestId($request);
            $samlAuth->processResponse($requestId);

            if (!$samlAuth->isAuthenticated()) {
                throw new AuthenticationException('SAML authentication failed: ' . implode(', ', $samlAuth->getErrors()));
            }
            $this->messageGuard->assertFirstUse($samlAuth->getLastAssertionId(), $samlAuth->getLastAssertionNotOnOrAfter());

            $attributes = $samlAuth->getAttributes();
            $nameId = $samlAuth->getNameId();

            // Extract user information from SAML attributes
            $email = $this->getSamlAttribute($attributes, 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress', $nameId);
            $firstName = $this->getSamlAttribute($attributes, 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname', '');
            $lastName = $this->getSamlAttribute($attributes, 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname', '');
            $objectId = $this->getSamlAttribute($attributes, 'http://schemas.microsoft.com/identity/claims/objectidentifier');
            $tenantId = $this->getSamlAttribute($attributes, 'http://schemas.microsoft.com/identity/claims/tenantid');
            $jobTitle = $this->getSamlAttribute($attributes, 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/jobtitle');
            $department = $this->getSamlAttribute($attributes, 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/department');

            // An Entra assertion names its directory: it must be a configured
            // one (SsoClaimPolicy). Other IdPs send no tenantid; the signature
            // against SAML_IDP_CERT already pins the IdP.
            $allowedTenants = SsoClaimPolicy::allowedAzureTenants($this->azureTenantId, $this->azureAllowedTenantIds);
            if ($tenantId !== null && $allowedTenants !== []) {
                SsoClaimPolicy::assertAzureTenant($tenantId, $allowedTenants);
            }

            return new SelfValidatingPassport(
                new UserBadge($email, function() use ($email, $nameId, $firstName, $lastName, $objectId, $tenantId, $jobTitle, $department, $attributes) {
                    // Find or create user
                    $user = null;

                    if ($objectId) {
                        $user = $this->userRepository->findByAzureObjectId($objectId);
                    }

                    if (!$user && $email) {
                        $user = $this->userRepository->findOneBy(['email' => $email]);
                        // Without an objectidentifier claim no object id is ever
                        // stored; an account already bound via SAML to this very
                        // NameID counts as linked. Anything else is a
                        // link by e-mail and goes through the policy.
                        if ($user instanceof User && !$this->isBoundToNameId($user, $objectId, $nameId)) {
                            // The e-mail attribute is signed by the IdP pinned through
                            // SAML_IDP_CERT (one directory per enterprise app), so it
                            // counts as verified; linking still needs the opt-in.
                            SsoAccountLinkPolicy::assertMayLinkAzureByEmail($user, $this->allowEmailLinking, $this->logger, emailVerified: true);
                        }
                    }

                    if (!$user) {
                        // Create new user
                        $user = new User();
                        $user->setEmail($email);
                        $user->setIsVerified(true);
                        // Azure carries no organisation: on a single-organisation installation the
                        // account belongs to the sole one (otherwise it sees no organisation's data).
                        $user->setTenant($this->tenantRepository->findSoleTenant());
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
                        'saml_nameid' => $nameId !== '' ? $nameId : $email,
                    ]);

                    $user->setLastLoginAt(new DateTimeImmutable());
                    $user->setUpdatedAt(new DateTimeImmutable());

                    $this->entityManager->persist($user);
                    $this->entityManager->flush();

                    return $user;
                })
            );
        } catch (Error|\OneLogin\Saml2\ValidationError $e) {
            throw new AuthenticationException('SAML Error: ' . $e->getMessage());
        } catch (\App\Exception\BusinessRule\BusinessRuleException $e) {
            // DEFAULT_URI missing (SamlAuthFactory::baseUrl()).
            throw new AuthenticationException($e->getMessage(), 0, $e);
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
        if ($exception instanceof CustomUserMessageAuthenticationException && $request->hasSession()) {
            $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);
        }

        return new RedirectResponse(
            $this->router->generate('app_login', ['error' => $message])
        );
    }

    private function getSamlAuth(): Auth
    {
        return $this->samlAuth ??= $this->samlAuthFactory->createAuth();
    }

    /**
     * Account already bound to this SAML NameID (stored on an earlier SAML
     * login) — only used when the assertion carries no objectidentifier.
     */
    private function isBoundToNameId(User $user, mixed $objectId, ?string $nameId): bool
    {
        if ($objectId || $nameId === null || $nameId === '' || $user->getAuthProvider() !== 'azure_saml') {
            return false;
        }

        return ($user->getAzureMetadata()['saml_nameid'] ?? null) === $nameId;
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
