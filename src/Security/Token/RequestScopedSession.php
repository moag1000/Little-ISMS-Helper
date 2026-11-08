<?php

declare(strict_types=1);

namespace App\Security\Token;

use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBag;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\SessionBagInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MetadataBag;

/**
 * The session an API-token request gets: it lives only in memory for this one
 * request. Code that reads or writes "the session" (locale, MFA flags, persona
 * preview, flashes) keeps working, but nothing is ever stored, no session id
 * exists and no cookie is sent — it never reports itself as started, so the
 * kernel's session listener has nothing to save. A session cookie sent along
 * with the token is ignored. Attached by {@see \App\EventSubscriber\ApiTokenSessionSubscriber}.
 */
final class RequestScopedSession implements FlashBagAwareSessionInterface
{
    private readonly AttributeBag $attributes;
    private readonly FlashBag $flashes;
    private readonly MetadataBag $metadata;
    /** @var array<string, SessionBagInterface> */
    private array $bags = [];

    public function __construct()
    {
        $this->attributes = new AttributeBag();
        $this->flashes = new FlashBag();
        $this->metadata = new MetadataBag();
        $attributes = [];
        $flashes = [];
        $metadata = [];
        $this->attributes->initialize($attributes);
        $this->flashes->initialize($flashes);
        $this->metadata->initialize($metadata);
    }

    public function start(): bool
    {
        return true;
    }

    public function getId(): string
    {
        return '';
    }

    public function setId(string $id): void
    {
    }

    public function getName(): string
    {
        return 'api_token_request';
    }

    public function setName(string $name): void
    {
    }

    public function invalidate(?int $lifetime = null): bool
    {
        $this->clear();
        $this->flashes->clear();

        return true;
    }

    public function migrate(bool $destroy = false, ?int $lifetime = null): bool
    {
        return true;
    }

    public function save(): void
    {
    }

    public function has(string $name): bool
    {
        return $this->attributes->has($name);
    }

    public function get(string $name, mixed $default = null): mixed
    {
        return $this->attributes->get($name, $default);
    }

    public function set(string $name, mixed $value): void
    {
        $this->attributes->set($name, $value);
    }

    public function all(): array
    {
        return $this->attributes->all();
    }

    public function replace(array $attributes): void
    {
        $this->attributes->replace($attributes);
    }

    public function remove(string $name): mixed
    {
        return $this->attributes->remove($name);
    }

    public function clear(): void
    {
        $this->attributes->clear();
    }

    /** Never started: the kernel's session listener then saves nothing and sets no cookie. */
    public function isStarted(): bool
    {
        return false;
    }

    public function registerBag(SessionBagInterface $bag): void
    {
        $this->bags[$bag->getName()] = $bag;
    }

    public function getBag(string $name): SessionBagInterface
    {
        $bag = match ($name) {
            $this->attributes->getName() => $this->attributes,
            $this->flashes->getName() => $this->flashes,
            default => $this->bags[$name] ?? null,
        };
        if ($bag === null) {
            // @intentional-assertion: SessionInterface::getBag() contract, same exception as Symfony's Session
            throw new \InvalidArgumentException(sprintf('The SessionBagInterface "%s" is not registered.', $name));
        }

        return $bag;
    }

    public function getMetadataBag(): MetadataBag
    {
        return $this->metadata;
    }

    public function getFlashBag(): FlashBagInterface
    {
        return $this->flashes;
    }
}
