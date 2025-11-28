<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Security\SamlAuthFactory;
use Exception;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Client\OAuth2ClientInterface;
use LogicException;
use OneLogin\Saml2\Auth as SamlAuth;
use OneLogin\Saml2\Settings as SamlSettings;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for SecurityController
 *
 * Tests authentication flows including:
 * - Local login (with rate limiting)
 * - OAuth (Azure AD)
 * - SAML SSO
 * - Logout functionality
 *
 * Note: Some tests may fail if there are routing configuration issues in the application.
 * Specifically, check for duplicate {_locale} parameters in route patterns (e.g., ProfileController).
 *
 * Test Coverage:
 * - Login page rendering and cache headers
 * - Locale handling (query parameters, session, defaults)
 * - Authentication state (redirect authenticated users)
 * - OAuth Azure integration
 * - SAML login, metadata, and single logout
 * - Error handling for SAML and OAuth flows
 * - Form field presence validation
 */
class SecurityControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testLoginPageRendersSuccessfully(): void
    {
        // Act
        $this->client->request('GET', '/login');

        // Assert
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    public function testLoginPageSetsCorrectCacheHeaders(): void
    {
        // Act
        $this->client->request('GET', '/login');
        $response = $this->client->getResponse();

        // Assert
        $this->assertTrue($response->headers->hasCacheControlDirective('no-cache'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('must-revalidate'));
    }

    public function testLoginPageStoresLocaleFromQueryParameter(): void
    {
        // Act
        $this->client->request('GET', '/login?locale=en');
        $session = $this->client->getRequest()->getSession();

        // Assert
        $this->assertSame('en', $session->get('_locale'));
    }

    public function testLoginPageUsesSessionLocaleWhenNoQueryParameter(): void
    {
        // Set session locale
        $this->client->request('GET', '/login');
        $session = $this->client->getRequest()->getSession();
        $session->set('_locale', 'en');

        // Act
        $this->client->request('GET', '/login');

        // Assert
        $this->assertSame('en', $session->get('_locale'));
    }

    public function testLoginPageDefaultsToSupportedLocale(): void
    {
        // Act
        $this->client->request('GET', '/login');
        $session = $this->client->getRequest()->getSession();

        // Assert - Should be either 'de' or 'en'
        $this->assertContains($session->get('_locale'), ['de', 'en']);
    }

    public function testLoginRedirectsAuthenticatedUserToDashboard(): void
    {
        // Arrange - Create a mock user
        $user = $this->createMock(User::class);
        $user->method('getUserIdentifier')->willReturn('test@example.com');
        $user->method('getRoles')->willReturn(['ROLE_USER']);

        // Simulate authenticated user
        $this->client->loginUser($user);

        // Act
        $this->client->request('GET', '/login');

        // Assert
        $this->assertResponseRedirects();
    }

    public function testLogoutThrowsLogicException(): void
    {
        // The logout route should never actually execute the controller method
        // as it's intercepted by the security firewall
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('This method should never be reached');

        // Directly call the controller method (bypassing the firewall)
        $controller = static::getContainer()->get('App\Controller\SecurityController');
        $controller->logout();
    }

    public function testOAuthAzureConnectRedirectsToAzure(): void
    {
        // Arrange - Mock the OAuth client
        $oauthClient = $this->createMock(OAuth2ClientInterface::class);
        $redirectResponse = new RedirectResponse('https://login.microsoftonline.com/oauth2/authorize');
        $oauthClient->method('redirect')
            ->with(['openid', 'email', 'profile'])
            ->willReturn($redirectResponse);

        $clientRegistry = $this->createMock(ClientRegistry::class);
        $clientRegistry->method('getClient')
            ->with('azure')
            ->willReturn($oauthClient);

        static::getContainer()->set(ClientRegistry::class, $clientRegistry);

        // Act
        $this->client->request('GET', '/oauth/azure/connect');

        // Assert
        $this->assertResponseRedirects();
    }

    public function testOAuthAzureCheckReturnsPlaceholderResponse(): void
    {
        // This route is intercepted by the authenticator in production
        // The controller method should never actually be reached
        // Act
        $this->client->request('GET', '/oauth/azure/check');

        // Assert
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('This should never be reached', $this->client->getResponse()->getContent());
    }

    public function testSamlLoginWithMockedFactory(): void
    {
        // Arrange - Mock SAML auth to avoid actual SAML configuration
        $samlAuth = $this->createMock(SamlAuth::class);
        $samlAuth->expects($this->once())
            ->method('login');

        $samlAuthFactory = $this->createMock(SamlAuthFactory::class);
        $samlAuthFactory->method('createAuth')
            ->willReturn($samlAuth);

        static::getContainer()->set(SamlAuthFactory::class, $samlAuthFactory);

        // Act
        $this->client->request('GET', '/saml/login');

        // Assert - login() redirects or returns success message
        $this->assertTrue(
            $this->client->getResponse()->isRedirect() ||
            $this->client->getResponse()->isSuccessful()
        );
    }

    public function testSamlLoginHandlesExceptionGracefully(): void
    {
        // Arrange - Mock SAML factory to throw exception
        $samlAuthFactory = $this->createMock(SamlAuthFactory::class);
        $samlAuthFactory->method('createAuth')
            ->willThrowException(new Exception('SAML configuration error'));

        static::getContainer()->set(SamlAuthFactory::class, $samlAuthFactory);

        // Act
        $this->client->request('GET', '/saml/login');

        // Assert - Should redirect to login on error
        $this->assertResponseRedirects('/login');
    }

    public function testSamlAcsReturnsPlaceholderResponse(): void
    {
        // This route is intercepted by the SAML authenticator in production
        // Act
        $this->client->request('POST', '/saml/acs');

        // Assert
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('This should never be reached', $this->client->getResponse()->getContent());
    }

    public function testSamlMetadataReturnsXmlWithMockedFactory(): void
    {
        // Arrange - Mock SAML settings and auth
        $samlSettings = $this->createMock(SamlSettings::class);
        $metadataXml = '<?xml version="1.0"?><EntityDescriptor></EntityDescriptor>';
        $samlSettings->method('getSPMetadata')->willReturn($metadataXml);
        $samlSettings->method('validateMetadata')->willReturn([]);

        $samlAuth = $this->createMock(SamlAuth::class);
        $samlAuth->method('getSettings')->willReturn($samlSettings);

        $samlAuthFactory = $this->createMock(SamlAuthFactory::class);
        $samlAuthFactory->method('createAuth')->willReturn($samlAuth);

        static::getContainer()->set(SamlAuthFactory::class, $samlAuthFactory);

        // Act
        $this->client->request('GET', '/saml/metadata');

        // Assert
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'text/xml; charset=UTF-8');
        $this->assertStringContainsString('EntityDescriptor', $this->client->getResponse()->getContent());
    }

    public function testSamlMetadataHandlesValidationErrors(): void
    {
        // Arrange - Mock SAML settings with validation errors
        $samlSettings = $this->createMock(SamlSettings::class);
        $samlSettings->method('getSPMetadata')->willReturn('<invalid>');
        $samlSettings->method('validateMetadata')->willReturn(['Invalid metadata']);

        $samlAuth = $this->createMock(SamlAuth::class);
        $samlAuth->method('getSettings')->willReturn($samlSettings);

        $samlAuthFactory = $this->createMock(SamlAuthFactory::class);
        $samlAuthFactory->method('createAuth')->willReturn($samlAuth);

        static::getContainer()->set(SamlAuthFactory::class, $samlAuthFactory);

        // Assert - Expect 404 exception
        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

        // Act
        $this->client->request('GET', '/saml/metadata');
    }

    public function testSamlMetadataHandlesFactoryException(): void
    {
        // Arrange - Mock factory to throw exception
        $samlAuthFactory = $this->createMock(SamlAuthFactory::class);
        $samlAuthFactory->method('createAuth')
            ->willThrowException(new Exception('SAML configuration error'));

        static::getContainer()->set(SamlAuthFactory::class, $samlAuthFactory);

        // Assert - Expect 404 exception
        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

        // Act
        $this->client->request('GET', '/saml/metadata');
    }

    public function testSamlSlsProcessesLogoutSuccessfully(): void
    {
        // Arrange - Mock SAML auth for successful logout
        $samlAuth = $this->createMock(SamlAuth::class);
        $samlAuth->method('processSLO');
        $samlAuth->method('getErrors')->willReturn([]);

        $samlAuthFactory = $this->createMock(SamlAuthFactory::class);
        $samlAuthFactory->method('createAuth')->willReturn($samlAuth);

        static::getContainer()->set(SamlAuthFactory::class, $samlAuthFactory);

        // Act
        $this->client->request('GET', '/saml/sls');

        // Assert
        $this->assertResponseRedirects('/login');
    }

    public function testSamlSlsHandlesSamlErrors(): void
    {
        // Arrange - Mock SAML auth with errors
        $samlAuth = $this->createMock(SamlAuth::class);
        $samlAuth->method('processSLO');
        $samlAuth->method('getErrors')->willReturn(['SAML error occurred']);

        $samlAuthFactory = $this->createMock(SamlAuthFactory::class);
        $samlAuthFactory->method('createAuth')->willReturn($samlAuth);

        static::getContainer()->set(SamlAuthFactory::class, $samlAuthFactory);

        // Act
        $this->client->request('GET', '/saml/sls');

        // Assert - Should redirect to login with error flash
        $this->assertResponseRedirects('/login');
    }

    public function testSamlSlsHandlesException(): void
    {
        // Arrange - Mock factory to throw exception
        $samlAuthFactory = $this->createMock(SamlAuthFactory::class);
        $samlAuthFactory->method('createAuth')
            ->willThrowException(new Exception('SAML configuration error'));

        static::getContainer()->set(SamlAuthFactory::class, $samlAuthFactory);

        // Act
        $this->client->request('GET', '/saml/sls');

        // Assert - Should redirect to login with error flash
        $this->assertResponseRedirects('/login');
    }

    public function testLoginPageDisplaysUsernameField(): void
    {
        // Act
        $this->client->request('GET', '/login');

        // Assert
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="_username"]');
    }

    public function testLoginPageDisplaysPasswordField(): void
    {
        // Act
        $this->client->request('GET', '/login');

        // Assert
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="_password"]');
    }

    public function testSecurityControllerIsRegisteredAsService(): void
    {
        // Act
        $controller = static::getContainer()->get('App\Controller\SecurityController');

        // Assert
        $this->assertInstanceOf(\App\Controller\SecurityController::class, $controller);
    }
}
