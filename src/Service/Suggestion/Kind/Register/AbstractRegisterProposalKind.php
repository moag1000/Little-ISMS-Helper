<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind\Register;

use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\RegisterType;
use App\Enum\SuggestionOrigin;
use App\Service\Register\RegisterProposalAdopter;
use App\Service\Register\RegisterProposalProvider;
use App\Service\Suggestion\AbstractSuggestionKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Controller\RegisterController;
use App\Service\Suggestion\TargetAccess;

/**
 * Register proposals (Paket 9) in the suggestion queue: whatever
 * RegisterProposalProvider would offer above a register list is also a
 * suggestion, decided by the register's maintaining persona. Both paths
 * write through RegisterProposalAdopter and deduplicate by `origin`, so a
 * proposal adopted on the register page drops out of the queue at the next run.
 *
 * One thin subclass per register type that has proposals.
 */
abstract class AbstractRegisterProposalKind extends AbstractSuggestionKind
{
    public function __construct(
        private readonly RegisterProposalProvider $proposals,
        private readonly RegisterProposalAdopter $adopter,
        private readonly SuggestionText $text,
        private readonly TranslatorInterface $translator,
    ) {
    }

    abstract protected function type(): RegisterType;

    public function key(): string
    {
        return 'register.' . $this->type()->value;
    }

    public function origin(): SuggestionOrigin
    {
        return SuggestionOrigin::Import;
    }

    public function targetAccess(): TargetAccess
    {
        return TargetAccess::action(RegisterController::class, 'adopt');
    }

    public function decisionRoles(): array
    {
        return array_values(array_unique([$this->type()->maintainerRole(), 'ROLE_MANAGER']));
    }

    public function generate(Tenant $tenant): iterable
    {
        $locale = SuggestionText::locale($tenant);
        $register = $this->translator->trans('register.type.' . $this->type()->value . '.title', [], 'register', $locale);
        foreach ($this->proposals->proposalsFor($tenant, $this->type()) as $proposal) {
            yield new SuggestionDraft(
                subject: null,
                title: $this->text->t($tenant, 'suggestions.text.register.title', [
                    '%register%' => $register,
                    '%title%' => $proposal->title,
                ]),
                rationale: $this->text->t($tenant, 'suggestions.text.register.rationale', [
                    '%source%' => $this->translator->trans($proposal->source, [], 'register', $locale),
                    '%norm%' => $this->type()->normReference(),
                ]),
                payload: [
                    ...$proposal->toArray(),
                    '_link' => ['app_register_list', ['type' => $this->type()->value]],
                ],
                dedupKey: $proposal->origin,
            );
        }
    }

    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
    {
        $tenant = $suggestion->getTenant();
        $origin = (string) ($suggestion->getPayload()['origin'] ?? '');
        foreach ($this->proposals->proposalsFor($tenant, $this->type()) as $proposal) {
            if ($proposal->origin === $origin) {
                $created = $this->adopter->adopt($tenant, [$proposal], $by);

                return $created[0];
            }
        }

        throw new StaleSuggestionException('already_applied');
    }
}
