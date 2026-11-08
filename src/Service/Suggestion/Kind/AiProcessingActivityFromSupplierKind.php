<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\ProcessingActivity;
use App\Entity\Suggestion;
use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ProcessingActivityRepository;
use App\Repository\SuggestionRepository;
use App\Repository\SupplierRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Suggestion\SectionedText;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Data protection officer (GDPR Art. 30, Art. 28): a supplier with a signed
 * processing agreement (AVV) that no record of processing activities names as
 * processor → the model drafts the VVT entry: purpose, data subjects, data
 * categories, retention. Accepting creates the processing activity in status
 * "draft" with the supplier as processor; legal basis defaults to contract
 * (Art. 6 (1) b) and every other field stays with the DPO.
 */
final class AiProcessingActivityFromSupplierKind extends AbstractAiKind
{
    public const string KEY = 'ai.vvt_from_supplier';

    /** Allowed values, as the processing activity form offers them. */
    private const array SUBJECTS = ['customers', 'employees', 'applicants', 'suppliers', 'visitors', 'subscribers', 'patients', 'students', 'other'];
    private const array DATA = ['identification', 'contact', 'financial', 'location', 'online_identifiers', 'employment', 'educational', 'contract', 'communication', 'usage'];
    private const array PURPOSES = ['contract_fulfillment', 'marketing', 'legal_obligation', 'crm', 'hr_management', 'accounting', 'it_security', 'quality_assurance', 'research', 'other'];
    private const array LEGAL_BASES = ['consent', 'contract', 'legal_obligation', 'vital_interests', 'public_task', 'legitimate_interests'];

    /** Field => translation key of its block heading. */
    private const array FIELDS = [
        'description' => 'suggestions.text.ai_vvt.purpose',
        'retentionPeriod' => 'suggestions.text.ai_vvt.retention',
    ];

    public function __construct(
        AiAssistant $ai,
        SuggestionRepository $suggestions,
        private readonly SupplierRepository $suppliers,
        private readonly ProcessingActivityRepository $activities,
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionText $text,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($ai, $suggestions);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function decisionRoles(): array
    {
        return ['ROLE_DPO'];
    }

    public function requiredModule(): string
    {
        return 'privacy';
    }

    public function subjectClasses(): array
    {
        return [Supplier::class];
    }

    protected function candidates(Tenant $tenant): iterable
    {
        foreach ($this->suppliers->findByTenant($tenant) as $supplier) {
            if ($this->isCandidate($supplier)) {
                yield $supplier;
            }
        }
    }

    protected function isCandidate(object $record): bool
    {
        return $record instanceof Supplier
            && $record->isHasDPA()
            && !in_array($record->getStatus(), ['terminated', 'inactive'], true)
            && mb_strlen(trim($record->getServiceProvided() . ' ' . $record->getDescription())) >= 10
            && $this->activities->findByProcessorSupplier($record) === [];
    }

    protected function instructions(): string
    {
        return 'Draft the record of processing activities entry (GDPR Art. 30 (1)) for the processing this supplier '
            . 'carries out as processor under a data processing agreement (Art. 28). Use only what the service '
            . 'description supports; where unsure choose the narrower category. Return keys '
            . '"name" (max 100 chars, e.g. "Payroll by <supplier>"), "purpose" (what is processed and why, 2-3 sentences), '
            . '"purposes" (list from: ' . implode(', ', self::PURPOSES) . '), '
            . '"data_subjects" (list from: ' . implode(', ', self::SUBJECTS) . '), '
            . '"data_categories" (list from: ' . implode(', ', self::DATA) . '), '
            . '"retention" (how long the data is kept and why, 1-2 sentences; "not yet known" if unclear), '
            . '"legal_basis" (one of: ' . implode(', ', self::LEGAL_BASES) . ').';
    }

    protected function describe(object $record): string
    {
        \assert($record instanceof Supplier);

        return implode("\n", array_filter([
            'Supplier: ' . $record->getName(),
            $record->getServiceProvided() ? 'Service provided: ' . $record->getServiceProvided() : null,
            $record->getDescription() ? 'Description: ' . $record->getDescription() : null,
            $record->getCriticality() ? 'Criticality: ' . $record->getCriticality() : null,
            $record->getGdprProcessorStatus() ? 'GDPR role: ' . $record->getGdprProcessorStatus() : null,
            $record->getCountryOfHeadOffice() ? 'Head office country: ' . $record->getCountryOfHeadOffice() : null,
            $record->getDpaSignedDate() ? 'Processing agreement signed: ' . $record->getDpaSignedDate()->format('Y-m-d') : null,
        ]));
    }

    protected function toDraft(object $record, array $data, string $model, Tenant $tenant): ?SuggestionDraft
    {
        \assert($record instanceof Supplier);
        $purpose = self::text($data, 'purpose');
        $subjects = self::choices($data, 'data_subjects', self::SUBJECTS);
        $categories = self::choices($data, 'data_categories', self::DATA);
        if ($purpose === '' || $subjects === [] || $categories === []) {
            return null;
        }
        $purposes = self::choices($data, 'purposes', self::PURPOSES) ?: ['contract_fulfillment'];
        $retention = self::text($data, 'retention', 2000);
        $basis = self::text($data, 'legal_basis', 40);
        $name = self::text($data, 'name', 255) ?: 'VVT: ' . $record->getName();
        $sections = [$this->text->t($tenant, self::FIELDS['description']) => $purpose];
        if ($retention !== '') {
            $sections[$this->text->t($tenant, self::FIELDS['retentionPeriod'])] = $retention;
        }

        return new SuggestionDraft(
            subject: $record,
            title: $this->text->t($tenant, 'suggestions.text.ai_vvt.title', ['%supplier%' => (string) $record->getName()]),
            rationale: $this->text->t($tenant, 'suggestions.text.ai_vvt.rationale', [
                '%subjects%' => $this->labels($tenant, 'processing_activity.data_subject.', $subjects),
                '%data%' => $this->labels($tenant, 'processing_activity.personal_data.', $categories),
                '%reasoning%' => self::text($data, 'reasoning', 600),
            ]),
            payload: [
                'name' => $name,
                'purposes' => $purposes,
                'subjects' => $subjects,
                'categories' => $categories,
                'legalBasis' => in_array($basis, self::LEGAL_BASES, true) ? $basis : 'contract',
                'fields' => ['description' => $purpose, 'retentionPeriod' => $retention],
                'labels' => array_map(fn (string $k): string => $this->text->t($tenant, $k), self::FIELDS),
            ],
            editableText: SectionedText::compose($sections),
            confidence: self::confidence($data),
            dedupKey: 'supplier:' . (int) $record->getId(),
            model: $model,
        );
    }

    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
    {
        $tenant = $suggestion->getTenant();
        $supplier = $this->entityManager->find(Supplier::class, (int) $suggestion->getSubjectId());
        if (!$supplier instanceof Supplier || $supplier->getTenant() !== $tenant) {
            throw new StaleSuggestionException('supplier_gone');
        }
        if ($this->activities->findByProcessorSupplier($supplier) !== []) {
            throw new StaleSuggestionException('changed_meanwhile');
        }
        $p = $suggestion->getPayload();
        $values = (array) ($p['fields'] ?? []);
        if ($editedText !== null) {
            foreach (SectionedText::parse($editedText, (array) ($p['labels'] ?? [])) as $field => $value) {
                if ($value !== '') {
                    $values[$field] = $value;
                }
            }
        }

        $activity = new ProcessingActivity();
        $activity->setTenant($tenant);
        $activity->setCreatedBy($by);
        $activity->setName(mb_substr((string) $p['name'], 0, 255));
        $activity->setDescription((string) ($values['description'] ?? ''));
        $activity->setPurposes((array) $p['purposes']);
        $activity->setDataSubjectCategories((array) $p['subjects']);
        $activity->setPersonalDataCategories((array) $p['categories']);
        $activity->setLegalBasis((string) $p['legalBasis']);
        $activity->setRetentionPeriod(($values['retentionPeriod'] ?? '') !== '' ? (string) $values['retentionPeriod'] : null);
        $activity->setInvolvesProcessors(true);
        $activity->addProcessorSupplier($supplier);
        $this->entityManager->persist($activity);
        $this->entityManager->flush();

        return $activity;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $allowed
     * @return list<string>
     */
    private static function choices(array $data, string $key, array $allowed): array
    {
        $values = is_array($data[$key] ?? null) ? $data[$key] : [];

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '', $values),
            static fn (string $v): bool => in_array($v, $allowed, true),
        )));
    }

    /** @param list<string> $values */
    private function labels(Tenant $tenant, string $prefix, array $values): string
    {
        $locale = SuggestionText::locale($tenant);

        return implode(', ', array_map(
            fn (string $v): string => $this->translator->trans($prefix . $v, [], 'privacy', $locale),
            $values,
        ));
    }
}
