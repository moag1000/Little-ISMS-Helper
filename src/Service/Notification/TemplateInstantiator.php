<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Notification\NotificationChannel;
use App\Entity\Notification\NotificationRule;
use App\Entity\Notification\NotificationTemplate;
use App\Entity\Tenant;
use App\Repository\Notification\NotificationChannelRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Instantiates a NotificationRule from a global NotificationTemplate.
 *
 * If the tenant has no active email channel and the template specifies email
 * delivery, a default email channel with empty recipient list is auto-created
 * so the tenant can fill in recipients in the admin UI.
 */
class TemplateInstantiator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationChannelRepository $channelRepo,
    ) {}

    /**
     * Clone the template's defaults into a new NotificationRule for the tenant.
     * Auto-creates an email channel if none exists and template requires email.
     */
    public function instantiate(NotificationTemplate $template, Tenant $tenant): NotificationRule
    {
        $rule = new NotificationRule();
        $rule->setTenant($tenant);
        $rule->setName($template->getName());
        $rule->setEventType($template->getDefaultEventType());
        $rule->setConditions($template->getDefaultConditions());
        $rule->setIsActive(false); // Inactive until tenant reviews and enables

        // Resolve or auto-create channels specified in defaultChannels
        foreach ($template->getDefaultChannels() as $channelSpec) {
            $channelType = (string) ($channelSpec['type'] ?? NotificationChannel::TYPE_EMAIL);
            $channel     = $this->resolveOrCreateChannel($tenant, $channelType, $channelSpec);
            $rule->addChannel($channel);
        }

        $this->entityManager->persist($rule);

        return $rule;
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function resolveOrCreateChannel(Tenant $tenant, string $type, array $spec): NotificationChannel
    {
        // Try to reuse the first active channel of the required type
        $existing = $this->channelRepo->findActiveByType($type, $tenant);
        if (!empty($existing)) {
            return $existing[0];
        }

        // Auto-create a placeholder channel
        $channel = new NotificationChannel();
        $channel->setTenant($tenant);
        $channel->setType($type);
        $channel->setName(sprintf('Auto: %s channel', ucfirst($type)));
        $channel->setConfig(match ($type) {
            NotificationChannel::TYPE_EMAIL   => ['recipients' => []],
            NotificationChannel::TYPE_WEBHOOK => ['url' => ''],
            default                           => [],
        });
        $channel->setIsActive(false); // Requires configuration before use

        $this->entityManager->persist($channel);

        return $channel;
    }
}
