<?php

declare(strict_types=1);

namespace App\Service\Register;

use App\Enum\RegisterType;

/**
 * A register entry the tool proposes from data it already has. Plain values
 * only, so the proposal can travel as a suggestion payload (Paket 5 queue).
 */
final readonly class RegisterProposal
{
    /**
     * @param array<string, list<int>> $links     link field => ids for the new entry
     * @param array<string, list<int>> $backLinks link field => ids of entries that get the new entry as link
     */
    public function __construct(
        public RegisterType $type,
        public string $title,
        public ?string $description,
        public string $origin,
        public string $source,
        public ?int $ownerUserId = null,
        public ?string $category = null,
        public ?string $reference = null,
        public array $links = [],
        public array $backLinks = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'title' => $this->title,
            'description' => $this->description,
            'origin' => $this->origin,
            'source' => $this->source,
            'ownerUserId' => $this->ownerUserId,
            'category' => $this->category,
            'reference' => $this->reference,
            'links' => $this->links,
            'backLinks' => $this->backLinks,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            RegisterType::from((string) $data['type']),
            (string) $data['title'],
            isset($data['description']) ? (string) $data['description'] : null,
            (string) $data['origin'],
            (string) ($data['source'] ?? ''),
            isset($data['ownerUserId']) ? (int) $data['ownerUserId'] : null,
            isset($data['category']) ? (string) $data['category'] : null,
            isset($data['reference']) ? (string) $data['reference'] : null,
            is_array($data['links'] ?? null) ? $data['links'] : [],
            is_array($data['backLinks'] ?? null) ? $data['backLinks'] : [],
        );
    }
}
