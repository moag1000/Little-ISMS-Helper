<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AuditLogger;
use App\Service\QuickFixGuard;
use App\Workflow\Mirror\RegulatoryWorkflowMirror;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * Quick-Fix action "Workflow-Definitionen abgleichen" (S17): writes the database rows of the
 * regulatory approval chains from config/workflows/regulatory/*.yaml, so a changed approver role
 * reaches the running system without a shell. Same call as `app:generate-regulatory-workflows`
 * and the nightly scheduler task. Rows are updated in place; running approvals keep their step.
 *
 * Like the other Quick-Fix writes: super admin or installer token only ({@see QuickFixGuard}).
 */
final class QuickFixWorkflowController extends AbstractController
{
    #[Route('/quick-fix/workflows/sync', name: 'app_quick_fix_workflows_sync', methods: ['POST'])]
    #[IsCsrfTokenValid('quick_fix_workflows_sync')]
    public function sync(
        Request $request,
        QuickFixGuard $guard,
        RegulatoryWorkflowMirror $mirror,
        AuditLogger $auditLogger,
    ): Response {
        if (!$guard->mayRunPrivileged($request)) {
            $privilegedOnly = $guard->mayAccess($request);

            return $this->render('quick_fix/locked.html.twig', [
                'token_required' => $guard->isTokenRequired() || $privilegedOnly,
                'privileged_only' => $privilegedOnly,
            ], new Response('', Response::HTTP_FORBIDDEN));
        }

        $drift = $mirror->drift();
        $result = $mirror->sync();

        $auditLogger->logCustom(
            'quick_fix.workflows_synced',
            'Workflow',
            null,
            ['drift' => array_map(static fn (array $entry): string => $entry['workflow'] . ':' . $entry['state'], $drift)],
            $result,
            sprintf(
                'QuickFix: workflow definitions aligned — %d created, %d updated',
                $result['created'],
                $result['updated'],
            ),
        );

        $this->addFlash('success', 'quick_fix.workflows.flash.synced');
        if ($result['kept_steps'] > 0) {
            $this->addFlash('warning', 'quick_fix.workflows.flash.kept_steps');
        }

        return $this->redirectToRoute('app_quick_fix_index');
    }
}
