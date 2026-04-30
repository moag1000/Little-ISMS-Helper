<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\QuickFixGuard;
use App\Service\SchemaMaintenanceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * Public-fallback Quick-Fix UI: lets self-hosted operators apply pending
 * Doctrine migrations after a Composer-style code update without needing
 * shell access. Reachable when {@see QuickFixGuard} permits — defaults to
 * open, can be locked via installer-token / dev-only / IP-allowlist.
 *
 * Routes are intentionally NOT locale-prefixed and NOT under `/admin/` so
 * the SchemaExceptionSubscriber can redirect here even when the locale
 * resolver itself fails on schema errors.
 */
class QuickFixController extends AbstractController
{
    public function index(
        Request $request,
        QuickFixGuard $guard,
        SchemaMaintenanceService $maintenance,
    ): Response {
        if (!$guard->mayAccess($request)) {
            return $this->render('quick_fix/locked.html.twig', [
                'token_required' => $guard->isTokenRequired(),
            ], new Response('', Response::HTTP_FORBIDDEN));
        }

        // If a token was supplied via ?token= and matches, persist it as a
        // cookie so the user does not need to keep the URL parameter on the
        // POST submission. Lifetime: session.
        $cookieToSet = null;
        if ($request->query->has('token') && $guard->mayAccess($request)) {
            $cookieToSet = (string) $request->query->get('token');
        }

        $pendingCount = 0;
        $driftCount = 0;
        $driftStatements = [];
        $driftDestructive = [];
        $errorMessage = null;
        try {
            $status = $maintenance->getMaintenanceStatus();
            $pendingCount = (int) ($status['migration_status']['pending'] ?? 0);
            $driftCount = (int) ($status['schema_drift']['count'] ?? 0);
            $driftStatements = $status['schema_drift']['statements'] ?? [];
            $driftDestructive = $status['schema_drift']['destructive'] ?? [];
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
        }

        $response = $this->render('quick_fix/index.html.twig', [
            'pending_count' => $pendingCount,
            'drift_count' => $driftCount,
            'drift_statements' => $driftStatements,
            'drift_destructive' => $driftDestructive,
            'error_message' => $errorMessage,
        ]);

        if ($cookieToSet !== null) {
            $response->headers->setCookie(
                \Symfony\Component\HttpFoundation\Cookie::create('quick_fix_token')
                    ->withValue($cookieToSet)
                    ->withHttpOnly(true)
                    ->withSecure($request->isSecure())
                    ->withSameSite('Lax'),
            );
        }

        return $response;
    }

    #[IsCsrfTokenValid('quick_fix_apply')]
    public function apply(
        Request $request,
        QuickFixGuard $guard,
        SchemaMaintenanceService $maintenance,
    ): Response {
        if (!$guard->mayAccess($request)) {
            return $this->render('quick_fix/locked.html.twig', [
                'token_required' => $guard->isTokenRequired(),
            ], new Response('', Response::HTTP_FORBIDDEN));
        }

        $result = $maintenance->executePendingMigrations('quick-fix');

        if (!$result['success']) {
            $this->addFlash('error', sprintf('Migration failed: %s', $result['error'] ?? 'unknown error'));
            return new RedirectResponse($this->generateUrl('app_quick_fix_index'));
        }

        $this->addFlash('success', sprintf('%d Migration(en) erfolgreich angewendet.', $result['executed']));
        return new RedirectResponse($this->generateUrl('app_quick_fix_index', ['fixed' => 1]));
    }

    #[IsCsrfTokenValid('quick_fix_reconcile')]
    public function reconcile(
        Request $request,
        QuickFixGuard $guard,
        SchemaMaintenanceService $maintenance,
    ): Response {
        if (!$guard->mayAccess($request)) {
            return $this->render('quick_fix/locked.html.twig', [
                'token_required' => $guard->isTokenRequired(),
            ], new Response('', Response::HTTP_FORBIDDEN));
        }

        // Refuse when destructive drift exists — those need manual review.
        try {
            $status = $maintenance->getMaintenanceStatus();
            $destructive = $status['schema_drift']['destructive'] ?? [];
        } catch (\Throwable $e) {
            $this->addFlash('error', sprintf('Status-Abfrage fehlgeschlagen: %s', $e->getMessage()));
            return new RedirectResponse($this->generateUrl('app_quick_fix_index'));
        }

        if ($destructive !== []) {
            $this->addFlash('error', 'Destruktive Schema-Änderungen erkannt. Bitte manuell prüfen via CLI: php bin/console app:schema:reconcile');
            return new RedirectResponse($this->generateUrl('app_quick_fix_index'));
        }

        $result = $maintenance->reconcileSchema('quick-fix');

        if (!$result['success']) {
            $this->addFlash('error', sprintf('Schema-Reconcile fehlgeschlagen: %s', $result['error'] ?? ($result['blocked'] ?? 'unbekannter Fehler')));
            return new RedirectResponse($this->generateUrl('app_quick_fix_index'));
        }

        $this->addFlash('success', sprintf('%d Schema-Statement(s) erfolgreich angewendet.', $result['executed']));
        return new RedirectResponse($this->generateUrl('app_quick_fix_index', ['fixed' => 1]));
    }
}
