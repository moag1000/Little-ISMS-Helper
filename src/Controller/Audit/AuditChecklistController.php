<?php

declare(strict_types=1);

namespace App\Controller\Audit;

use App\Entity\AuditChecklist;
use App\Entity\InternalAudit;
use App\Entity\User;
use App\Repository\AuditChecklistRepository;
use App\Security\Voter\AuditWorkVoter;
use App\Service\Audit\AuditChecklistGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The audit checklist (ISO 27001 9.2.2): built from the audit's scope with
 * the organisation's evidence listed, and each verdict feeds the
 * requirement's fulfilment ({@see \App\Service\Compliance\Fulfillment\AuditResultRule}).
 */
final class AuditChecklistController extends AbstractController
{
    private const array LOCKED_STATUSES = ['reported', 'approved', 'closed'];

    /**
     * S20/S27 (ISO 27001 9.2.2 objectivity): verdicts come from the audit's lead auditor and team
     * (RECORD_RESPONSIBLE of the audit), an auditor or a manager — never from anybody of the
     * organisation, and not from top management or the group roles, which inherit ROLE_AUDITOR
     * only for reading ({@see AuditWorkVoter}).
     */
    public const string AUDIT_WORK = 'is_granted("' . AuditWorkVoter::AUDIT_WORK . '", subject)';
    private const array STATUSES = ['not_checked', 'compliant', 'partial', 'non_compliant', 'not_applicable'];

    public function __construct(
        private readonly AuditChecklistRepository $checklists,
        private readonly AuditChecklistGenerator $generator,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/audit/{id}/checklist', name: 'app_audit_checklist', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function checklist(InternalAudit $internalAudit): Response
    {
        $items = $this->checklists->findByAudit($internalAudit);
        $locked = self::isLocked($internalAudit);
        if ($items === [] && !$locked && $this->generator->generate($internalAudit) > 0) {
            $items = $this->checklists->findByAudit($internalAudit); // first visit: built from the scope
        }

        $grouped = [];
        $stats = ['total' => count($items), 'verified' => 0, 'compliant' => 0, 'partial' => 0, 'non_compliant' => 0, 'not_applicable' => 0];
        foreach ($items as $item) {
            $grouped[$item->getRequirement()?->getFramework()?->getName() ?? 'general'][] = $item;
            $status = $item->getVerificationStatus();
            if ($status !== 'not_checked') {
                $stats['verified']++;
            }
            if (isset($stats[$status])) {
                $stats[$status]++;
            }
        }

        return $this->render('audit/checklist.html.twig', [
            'audit' => $internalAudit,
            'checklist_items' => $items,
            'grouped_items' => $grouped,
            'stats' => $stats,
            // read-only for whoever may not judge (S20): no save or generate button
            'locked' => $locked || !$this->isGranted(new Expression(self::AUDIT_WORK), $internalAudit),
            'frameworks' => $this->generator->frameworksFor($internalAudit),
        ]);
    }

    /** After the scope changed: adds the items of new requirements, keeps every verdict. */
    #[Route('/audit/{id}/checklist/generate', name: 'app_audit_checklist_generate', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(new Expression(self::AUDIT_WORK), subject: 'internalAudit')]
    #[IsCsrfTokenValid(new Expression('"audit_checklist_generate_" ~ args["internalAudit"].getId()'))]
    public function generate(InternalAudit $internalAudit): Response
    {
        if (self::isLocked($internalAudit)) {
            $this->addFlash('warning', 'checklist.flash.locked');
        } else {
            $this->addFlash('success', $this->translator->trans(
                'checklist.flash.generated',
                ['%count%' => $this->generator->generate($internalAudit)],
                'audit',
            ));
        }

        return $this->redirectToRoute('app_audit_checklist', ['id' => $internalAudit->getId()]);
    }

    #[Route('/audit/{id}/checklist/save', name: 'app_audit_checklist_save', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(new Expression(self::AUDIT_WORK), subject: 'internalAudit')]
    public function checklistSave(Request $request, InternalAudit $internalAudit): Response
    {
        $data = json_decode($request->getContent(), true) ?? [];
        if (!isset($data['_token']) || !$this->isCsrfTokenValid('audit_checklist_save_' . $internalAudit->getId(), (string) $data['_token'])) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        if (self::isLocked($internalAudit)) {
            return new JsonResponse(['error' => $this->translator->trans('checklist.flash.locked', [], 'audit')], Response::HTTP_CONFLICT);
        }

        $user = $this->getUser();
        $auditor = $user instanceof User ? ($user->getFullName() ?: (string) $user->getEmail()) : '';
        $updated = 0;
        foreach (is_array($data['items'] ?? null) ? $data['items'] : [] as $itemData) {
            $item = is_array($itemData) ? $this->checklists->find((int) ($itemData['id'] ?? 0)) : null;
            if (!$item instanceof AuditChecklist || $item->getAudit()?->getId() !== $internalAudit->getId()) {
                continue;
            }
            $status = $itemData['verificationStatus'] ?? null;
            if (in_array($status, self::STATUSES, true) && $status !== $item->getVerificationStatus()) {
                $item->setVerificationStatus($status);
                // the verdict and its date feed the requirement's fulfilment
                $status === 'not_checked' ? $item->setVerifiedAt(null) : $item->markAsVerified((string) $auditor);
            }
            foreach (['auditNotes', 'evidenceFound', 'findings', 'recommendations'] as $field) {
                if (isset($itemData[$field])) {
                    $item->{'set' . ucfirst($field)}((string) $itemData[$field]);
                }
            }
            if (isset($itemData['complianceScore'])) {
                $item->setComplianceScore(max(0, min(100, (int) $itemData['complianceScore'])));
            }
            $item->setUpdatedAt(new \DateTimeImmutable());
            $updated++;
        }
        $this->entityManager->flush();

        return new JsonResponse(['saved' => $updated, 'message' => $this->translator->trans('checklist.flash.saved', ['%count%' => $updated], 'audit')]);
    }

    private static function isLocked(InternalAudit $audit): bool
    {
        return in_array($audit->getStatus(), self::LOCKED_STATUSES, true);
    }
}
