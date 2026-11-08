<?php

declare(strict_types=1);

namespace App\Service\Suggestion;

use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\SuggestionRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Responsibility\Responsibility;
use App\Service\Responsibility\ResponsibilityResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * "Entwurf vorschlagen" on a detail page (P6): which drafts the current user
 * may ask for on this record, and asking for one. The draft lands in the
 * suggestion queue like the nightly ones — same decision dialog, same audit
 * trail, same rule that nothing is written before a person accepts.
 *
 * Who may ask: whoever may decide the result (the kind's decision roles or
 * the person responsible for the record). Asking again after a decision is
 * allowed on purpose: the person asks, so the "once per record" rule of the
 * nightly AI run does not apply.
 */
class RecordDrafts
{
    public function __construct(
        private readonly SuggestionQueue $queue,
        private readonly SuggestionRepository $repository,
        private readonly AiAssistant $ai,
        private readonly Security $security,
        private readonly ResponsibilityResolver $resolver,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Drafts the current user may ask for on the record, none pending. With
     * $byAssistant the draft comes from the person's own assistant (MCP), so
     * model kinds count even while the organisation's AI is off.
     *
     * @return list<RecordDraftKind>
     */
    public function kindsFor(object $record, bool $byAssistant = false): array
    {
        $tenant = self::tenantOf($record);
        $id = method_exists($record, 'getId') ? $record->getId() : null;
        if (!$tenant instanceof Tenant || !is_int($id)) {
            return [];
        }
        $class = (string) (new SuggestionDraft($record, '', ''))->subjectClass();
        $pendingKinds = [];
        foreach ($this->repository->findPendingForSubject($tenant, $class, $id) as $pending) {
            $pendingKinds[$pending->getKind()] = true;
        }

        $kinds = [];
        foreach ($this->queue->kinds() as $key => $kind) {
            if (!$kind instanceof RecordDraftKind || isset($pendingKinds[$key])
                || !self::matchesClass($kind, $record)
                || !$this->queue->isKindActive($kind, $tenant)
                || ($kind->needsModel() && !$byAssistant && !$this->ai->isAvailable($tenant, $key))
                || !$this->mayRequest($kind, $record, $tenant)
                || !$kind->supportsRecord($record)) {
                continue;
            }
            $kinds[] = $kind;
        }

        return $kinds;
    }

    /** The record behind a request, only for a class the kind drafts for and only in the given organisation. */
    public function resolve(RecordDraftKind $kind, string $class, int $id, Tenant $tenant): ?object
    {
        if (!in_array($class, $kind->subjectClasses(), true)) {
            return null;
        }
        $record = $this->entityManager->find($class, $id);

        return $record !== null && self::tenantOf($record) === $tenant ? $record : null;
    }

    /**
     * The record behind a short class name (Incident, Control, …) among the
     * classes some draft kind works on, in the given organisation.
     */
    public function resolveByName(string $shortName, int $id, Tenant $tenant): ?object
    {
        foreach ($this->queue->kinds() as $kind) {
            if (!$kind instanceof RecordDraftKind) {
                continue;
            }
            foreach ($kind->subjectClasses() as $class) {
                if (strcasecmp(substr((string) strrchr('\\' . $class, '\\'), 1), $shortName) === 0) {
                    return $this->resolve($kind, $class, $id, $tenant);
                }
            }
        }

        return null;
    }

    /** @return list<string> short class names drafts can be asked for on */
    public function subjectNames(): array
    {
        $names = [];
        foreach ($this->queue->kinds() as $kind) {
            if ($kind instanceof RecordDraftKind) {
                foreach ($kind->subjectClasses() as $class) {
                    $names[] = substr((string) strrchr('\\' . $class, '\\'), 1);
                }
            }
        }
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    public function mayRequest(RecordDraftKind $kind, object $record, Tenant $tenant): bool
    {
        foreach ($kind->decisionRoles() as $role) {
            if ($this->security->isGranted($role)) {
                return true;
            }
        }
        $user = $this->security->getUser();

        return $user instanceof User
            && $this->resolver->isResponsible($user, Responsibility::ofRecord($record)->orRoles(...$kind->decisionRoles()), $tenant);
    }

    /**
     * Asks the kind for a draft and puts it into the queue. Null when nothing
     * could be drafted (model unavailable or failed, facts missing).
     */
    public function draft(Tenant $tenant, RecordDraftKind $kind, object $record): ?Suggestion
    {
        if (!$this->queue->isKindActive($kind, $tenant) || !$kind->supportsRecord($record)) {
            return null;
        }
        $draft = $kind->draftFor($tenant, $record);

        return $draft !== null ? $this->offer($tenant, $kind, $draft) : null;
    }

    /**
     * Puts a draft asked for by a person into the queue — also when the same
     * proposal was decided before (the person asks again on purpose).
     */
    public function offer(Tenant $tenant, RecordDraftKind $kind, SuggestionDraft $draft): ?Suggestion
    {
        $suggestion = $this->queue->offer($tenant, $kind, $draft);
        if ($suggestion === null) {
            // The same proposal was decided before; the person asks again, so it gets its own entry.
            $suggestion = $this->queue->offer($tenant, $kind, $draft->withDedupKey(
                ($draft->dedupKey ?? $draft->resolveDedupKey($kind->key())) . ':asked:' . (new \DateTimeImmutable())->format('YmdHisu'),
            ));
        }

        return $suggestion;
    }

    private static function matchesClass(RecordDraftKind $kind, object $record): bool
    {
        foreach ($kind->subjectClasses() as $class) {
            if ($record instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private static function tenantOf(object $record): ?Tenant
    {
        $tenant = method_exists($record, 'getTenant') ? $record->getTenant() : null;

        return $tenant instanceof Tenant ? $tenant : null;
    }
}
