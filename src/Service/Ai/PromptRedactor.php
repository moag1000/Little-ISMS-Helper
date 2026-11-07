<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Person;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Replaces personal data before a prompt leaves the house (GDPR Art. 5 (1)
 * c, Art. 25): e-mail addresses, phone numbers, IBANs, IP addresses and the
 * names of the organisation's accounts and persons. Placeholders keep the
 * text readable for the model ("[Person 1]"). Used for external endpoints
 * unless the organisation allowed personal data explicitly.
 */
class PromptRedactor implements ResetInterface
{
    private const array PATTERNS = [
        '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i' => '[E-Mail]',
        '/\b[A-Z]{2}\d{2}(?:\s?[A-Z0-9]{4}){3,7}(?:\s?[A-Z0-9]{1,4})?\b/' => '[IBAN]',
        '/\b(?:\d{1,3}\.){3}\d{1,3}\b/' => '[IP]',
        // Starts with +, 00 or 0 and has no dots, so dates (07.11.2026, 2026-11-07) survive.
        '/(?<![\w.])(?:\+|0)\d[\d \/()-]{6,}\d(?![\w.])/' => '[Telefon]',
    ];

    /** @var array<int, list<string>> tenant id => names, longest first */
    private array $names = [];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function reset(): void
    {
        $this->names = [];
    }

    public function redact(string $text, Tenant $tenant): string
    {
        $text = (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $text);
        foreach ($this->namesOf($tenant) as $i => $name) {
            $text = (string) preg_replace('/\b' . preg_quote($name, '/') . '\b/iu', sprintf('[Person %d]', $i + 1), $text);
        }

        return $text;
    }

    /** @return list<string> */
    private function namesOf(Tenant $tenant): array
    {
        $id = (int) $tenant->getId();
        if (isset($this->names[$id])) {
            return $this->names[$id];
        }
        $names = [];
        foreach ($this->entityManager->getRepository(User::class)->findBy(['tenant' => $tenant]) as $user) {
            $names[] = trim((string) $user->getFullName());
            $names[] = trim((string) $user->getLastName());
        }
        foreach ($this->entityManager->getRepository(Person::class)->findBy(['tenant' => $tenant]) as $person) {
            $names[] = trim((string) $person->getFullName());
        }
        // Only names with at least 3 characters: "IT" or "Al" would eat ordinary words.
        $names = array_values(array_unique(array_filter($names, static fn (string $n): bool => mb_strlen($n) >= 3)));
        usort($names, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $this->names[$id] = $names;
    }
}
