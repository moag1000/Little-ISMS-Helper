<?php

declare(strict_types=1);

namespace App\Service\Suggestion;

use App\Attribute\PersonalDataResource;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SuggestionStatus;
use App\Repository\SuggestionRepository;
use App\Service\AuditLogger;
use App\Service\ModuleConfigurationService;
use App\Service\Responsibility\ResponsibilityResolver;
use App\Service\Suggestion\Kind\AbstractAiKind;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The one suggestion queue (Paket 5). Kinds offer drafts; a person accepts
 * (the kind applies it) or rejects. Every decision is audit-logged with kind,
 * origin, model and target — never with prompt or answer text, which may
 * carry personal data.
 *
 * Who sees and who decides (S30): deciding needs a decision role or the
 * responsibility for the record AND the right of the same write in the UI
 * ({@see SuggestionKind::targetAccess()}); seeing needs the decision, or a
 * reading role (auditor line, managers) — suggestions about records of
 * people (`#[PersonalDataResource]` on the whole class) only for the data
 * protection officer's role.
 */
class SuggestionQueue
{
    /** @var array<string, SuggestionKind> */
    private array $kinds = [];

    /** Role that reads suggestions about records of people (as for the assistant, S24). */
    public const string PERSONAL_DATA_ROLE = 'ROLE_DPO';

    /** Role that reads the whole queue without deciding (auditor line; managers inherit it). */
    public const string READER_ROLE = 'ROLE_AUDITOR';

    /** @var array<string, bool> */
    private static array $personalClasses = [];

    /**
     * @param iterable<SuggestionKind> $kinds
     */
    public function __construct(
        #[AutowireIterator('app.suggestion_kind')] iterable $kinds,
        private readonly SuggestionRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Security $security,
        private readonly ModuleConfigurationService $modules,
        private readonly ?SuggestionResponsibility $responsibility = null,
        private readonly ?ResponsibilityResolver $resolver = null,
        private ?SuggestionTargetAccess $targetAccess = null,
    ) {
        $this->targetAccess ??= new SuggestionTargetAccess($security);
        foreach ($kinds as $kind) {
            $this->kinds[$kind->key()] = $kind;
        }
    }

    /** @return array<string, SuggestionKind> */
    public function kinds(): array
    {
        return $this->kinds;
    }

    public function kind(string $key): ?SuggestionKind
    {
        return $this->kinds[$key] ?? null;
    }

    public function isKindActive(SuggestionKind $kind, Tenant $tenant): bool
    {
        $module = $kind->requiredModule();

        return $module === null || $this->modules->isModuleActiveForTenant($module, $tenant);
    }

    /**
     * Adds a draft to the queue. Returns the new entry, or null when the
     * same proposal is already known (pending, accepted or rejected). A
     * superseded twin is reopened; an older pending proposal of the same
     * kind for the same record is superseded by the new one.
     */
    public function offer(Tenant $tenant, SuggestionKind $kind, SuggestionDraft $draft): ?Suggestion
    {
        $key = $draft->resolveDedupKey($kind->key());
        $existing = $this->repository->findOneByDedup($tenant, $kind->key(), $key);
        if ($existing instanceof Suggestion) {
            if ($existing->getStatus() !== SuggestionStatus::Superseded) {
                return null;
            }
            $existing->reopen($draft->title, $draft->rationale);
            $this->supersedeSiblings($tenant, $kind, $draft, $existing);
            $this->entityManager->flush();

            return $existing;
        }

        $suggestion = (new Suggestion($tenant, $kind->key(), $kind->origin(), $draft->title, $draft->rationale, $key))
            ->setSubject($draft->subjectClass(), $draft->subjectId())
            ->setPayload($draft->payload)
            ->setEditableText($draft->editableText)
            ->setConfidence($draft->confidence)
            ->setModel($draft->model);
        $this->supersedeSiblings($tenant, $kind, $draft, $suggestion);
        $this->entityManager->persist($suggestion);
        $this->entityManager->flush();

        return $suggestion;
    }

    /**
     * Applies the suggestion through its kind and records the decision.
     *
     * @throws StaleSuggestionException when the proposal no longer fits; it is then superseded
     */
    public function accept(Suggestion $suggestion, User $by, ?string $editedText = null, ?string $note = null): object
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            // One decision per suggestion: a second click or a second person waits here and
            // then finds it decided (F4) — nothing is created twice.
            if ($suggestion->getId() !== null) {
                $this->entityManager->refresh($suggestion, LockMode::PESSIMISTIC_WRITE);
            }
            $this->assertPending($suggestion);
            $kind = $this->requireKind($suggestion);
            $this->assertStillCandidate($kind, $suggestion);
            $edited = $editedText !== null && $suggestion->getEditableText() !== null
                && trim($editedText) !== trim($suggestion->getEditableText());
            $text = $editedText !== null && trim($editedText) !== '' && $suggestion->getEditableText() !== null
                ? trim($editedText)
                : $suggestion->getEditableText();
            $result = $kind->apply($suggestion, $by, $text);
            $suggestion->decide(SuggestionStatus::Accepted, $by, $note, $result);
            $this->entityManager->flush();
            $connection->commit();
        } catch (StaleSuggestionException $e) {
            try {
                $this->supersede($suggestion, $e->getMessage());
                $connection->commit();
            } catch (\Throwable $inner) {
                self::rollBack($connection);
                throw $inner;
            }
            throw $e;
        } catch (\Throwable $e) {
            self::rollBack($connection);
            throw $e;
        }
        $this->log('suggestion.accepted', $suggestion, ['edited' => $edited]);

        return $result;
    }

    public function reject(Suggestion $suggestion, User $by, ?string $reason): void
    {
        $this->assertPending($suggestion);
        try {
            $this->requireKind($suggestion)->onReject($suggestion, $by, $reason);
        } catch (StaleSuggestionException $e) {
            $this->supersede($suggestion, $e->getMessage());
            throw $e;
        }
        $suggestion->decide(SuggestionStatus::Rejected, $by, $reason);
        $this->entityManager->flush();
        $this->log('suggestion.rejected', $suggestion, ['reason_given' => $reason !== null && trim($reason) !== '']);
    }

    public function supersede(Suggestion $suggestion, string $cause): void
    {
        if (!$suggestion->isPending()) {
            return;
        }
        $suggestion->decide(SuggestionStatus::Superseded, null, $cause);
        $this->entityManager->flush();
    }

    /**
     * Records a rejection of a proposal that is computed live and never
     * queued (SoA mapping suggestions), so it does not come back.
     */
    public function rememberRejection(Tenant $tenant, SuggestionKind $kind, SuggestionDraft $draft, User $by, ?string $reason): Suggestion
    {
        $suggestion = $this->repository->findOneByDedup($tenant, $kind->key(), $draft->resolveDedupKey($kind->key()))
            ?? $this->offer($tenant, $kind, $draft);
        if ($suggestion instanceof Suggestion && $suggestion->isPending()) {
            $this->reject($suggestion, $by, $reason);
        }

        // @intentional-assertion: offer() only returns null for an inactive kind, which callers checked
        return $suggestion ?? throw new \LogicException('Suggestion could not be recorded.');
    }

    public function canDecide(Suggestion $suggestion): bool
    {
        $kind = $this->kind($suggestion->getKind());
        if (!$kind instanceof SuggestionKind || !$this->isKindActive($kind, $suggestion->getTenant())) {
            return false;
        }
        // The same right as the write in the UI, for role holders and record holders alike (S30, F3).
        if (!$this->allowsTarget($kind, $this->responsibility?->subject($suggestion))) {
            return false;
        }
        foreach ($kind->decisionRoles() as $role) {
            if ($this->security->isGranted($role)) {
                return true;
            }
        }
        // The person responsible for the record (risk owner, asset owner, …)
        // decides its suggestions too — the same answer My Day and the digest use.
        $user = $this->security->getUser();
        if ($user instanceof User && $this->responsibility !== null && $this->resolver !== null) {
            return $this->resolver->isResponsible($user, $this->responsibility->of($suggestion), $suggestion->getTenant());
        }

        return false;
    }

    /** Does the current user hold the UI right of the kind's write on this record? */
    public function allowsTarget(SuggestionKind $kind, ?object $subject): bool
    {
        return $this->targetAccess?->allows($kind, $subject) ?? false;
    }

    /**
     * May the current user see this suggestion (list, decided view, counts)?
     * Who decides sees it; a reading role sees the organisation's queue, but
     * suggestions about records of people only with the DPO role (S30, F1).
     */
    public function isVisible(Suggestion $suggestion): bool
    {
        $kind = $this->kind($suggestion->getKind());
        if (!$kind instanceof SuggestionKind || !$this->isKindActive($kind, $suggestion->getTenant())) {
            return false;
        }
        if ($this->canDecide($suggestion)) {
            return true;
        }
        if (!$this->security->isGranted(self::READER_ROLE)) {
            return false;
        }

        return !self::isAboutPeople($suggestion->getSubjectClass()) || $this->security->isGranted(self::PERSONAL_DATA_ROLE);
    }

    /**
     * @param iterable<Suggestion> $suggestions
     * @return list<Suggestion>
     */
    public function visible(iterable $suggestions): array
    {
        $visible = [];
        foreach ($suggestions as $suggestion) {
            if ($this->isVisible($suggestion)) {
                $visible[] = $suggestion;
            }
        }

        return $visible;
    }

    /**
     * Is the record class one whose records describe people as a whole
     * (`#[PersonalDataResource]` without a field list, or never for the assistant)?
     */
    public static function isAboutPeople(?string $class): bool
    {
        if ($class === null || !class_exists($class)) {
            return false;
        }
        if (!array_key_exists($class, self::$personalClasses)) {
            $attributes = (new \ReflectionClass($class))->getAttributes(PersonalDataResource::class);
            $marker = $attributes === [] ? null : $attributes[0]->newInstance();
            self::$personalClasses[$class] = $marker !== null && (!$marker->isFieldLevel() || !$marker->assistant);
        }

        return self::$personalClasses[$class];
    }

    /** @return list<Suggestion> pending entries the current user may decide */
    public function pendingFor(Tenant $tenant): array
    {
        return array_values(array_filter(
            $this->repository->findPending($tenant),
            fn (Suggestion $s): bool => $this->canDecide($s),
        ));
    }

    /** @return list<Suggestion> pending entries about one record that the current user may decide */
    public function pendingForSubject(object $record): array
    {
        if (!method_exists($record, 'getId') || !method_exists($record, 'getTenant')) {
            return [];
        }
        $tenant = $record->getTenant();
        $id = $record->getId();
        if (!$tenant instanceof Tenant || !is_int($id)) {
            return [];
        }
        $class = (new SuggestionDraft($record, '', ''))->subjectClass();

        return array_values(array_filter(
            $this->repository->findPendingForSubject($tenant, (string) $class, $id),
            fn (Suggestion $s): bool => $this->canDecide($s),
        ));
    }

    private function supersedeSiblings(Tenant $tenant, SuggestionKind $kind, SuggestionDraft $draft, Suggestion $keep): void
    {
        $class = $draft->subjectClass();
        $id = $draft->subjectId();
        if ($class === null || $id === null) {
            return;
        }
        foreach ($this->repository->findPendingForSubject($tenant, $class, $id) as $old) {
            if ($old !== $keep && $old->getKind() === $kind->key()) {
                $old->decide(SuggestionStatus::Superseded, null, 'replaced');
            }
        }
    }

    private function requireKind(Suggestion $suggestion): SuggestionKind
    {
        return $this->kind($suggestion->getKind())
            ?? throw new StaleSuggestionException(sprintf('Unknown suggestion kind "%s".', $suggestion->getKind()));
    }

    /**
     * An AI draft fits only while its record still needs it (F5): a breach
     * already reported, a request answered or an audit closed makes it stale.
     */
    private function assertStillCandidate(SuggestionKind $kind, Suggestion $suggestion): void
    {
        if (!$kind instanceof AbstractAiKind) {
            return;
        }
        $subject = $this->responsibility?->subject($suggestion);
        if ($subject !== null && !$kind->stillApplies($suggestion, $subject)) {
            throw new StaleSuggestionException('no_longer_open');
        }
    }

    private static function rollBack(\Doctrine\DBAL\Connection $connection): void
    {
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
    }

    private function assertPending(Suggestion $suggestion): void
    {
        if (!$suggestion->isPending()) {
            throw new StaleSuggestionException('Suggestion was already decided.');
        }
    }

    /** @param array<string, mixed> $extra */
    private function log(string $action, Suggestion $suggestion, array $extra): void
    {
        $this->auditLogger->logCustom(
            $action,
            'Suggestion',
            $suggestion->getId(),
            null,
            [
                'kind' => $suggestion->getKind(),
                'origin' => $suggestion->getOrigin()->value,
                'model' => $suggestion->getModel(),
                'subject' => self::ref($suggestion->getSubjectClass(), $suggestion->getSubjectId()),
                'result' => self::ref($suggestion->getResultClass(), $suggestion->getResultId()),
            ] + $extra,
            sprintf('Suggestion %s: %s', $action === 'suggestion.accepted' ? 'accepted' : 'rejected', $suggestion->getKind()),
        );
    }

    private static function ref(?string $class, ?int $id): ?string
    {
        if ($class === null) {
            return null;
        }
        $pos = strrpos($class, '\\');

        return ($pos === false ? $class : substr($class, $pos + 1)) . '#' . (string) $id;
    }
}
