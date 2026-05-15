<?php

declare(strict_types=1);

namespace App\Tests\Controller\Trait;

use App\Controller\Trait\LocalizedFlashTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Unit tests for {@see LocalizedFlashTrait}.
 *
 * Verifies that the trait routes flash-messages through the controller-declared
 * translation-domain (Foundation-Pattern P-5). Uses a lightweight test harness
 * that wraps a real Symfony Session in a RequestStack and a stub translator
 * which echoes back `{domain}::{key}` so we can assert the resolved domain.
 */
final class LocalizedFlashTraitTest extends TestCase
{
    #[Test]
    public function flashSuccessRoutesThroughDeclaredDomain(): void
    {
        $controller = $this->createControllerWithDomain('asset');

        $controller->triggerFlash('success', 'asset.success.created');

        $flashes = $controller->getSessionFlashBag()->get('success');
        $this->assertSame(['asset::asset.success.created'], $flashes);
    }

    #[Test]
    public function flashErrorUsesErrorBucket(): void
    {
        $controller = $this->createControllerWithDomain('risk');

        $controller->triggerFlash('error', 'risk.link_incident.csrf_invalid');

        $flashes = $controller->getSessionFlashBag()->get('error');
        $this->assertSame(['risk::risk.link_incident.csrf_invalid'], $flashes);
    }

    #[Test]
    public function flashWarningUsesWarningBucket(): void
    {
        $controller = $this->createControllerWithDomain('risk');

        $controller->triggerFlash('warning', 'risk.acceptance.error.already_accepted');

        $flashes = $controller->getSessionFlashBag()->get('warning');
        $this->assertSame(['risk::risk.acceptance.error.already_accepted'], $flashes);
    }

    #[Test]
    public function flashInfoUsesInfoBucket(): void
    {
        $controller = $this->createControllerWithDomain('business_process');

        $controller->triggerFlash('info', 'business_process.info.note');

        $flashes = $controller->getSessionFlashBag()->get('info');
        $this->assertSame(['business_process::business_process.info.note'], $flashes);
    }

    #[Test]
    public function paramsArePassedThroughToTranslator(): void
    {
        $controller = $this->createControllerWithDomain('risk');

        $controller->triggerFlash('success', 'risk.acceptance.success.approval_requested', [
            '%approver%' => 'Alice',
            '%level%' => 'manager',
        ]);

        $flashes = $controller->getSessionFlashBag()->get('success');
        // The stub translator only returns `{domain}::{key}` — params are not
        // injected into the resulting string, but we assert below via the
        // translator-mock that they reached `trans()`.
        $this->assertSame(['risk::risk.acceptance.success.approval_requested'], $flashes);
        $this->assertSame([
            '%approver%' => 'Alice',
            '%level%' => 'manager',
        ], $controller->getLastTranslatedParams());
    }

    private function createControllerWithDomain(string $domain): FakeFlashController
    {
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $translator = new class implements TranslatorInterface {
            public array $lastParams = [];
            public string $lastDomain = '';

            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                $this->lastParams = $parameters;
                $this->lastDomain = (string) $domain;

                return $domain . '::' . $id;
            }

            public function getLocale(): string
            {
                return 'de';
            }
        };

        return new FakeFlashController($domain, $translator, $requestStack);
    }
}

/**
 * Test-only controller that exposes the otherwise-protected trait helpers.
 * Uses RequestStack instead of the full AbstractController to keep the test
 * a pure unit test without a kernel boot.
 */
final class FakeFlashController
{
    use LocalizedFlashTrait;

    public function __construct(
        private readonly string $domain,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function triggerFlash(string $type, string $key, array $params = []): void
    {
        match ($type) {
            'success' => $this->flashSuccess($key, $params),
            'error'   => $this->flashError($key, $params),
            'warning' => $this->flashWarning($key, $params),
            'info'    => $this->flashInfo($key, $params),
        };
    }

    public function getSessionFlashBag(): \Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface
    {
        /** @var Session $session */
        $session = $this->requestStack->getSession();

        return $session->getFlashBag();
    }

    public function getLastTranslatedParams(): array
    {
        return $this->translator->lastParams; // @phpstan-ignore-line — anonymous class
    }

    protected function getFlashDomain(): string
    {
        return $this->domain;
    }

    protected function getTranslator(): TranslatorInterface
    {
        return $this->translator;
    }

    /**
     * Re-implement the {@see AbstractController::addFlash()} helper without
     * pulling in the full controller infrastructure — we only need to push a
     * flash on the current session.
     */
    private function addFlash(string $type, mixed $message): void
    {
        /** @var Session $session */
        $session = $this->requestStack->getSession();
        $session->getFlashBag()->add($type, (string) $message);
    }
}
