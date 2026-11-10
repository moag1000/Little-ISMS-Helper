<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * One limit for every bulk action: a request names at most {@see MAX_IDS} records in
 * `ids` / `ids[]` (JSON body or form field). The bulk endpoints load and check each id
 * on its own; without a cap a 100 MB body of ids holds a PHP worker for minutes (S31).
 * A list page selects at most one page of rows, so 500 is far above real use.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 0)]
final class BulkIdsLimitSubscriber
{
    public const int MAX_IDS = 500;

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if (!in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }
        if (self::idCount($request) <= self::MAX_IDS) {
            return;
        }

        $event->setResponse($request->getContentTypeFormat() === 'json' || $request->getPreferredFormat() === 'json'
            ? new JsonResponse(['error' => 'too_many_ids', 'max' => self::MAX_IDS], Response::HTTP_BAD_REQUEST)
            : new Response(sprintf('At most %d records per bulk action.', self::MAX_IDS), Response::HTTP_BAD_REQUEST, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]));
    }

    /** The larger of the form field `ids` and the JSON body's `ids`. */
    public static function idCount(Request $request): int
    {
        $form = $request->request->all()['ids'] ?? null;
        $count = is_array($form) ? count($form) : 0;
        // Bulk fetch() calls do not all send a JSON content type: look at the body itself.
        $content = $request->getContent();
        if (str_starts_with(ltrim($content), '{') && str_contains($content, '"ids"')) {
            try {
                $data = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $count;
            }
            if (is_array($data) && is_array($data['ids'] ?? null)) {
                $count = max($count, count($data['ids']));
            }
        }

        return $count;
    }
}
