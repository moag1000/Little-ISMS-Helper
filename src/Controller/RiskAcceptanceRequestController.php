<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\LocalizedFlashTrait;
use App\Entity\Risk;
use App\Entity\User;
use App\Enum\TreatmentStrategy;
use App\Security\Voter\RecordResponsibleVoter;
use App\Service\RiskAcceptanceWorkflowService;
use App\Util\InputDate;
use DateTime;
use DateTimeImmutable;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the risk owner decides about a risk outside the edit form (S12): the
 * acceptance request with reason and expiry (ISO 27005 8.4.4, ISO 27001 8.3)
 * and the approval of the treatment decision (ISO 27001 6.1.3 f). The form
 * shows these fields read-only — typing them there faked a pending request,
 * moved the expiry of a granted acceptance or named any user as approver.
 */
final class RiskAcceptanceRequestController extends AbstractController
{
    use LocalizedFlashTrait;

    public function __construct(
        private readonly RiskAcceptanceWorkflowService $riskAcceptanceWorkflowService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    protected function getFlashDomain(): string
    {
        return 'risk';
    }

    protected function getTranslator(): TranslatorInterface
    {
        return $this->translator;
    }

    /**
     * Request formal risk acceptance (ISO 27005 8.4.4). On a risk that is
     * already accepted this is the re-evaluation / extension: the standing
     * acceptance ends and the new request goes to the approver of its level.
     */
    #[Route('/risk/{id}/request-acceptance', name: 'app_risk_request_acceptance', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(new Expression(RecordResponsibleVoter::MANAGER_OR_RESPONSIBLE), subject: 'risk')]
    public function requestAcceptance(Request $request, Risk $risk): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if ($risk->getTreatmentStrategy() !== TreatmentStrategy::Accept) {
            $this->flashError('risk.acceptance.error.wrong_strategy');

            return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
        }
        $reevaluation = $risk->isFormallyAccepted();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('request-acceptance' . $risk->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('error', $this->translator->trans('security.csrf_token_invalid', [], 'messages'));

                return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
            }

            $justification = trim((string) $request->request->get('justification', ''));
            $expiry = InputDate::ymd($request->request->get('expiry_date'));
            if ($justification === '') {
                $this->flashError('risk.acceptance.error.justification_required');
            } elseif (!$expiry instanceof DateTime || !self::expiryAllowed($expiry)) {
                $this->flashError('risk.acceptance.error.expiry_invalid', ['%months%' => RiskAcceptanceWorkflowService::MAX_ACCEPTANCE_MONTHS]);
            } else {
                try {
                    $result = $reevaluation
                        ? $this->riskAcceptanceWorkflowService->requestReevaluation($risk, $user, $justification, $expiry)
                        : $this->riskAcceptanceWorkflowService->requestAcceptance($risk, $user, $justification, $expiry);

                    if ($result['status'] === 'accepted') {
                        $this->flashSuccess('risk.acceptance.success.auto_accepted');
                    } else {
                        $this->flashSuccess(
                            'risk.acceptance.success.approval_requested',
                            ['%approver%' => $result['approver'], '%level%' => $result['approval_level']]
                        );
                    }

                    return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
                } catch (DomainException $e) {
                    $this->addFlash('error', $e->getMessage());
                }
            }
        }

        return $this->render('risk/request_acceptance.html.twig', [
            'risk' => $risk,
            'thresholds' => $this->riskAcceptanceWorkflowService->getApprovalThresholds($risk),
            'required_level' => $this->riskAcceptanceWorkflowService->determineApprovalLevel($risk),
            'reevaluation' => $reevaluation,
            'default_expiry' => (new DateTimeImmutable('today'))->modify('+12 months'),
            'max_months' => RiskAcceptanceWorkflowService::MAX_ACCEPTANCE_MONTHS,
            'max_expiry' => (new DateTimeImmutable('today'))->modify(sprintf('+%d months', RiskAcceptanceWorkflowService::MAX_ACCEPTANCE_MONTHS)),
        ]);
    }

    /**
     * ISO 27001 6.1.3 f: the risk owner (or a manager) approves the treatment
     * decision; who and when come from the login, never from a picker.
     */
    #[Route('/risk/{id}/approve-decision', name: 'app_risk_approve_decision', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(new Expression(RecordResponsibleVoter::MANAGER_OR_RESPONSIBLE), subject: 'risk')]
    #[IsCsrfTokenValid(new Expression('"risk_approve_decision_" ~ args["risk"].getId()'))]
    public function approveDecision(Request $request, Risk $risk): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if ($risk->getTreatmentStrategy() === null || trim((string) $risk->getDecisionRationale()) === '') {
            $this->flashError('risk.decision.error.rationale_required');

            return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
        }

        $this->riskAcceptanceWorkflowService->approveTreatmentDecision($risk, $user, (string) $request->request->get('comment', ''));
        $this->flashSuccess('risk.decision.flash.approved');

        return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId(), '_fragment' => 'treatment']);
    }

    private static function expiryAllowed(DateTime $expiry): bool
    {
        $today = new DateTime('today');
        $max = (clone $today)->modify(sprintf('+%d months', RiskAcceptanceWorkflowService::MAX_ACCEPTANCE_MONTHS));

        return $expiry > $today && $expiry <= $max;
    }
}
