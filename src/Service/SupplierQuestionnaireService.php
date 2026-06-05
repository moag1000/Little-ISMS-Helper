<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Supplier;
use App\Entity\SupplierQuestionnaire;
use App\Entity\Tenant;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * F23 — outbound supplier questionnaire lifecycle: create + send + record the
 * supplier's response. The public answer surface is token-gated (see
 * {@see \App\Controller\PublicSupplierQuestionnaireController}).
 */
final class SupplierQuestionnaireService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Create a questionnaire in DRAFT and immediately mark it SENT (a signed
     * link is the delivery channel). Returns the persisted questionnaire.
     *
     * @param list<array{id: string, text: string}> $questions
     */
    public function createAndSend(Tenant $tenant, Supplier $supplier, string $title, array $questions): SupplierQuestionnaire
    {
        $q = new SupplierQuestionnaire();
        $q->setTenant($tenant);
        $q->setSupplier($supplier);
        $q->setTitle($title !== '' ? $title : 'Security questionnaire');
        $q->setQuestions($questions);
        $q->setPublicToken(bin2hex(random_bytes(32)));
        $q->setStatus(SupplierQuestionnaire::STATUS_SENT);
        $q->setSentAt(new DateTimeImmutable());

        $this->entityManager->persist($q);
        $this->entityManager->flush();

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
        $questionnaire->setStatus(SupplierQuestionnaire::STATUS_COMPLETED);
        $questionnaire->setCompletedAt(new DateTimeImmutable());
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            action: 'supplier_questionnaire.completed',
            entityType: 'SupplierQuestionnaire',
            entityId: $questionnaire->getId(),
            description: sprintf('Supplier completed questionnaire "%s"', $questionnaire->getTitle()),
        );

        return true;
    }
}
