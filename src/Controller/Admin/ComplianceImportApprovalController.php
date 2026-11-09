<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Security\Voter\TenantScopedAdminVoter;
use App\Service\Import\ImportCommitApproval;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Freigabe anfordern" in the compliance import preview (W9): from the
 * organisation's row threshold the commit needs a second person's approval
 * of exactly this file ({@see ImportCommitApproval}). The request lands in the
 * approver's four-eyes inbox and on My Day.
 */
#[IsGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT)]
final class ComplianceImportApprovalController extends AbstractController
{
    public function __construct(
        private readonly ImportCommitApproval $commitApproval,
    ) {
    }

    #[Route('/admin/import/compliance/request-approval', name: 'admin_compliance_import_request_approval', methods: ['POST'])]
    #[IsCsrfTokenValid('compliance_import_four_eyes')]
    public function request(Request $request): Response
    {
        $session = $request->getSession()->get(ComplianceImportController::SESSION_KEY);
        if (!is_array($session) || !is_file($session['stored_path'] ?? '') || !isset($session['row_count'])) {
            $this->addFlash('warning', 'compliance_import.flash.no_session');

            return $this->redirectToRoute('admin_compliance_import_upload');
        }
        $path = (string) $session['stored_path'];
        $rows = (int) $session['row_count'];
        if ($this->commitApproval->state($path, $rows) === ImportCommitApproval::STATE_MISSING) {
            /** @var User $user */
            $user = $this->getUser();
            $this->commitApproval->request($path, $rows, (string) ($session['original_name'] ?? ''), $user);
            $this->addFlash('success', 'compliance_import.flash.four_eyes_requested');
        }

        return $this->redirectToRoute('admin_compliance_import_preview');
    }
}
