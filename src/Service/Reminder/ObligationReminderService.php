<?php

declare(strict_types=1);

namespace App\Service\Reminder;

use App\Http\PublicUrlGenerator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use App\Entity\ObligationNotice;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ObligationNoticeRepository;
use App\Repository\TenantRepository;
use App\Service\Deadline\Obligation;
use App\Service\Deadline\ObligationInbox;
use App\Service\EmailNotificationService;
use App\Service\Notification\Event\DomainEventNotifier;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The one reminder path for every duty with a deadline.
 *
 * - Daily digest: one mail per person at the organisation's digest hour
 *   (default 07:00 local time) with everything on their My Day, decisions
 *   first. People can switch it off in their profile.
 * - Urgent notice: statutory hour deadlines (GDPR Art. 33 72 h, NIS-2 24/72 h)
 *   go out as soon as they enter their window, regardless of the digest
 *   setting, once per duty and deadline.
 *
 * Both read from {@see ObligationInbox}, so a duty reaches exactly the person
 * the responsibility resolver names. Every mail is also the person's in-app
 * notification ({@see ObligationNotice}).
 */
class ObligationReminderService
{
    /** Notices older than this are purged (they are a log, not an archive). */
    private const int RETENTION_DAYS = 90;

    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly ObligationInbox $inbox,
        private readonly ObligationNoticeRepository $notices,
        private readonly EmailNotificationService $mail,
        private readonly EntityManagerInterface $entityManager,
        // Public address (DEFAULT_URI), never the request's Host header — App\Http\PublicUrlGenerator.
        #[Autowire(service: PublicUrlGenerator::class)]
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly DomainEventNotifier $events,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Sends the digest to every organisation whose digest hour is now
     * (or to all of them with $force). Returns the number of mails sent.
     */
    public function sendDigests(?DateTimeImmutable $now = null, bool $force = false, bool $dryRun = false): int
    {
        $now ??= new DateTimeImmutable();
        $sent = 0;
        foreach ($this->tenants->findActive() as $tenant) {
            $local = $now->setTimezone($this->timezoneOf($tenant));
            if (!$force && (int) $local->format('G') !== ReminderSettings::digestHour($tenant)) {
                continue;
            }
            $key = 'digest:' . $local->format('Y-m-d');
            foreach ($this->inbox->byRecipient($tenant, $now) as ['user' => $user, 'items' => $items]) {
                if ($items === [] || !$user->isReminderDigestEnabled() || (string) $user->getEmail() === '') {
                    continue;
                }
                if ($this->notices->sentKeys($user, [$key]) !== []) {
                    continue;
                }
                if (!$dryRun) {
                    $this->sendDigest($tenant, $user, $items, $key);
                }
                ++$sent;
            }
        }
        if (!$dryRun) {
            $this->entityManager->flush();
            $this->notices->purgeOlderThan($now->modify(sprintf('-%d days', self::RETENTION_DAYS)));
        }

        return $sent;
    }

    /**
     * Sends a notice for every hour deadline that entered its window and has
     * not been notified yet. Returns the number of mails sent.
     */
    public function sendUrgent(?DateTimeImmutable $now = null, bool $dryRun = false): int
    {
        $now ??= new DateTimeImmutable();
        $sent = 0;
        foreach ($this->tenants->findActive() as $tenant) {
            $fired = [];
            foreach ($this->inbox->byRecipient($tenant, $now) as ['user' => $user, 'items' => $items]) {
                $urgent = array_values(array_filter(
                    $items,
                    static fn (Obligation $o): bool => $o->profile()->urgentHours !== null && ($o->urgent || $o->overdue),
                ));
                if ($urgent === []) {
                    continue;
                }
                $keys = array_map(static fn (Obligation $o): string => 'urgent:' . $o->key(), $urgent);
                $already = $this->notices->sentKeys($user, $keys);
                $new = array_values(array_filter($urgent, static fn (Obligation $o): bool => !isset($already['urgent:' . $o->key()])));
                if ($new === []) {
                    continue;
                }
                if (!$dryRun) {
                    $this->sendUrgentMail($tenant, $user, $new);
                    foreach ($new as $obligation) {
                        $fired[$obligation->key()] = $obligation;
                    }
                }
                ++$sent;
            }
            foreach ($fired as $obligation) {
                // Webhook / chat rules can forward hour deadlines (rule event "obligation.urgent").
                $this->events->fire('obligation.urgent', $tenant, [
                    'source' => $obligation->source->slug(),
                    'id' => $obligation->source->refId($obligation->record),
                    'title' => $obligation->title,
                    'dueAt' => $obligation->dueDate?->format(DATE_ATOM),
                    'severity' => 'critical',
                ]);
            }
        }
        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return $sent;
    }

    /** @param list<Obligation> $items */
    private function sendDigest(Tenant $tenant, User $user, array $items, string $key): void
    {
        $locale = $this->localeOf($user, $tenant);
        $groups = [];
        foreach ($items as $obligation) {
            $bucket = $obligation->profile()->bucket;
            $groups[$bucket] ??= [
                'label' => $this->translator->trans('my_day.section.' . $bucket, [], 'my_day', $locale),
                'decision' => $obligation->profile()->decision,
                'items' => [],
            ];
            $groups[$bucket]['items'][] = $this->row($obligation, $locale);
        }
        uasort($groups, static fn (array $a, array $b): int => $b['decision'] <=> $a['decision']);
        $overdue = count(array_filter($items, static fn (Obligation $o): bool => $o->overdue));

        try {
            $this->mail->sendToUser($user, $this->translator->trans(
                'emails.obligation.digest.subject',
                ['%count%' => count($items), '%overdue%' => $overdue],
                'emails',
                $locale,
            ), 'emails/obligation_digest.html.twig', [
                'user' => $user,
                'groups' => array_values($groups),
                'count' => count($items),
                'overdue' => $overdue,
                'my_day_url' => $this->urls->generate('app_my_day', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL),
                'profile_url' => $this->urls->generate('app_profile', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL),
                'locale' => $locale,
            ], $tenant);
        } catch (\Throwable $e) {
            $this->logger->error('Reminder digest failed', ['user' => $user->getId(), 'error' => $e->getMessage()]);

            return;
        }
        $this->entityManager->persist(new ObligationNotice(
            $tenant,
            $user,
            $key,
            ObligationNotice::KIND_DIGEST,
            $this->translator->trans('emails.obligation.digest.notice', ['%count%' => count($items)], 'emails', $locale),
            $this->urls->generate('app_my_day', ['_locale' => $locale]),
        ));
    }

    /** @param list<Obligation> $items */
    private function sendUrgentMail(Tenant $tenant, User $user, array $items): void
    {
        $locale = $this->localeOf($user, $tenant);
        try {
            $this->mail->sendToUser($user, $this->translator->trans(
                'emails.obligation.urgent.subject',
                ['%title%' => $items[0]->title, '%count%' => count($items)],
                'emails',
                $locale,
            ), 'emails/obligation_urgent.html.twig', [
                'user' => $user,
                'items' => array_map(fn (Obligation $o): array => $this->row($o, $locale), $items),
                'my_day_url' => $this->urls->generate('app_my_day', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL),
                'locale' => $locale,
            ], $tenant);
        } catch (\Throwable $e) {
            $this->logger->error('Urgent reminder failed', ['user' => $user->getId(), 'error' => $e->getMessage()]);

            return;
        }
        foreach ($items as $obligation) {
            $link = $obligation->link();
            $this->entityManager->persist(new ObligationNotice(
                $tenant,
                $user,
                'urgent:' . $obligation->key(),
                ObligationNotice::KIND_URGENT,
                $obligation->title,
                $link !== null ? $this->urls->generate($link->route, $link->parameters + ['_locale' => $locale]) : null,
                $obligation->dueDate,
            ));
        }
    }

    /** @return array{title: string, due: ?DateTimeImmutable, overdue: bool, urgent: bool, norm: ?string, url: ?string} */
    private function row(Obligation $obligation, string $locale): array
    {
        $link = $obligation->link();

        return [
            'title' => $obligation->title,
            'due' => $obligation->dueDate,
            'overdue' => $obligation->overdue,
            'urgent' => $obligation->urgent,
            'norm' => $obligation->profile()->normReference,
            'url' => $link !== null
                ? $this->urls->generate($link->route, $link->parameters + ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL)
                : null,
        ];
    }

    private function timezoneOf(Tenant $tenant): DateTimeZone
    {
        try {
            return new DateTimeZone($tenant->getTimezone() ?: 'Europe/Berlin');
        } catch (\Exception) {
            return new DateTimeZone('Europe/Berlin');
        }
    }

    private function localeOf(User $user, Tenant $tenant): string
    {
        $locale = $user->getLanguage() ?: $tenant->getLocale() ?: 'de';

        return in_array($locale, ['de', 'en'], true) ? $locale : 'de';
    }
}
