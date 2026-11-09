<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Lifecycle\DeletionRefusedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * S29: a delete the {@see \App\Lifecycle\DeletionPolicy} refused at the flush.
 * The refused removal stays scheduled in the unit of work, so it is dropped
 * first (no later flush in this request may replay it). An HTML page goes back
 * where it came from with the reason as a warning; API, JSON and XHR callers
 * get the exception's 422.
 */
final class DeletionRefusedSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Before the API Platform / framework error listeners (priority 0 and below).
        return [KernelEvents::EXCEPTION => ['onException', 10]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        while (!$exception instanceof DeletionRefusedException && $exception->getPrevious() !== null) {
            $exception = $exception->getPrevious();
        }
        if (!$exception instanceof DeletionRefusedException) {
            return;
        }
        if ($this->entityManager->isOpen()) {
            $this->entityManager->clear();
        }

        $request = $event->getRequest();
        if (!$event->isMainRequest()
            || $request->isXmlHttpRequest()
            || str_starts_with($request->getPathInfo(), '/api')
            || str_starts_with($request->getPathInfo(), '/mcp')
            || \in_array($request->getPreferredFormat(), ['json', 'jsonld'], true)
            || !$request->hasSession()
        ) {
            return; // the 422 of the exception itself
        }

        $refusal = $exception->refusal;
        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('warning', $this->translator->trans($refusal->translationKey, $refusal->parameters, 'lifecycle'));
        }
        $event->setResponse(new RedirectResponse($this->backUrl($request->headers->get('referer'), $request->getSchemeAndHttpHost())));
    }

    /** Only back to this application; else the dashboard. */
    private function backUrl(?string $referer, string $host): string
    {
        if ($referer !== null && str_starts_with($referer, $host . '/')) {
            return $referer;
        }

        return $this->urlGenerator->generate('app_dashboard');
    }
}
