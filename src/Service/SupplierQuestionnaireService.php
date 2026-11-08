<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Supplier;
use App\Entity\SupplierQuestionnaire;
use App\Entity\Tenant;
use App\Entity\User;
use App\Lifecycle\LifecycleTransitionInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Outbound supplier questionnaire lifecycle: create + send + record the
 * supplier's response + review it into the supplier's assessment. The public answer surface is token-gated (see
 * {@see \App\Controller\PublicSupplierQuestionnaireController}).
 */
final class SupplierQuestionnaireService
{
    private const string WORKFLOW = 'supplier_questionnaire_lifecycle';

    /** A reviewed questionnaire counts as the supplier assessment for a year. */
    public const int REASSESSMENT_MONTHS = 12;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly LifecycleTransitionInterface $lifecycle,
    ) {
    }

    /**
     * Create a questionnaire in DRAFT and immediately mark it SENT (a signed
     * link is the delivery channel). Returns the persisted questionnaire.
     *
     * @param list<array{id: string, text: string}> $questions
     */
    public function createAndSend(
        Tenant $tenant,
        Supplier $supplier,
        string $title,
        array $questions,
        ?User $sender = null,
    ): SupplierQuestionnaire
    {
        $q = new SupplierQuestionnaire();
        $q->setTenant($tenant);
        $q->setSupplier($supplier);
        $q->setTitle($title !== '' ? $title : 'Security questionnaire');
        $q->setQuestions($questions);
        $q->setPublicToken(bin2hex(random_bytes(32)));

        // Persisted at the initial marking (`draft`), then moved by the
        // `send` transition — LifecycleService flushes.
        $this->entityManager->persist($q);
        $this->entityManager->flush();
        $q->setSentAt(new DateTimeImmutable());
        $this->lifecycle->transition($q, self::WORKFLOW, 'send', $sender);

        $this->auditLogger->logCustom(
            action: 'supplier_questionnaire.sent',
            entityType: 'SupplierQuestionnaire',
            entityId: $q->getId(),
            description: sprintf('Questionnaire "%s" sent to supplier %s', $q->getTitle(), (string) $supplier->getName()),
        );

        return $q;
    }

    /**
     * Record the supplier's answers (public submission). Idempotent-safe: only
     * accepts a response while the questionnaire is open.
     *
     * @param array<string, string> $answers questionId => answerText
     */
    public function submitResponse(SupplierQuestionnaire $questionnaire, array $answers): bool
    {
        if (!$questionnaire->isOpenForResponse()) {
            return false;
        }

        // Keep only answers for known question ids (ignore injected keys).
        $validIds = array_column($questionnaire->getQuestions(), 'id');
        $clean = [];
        foreach ($answers as $qid => $text) {
            if (in_array($qid, $validIds, true)) {
                $clean[$qid] = mb_substr((string) $text, 0, 5000);
            }
        }

        $questionnaire->setAnswers($clean);
        $questionnaire->setCompletedAt(new DateTimeImmutable());
        // sent → completed. The caller (public, token-gated controller) has
        // set the questionnaire's tenant as current tenant for the guard.
        $this->lifecycle->transition($questionnaire, self::WORKFLOW, 'complete');

        $this->auditLogger->logCustom(
            action: 'supplier_questionnaire.completed',
            entityType: 'SupplierQuestionnaire',
            entityId: $questionnaire->getId(),
            description: sprintf('Supplier completed questionnaire "%s"', $questionnaire->getTitle()),
        );

        return true;
    }

    /**
     * The reviewer's verdict on the supplier's answers (ISO 27001 A.5.19–5.22,
     * DORA Art. 28): completed → reviewed, and the supplier's security
     * assessment takes it over — score, findings, assessed today, next
     * assessment in a year. That date is what the supplier assessment
     * reminder counts from, so the next due date follows without extra input.
     */
    public function review(SupplierQuestionnaire $questionnaire, int $score, ?string $findings, ?User $reviewer): void
    {
        $this->lifecycle->transition($questionnaire, self::WORKFLOW, 'review', $reviewer);

        $supplier = $questionnaire->getSupplier();
        if ($supplier instanceof Supplier) {
            $today = new DateTimeImmutable('today');
            $supplier->setSecurityScore(max(0, min(100, $score)));
            $supplier->setLastSecurityAssessment($today);
            $supplier->setNextAssessmentDate($today->modify('+' . self::REASSESSMENT_MONTHS . ' months'));
            $findings = trim((string) $findings);
            if ($findings !== '') {
                $supplier->setAssessmentFindings(mb_substr($findings, 0, 5000));
            }
        }
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            action: 'supplier_questionnaire.reviewed',
            entityType: 'SupplierQuestionnaire',
            entityId: $questionnaire->getId(),
            description: sprintf('Questionnaire "%s" reviewed, supplier score %d', $questionnaire->getTitle(), $score),
        );
    }
}
