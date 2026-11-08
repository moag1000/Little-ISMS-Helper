<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresModule;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Security\Voter\RecordResponsibleVoter;
use App\Service\Compliance\Fulfillment\FulfillmentDerivationService;
use App\Service\TenantContext;
use App\Util\InputDate;
use App\Util\ListPage;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Hand values next to a fulfilment rule (Paket 3): set an override with
 * reason and review date, hand a requirement back to its rule, and the list
 * "Übersteuerungen prüfen" (legacy values from before the rule, certificates,
 * overrides whose review date has come).
 *
 * Reading the list is open to reviewers (auditors see every override with its
 * reason); changing a value needs ROLE_MANAGER. Confirming the periodic review
 * of a fulfilment (`nextReviewDate`, deadline type `fulfillment_review`) is
 * open to managers and to whoever that reminder reaches.
 */
#[RequiresModule('compliance')]
final class FulfillmentOverrideController extends AbstractController
{
    private const MIN_REASON_LENGTH = 10;
    private const FILTERS = ['legacy', 'due', 'manual', 'certificate', 'inheritance'];
    /** Default cadence of the periodic fulfilment review (ISO 27001 9.1: at planned intervals). */
    private const int REVIEW_CADENCE_MONTHS = 12;

    public function __construct(
        private readonly ComplianceRequirementFulfillmentRepository $fulfillmentRepository,
        private readonly FulfillmentDerivationService $derivation,
        private readonly TenantContext $tenantContext,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/compliance/fulfillment/overrides', name: 'app_compliance_fulfillment_overrides', methods: ['GET'])]
    #[IsGranted('ROLE_AUDITOR')]
    public function index(Request $request): Response
    {
        $tenant = $this->requireTenant();
        $filter = (string) $request->query->get('filter', '');
        $filter = in_array($filter, self::FILTERS, true) ? $filter : '';

        $all = $this->fulfillmentRepository->findOverrides($tenant);
        $counts = ['legacy' => 0, 'due' => 0];
        foreach ($all as $f) {
            $counts['legacy'] += $f->getOverrideSource() === ComplianceRequirementFulfillment::OVERRIDE_LEGACY ? 1 : 0;
            $counts['due'] += $f->isOverrideReviewDue() ? 1 : 0;
        }

        $rows = array_values(array_filter($all, static fn (ComplianceRequirementFulfillment $f): bool => match ($filter) {
            '' => true,
            'due' => $f->isOverrideReviewDue(),
            default => $f->getOverrideSource() === $filter,
        }));

        return $this->render('compliance/fulfillment/overrides.html.twig', [
            'page' => ListPage::fromRequest($rows, $request, static fn (ComplianceRequirementFulfillment $f): array => [
                (string) $f->getRequirement()?->getRequirementId(),
                (string) $f->getRequirement()?->getTitle(),
                (string) $f->getRequirement()?->getFramework()?->getCode(),
                (string) $f->getOverrideReason(),
            ]),
            'filter' => $filter,
            'filters' => self::FILTERS,
            'counts' => $counts,
            'legacyWithRule' => count(array_filter($all, static fn (ComplianceRequirementFulfillment $f): bool =>
                $f->getOverrideSource() === ComplianceRequirementFulfillment::OVERRIDE_LEGACY && $f->getDerivedPercentage() !== null)),
        ]);
    }

    #[Route('/compliance/requirement/{id}/fulfillment/override', name: 'app_compliance_fulfillment_override', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    #[IsCsrfTokenValid(new Expression('"fulfillment_override_" ~ args["requirement"].getId()'))]
    public function override(Request $request, ComplianceRequirement $requirement): Response
    {
        $fulfillment = $this->ownFulfillment($requirement);
        $reason = trim((string) $request->request->get('reason', ''));
        $reviewDue = InputDate::ymd($request->request->get('review_due'));
        $percentage = $request->request->get('percentage');

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH || !is_numeric($percentage)
            || !$reviewDue instanceof \DateTime || $reviewDue < new \DateTime('today')) {
            $this->addFlash('warning', $this->translator->trans('compliance.fulfillment_rule.flash.override_invalid', ['%min%' => self::MIN_REASON_LENGTH], 'compliance'));

            return $this->backToRequirement($requirement);
        }

        $user = $this->getUser();
        $this->derivation->override(
            $fulfillment,
            max(0, min(100, (int) $percentage)),
            $reason,
            DateTimeImmutable::createFromMutable($reviewDue),
            $user instanceof User ? $user : null,
        );
        $this->derivation->save($fulfillment);
        $this->addFlash('success', $this->translator->trans('compliance.fulfillment_rule.flash.overridden', [], 'compliance'));

        return $this->backToRequirement($requirement);
    }

    #[Route('/compliance/requirement/{id}/fulfillment/follow-rule', name: 'app_compliance_fulfillment_follow_rule', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    #[IsCsrfTokenValid(new Expression('"fulfillment_follow_rule_" ~ args["requirement"].getId()'))]
    public function followRule(Request $request, ComplianceRequirement $requirement): Response
    {
        $fulfillment = $this->ownFulfillment($requirement);
        $user = $this->getUser();
        $this->derivation->clearOverride($fulfillment, $user instanceof User ? $user : null);
        $this->derivation->save($fulfillment);
        $this->addFlash('success', $this->translator->trans('compliance.fulfillment_rule.flash.follows_rule', [], 'compliance'));

        $back = (string) $request->request->get('back', '');

        return $back === 'list'
            ? $this->redirectToRoute('app_compliance_fulfillment_overrides', array_filter(['filter' => (string) $request->request->get('filter', '')]))
            : $this->backToRequirement($requirement);
    }

    /**
     * "Review bestätigen" for one fulfilment (ISO 27001 9.1): the reminder of
     * `fulfillment_review` links to the requirement page, its responsible
     * person confirms there. Only the review dates change — the value stays
     * with FulfillmentDerivationService. Next review in twelve months, or the
     * date the person picks.
     */
    #[Route('/compliance/requirement/{id}/fulfillment/review', name: 'app_compliance_fulfillment_review', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[IsCsrfTokenValid(new Expression('"fulfillment_review_" ~ args["requirement"].getId()'))]
    public function confirmReview(Request $request, ComplianceRequirement $requirement): Response
    {
        $fulfillment = $this->ownFulfillment($requirement);
        if (!$this->isGranted('ROLE_MANAGER') && !$this->isGranted(RecordResponsibleVoter::RESPONSIBLE, $fulfillment)) {
            throw $this->createAccessDeniedException();
        }
        $today = new DateTimeImmutable('today');
        $next = InputDate::ymd($request->request->get('next_review'));
        $next = $next instanceof \DateTime && $next > new \DateTime('today')
            ? DateTimeImmutable::createFromMutable($next)
            : $today->modify(sprintf('+%d months', self::REVIEW_CADENCE_MONTHS));
        $fulfillment->setLastReviewDate($today);
        $fulfillment->setNextReviewDate($next);
        $this->derivation->save($fulfillment);
        $this->addFlash('success', 'compliance.fulfillment_rule.flash.review_confirmed');

        return $this->backToRequirement($requirement);
    }

    /** Every legacy value that a rule now computes goes back to its rule. */
    #[Route('/compliance/fulfillment/overrides/follow-rule', name: 'app_compliance_fulfillment_follow_rule_all', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    #[IsCsrfTokenValid('fulfillment_follow_rule_all')]
    public function followRuleAll(): Response
    {
        $tenant = $this->requireTenant();
        $user = $this->getUser();
        $count = 0;
        foreach ($this->fulfillmentRepository->findOverrides($tenant, ComplianceRequirementFulfillment::OVERRIDE_LEGACY) as $f) {
            if ($f->getDerivedPercentage() === null) {
                continue;
            }
            $this->derivation->clearOverride($f, $user instanceof User ? $user : null);
            $count++;
        }
        $this->derivation->flush();
        $this->addFlash('success', $this->translator->trans('compliance.fulfillment_rule.flash.follows_rule_all', ['%count%' => $count], 'compliance'));

        return $this->redirectToRoute('app_compliance_fulfillment_overrides');
    }

    private function requireTenant(): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            throw $this->createAccessDeniedException();
        }

        return $tenant;
    }

    /** The current organisation's own fulfilment of a requirement it can see. */
    private function ownFulfillment(ComplianceRequirement $requirement): ComplianceRequirementFulfillment
    {
        $tenant = $this->requireTenant();
        $owner = $requirement->getUploadTenant();
        if ($owner !== null && $owner->getId() !== $tenant->getId()) {
            throw $this->createNotFoundException();
        }

        $fulfillment = $this->fulfillmentRepository->findOrCreateForTenantAndRequirement($tenant, $requirement);
        if ($fulfillment->getDerivedAt() === null) {
            $this->derivation->apply($fulfillment);
        }

        return $fulfillment;
    }

    private function backToRequirement(ComplianceRequirement $requirement): Response
    {
        return $this->redirectToRoute('app_compliance_requirement_show', ['id' => $requirement->getId()]);
    }
}
