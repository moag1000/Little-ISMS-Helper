<?php

declare(strict_types=1);

namespace App\Validator\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Server-side shape check for JsonBuilderType values (see JsonShape).
 */
final class JsonShapeValidator extends ConstraintValidator
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof JsonShape) {
            throw new UnexpectedTypeException($constraint, JsonShape::class);
        }
        if ($value === null || $value === []) {
            return;
        }
        if (!is_array($value)) {
            $this->context->buildViolation($constraint->invalidStructureMessage)->addViolation();
            return;
        }

        switch ($constraint->shape) {
            case 'object':
                $this->validateEntry($value, 1, $constraint);
                return;
            case 'list':
                if (!array_is_list($value)) {
                    $this->context->buildViolation($constraint->invalidStructureMessage)->addViolation();
                    return;
                }
                foreach ($value as $i => $entry) {
                    // Legacy free-JSON data may hold plain scalars; the builder
                    // keeps them verbatim (read-only), so they must not block a save.
                    if (is_string($entry) || is_int($entry) || is_float($entry)) {
                        continue;
                    }
                    if (!is_array($entry) || ($entry !== [] && array_is_list($entry))) {
                        $this->context->buildViolation($constraint->invalidStructureMessage)->addViolation();
                        return;
                    }
                    $this->validateEntry($entry, $i + 1, $constraint);
                }
                return;
            case 'map':
                if (array_is_list($value)) {
                    $this->context->buildViolation($constraint->invalidStructureMessage)->addViolation();
                    return;
                }
                $row = 0;
                foreach ($value as $key => $entry) {
                    ++$row;
                    if (trim((string) $key) === '') {
                        $this->context->buildViolation($constraint->emptyKeyMessage)
                            ->setParameter('%row%', (string) $row)
                            ->addViolation();
                        continue;
                    }
                    if ($constraint->scalarValue) {
                        $field = $constraint->fields[0] ?? ['key' => 'value', 'type' => 'text'];
                        $this->validateField($field, $entry, $row, $constraint);
                        continue;
                    }
                    if (!is_array($entry) || ($entry !== [] && array_is_list($entry))) {
                        $this->context->buildViolation($constraint->invalidStructureMessage)->addViolation();
                        return;
                    }
                    $this->validateEntry($entry, $row, $constraint);
                }
                return;
            default:
                $this->context->buildViolation($constraint->invalidStructureMessage)->addViolation();
        }
    }

    /**
     * @param array<array-key, mixed> $entry
     */
    private function validateEntry(array $entry, int $row, JsonShape $constraint): void
    {
        foreach ($constraint->fields as $field) {
            $key = (string) $field['key'];
            $this->validateField($field, $entry[$key] ?? null, $row, $constraint);
        }
    }

    /**
     * @param array<string, mixed> $field
     */
    private function validateField(array $field, mixed $value, int $row, JsonShape $constraint): void
    {
        $label = $this->translator->trans((string) ($field['label'] ?? $field['key']), [], $constraint->translationDomain);
        $isEmpty = $value === null || $value === '' || $value === [];

        if ($isEmpty) {
            if (($field['required'] ?? false) === true) {
                $this->context->buildViolation($constraint->requiredMessage)
                    ->setParameter('%field%', $label)
                    ->setParameter('%row%', (string) $row)
                    ->addViolation();
            }
            return;
        }

        if (!$this->isValidValue($field, $value)) {
            $this->context->buildViolation($constraint->invalidValueMessage)
                ->setParameter('%field%', $label)
                ->setParameter('%row%', (string) $row)
                ->addViolation();
        }
    }

    /**
     * @param array<string, mixed> $field
     */
    private function isValidValue(array $field, mixed $value): bool
    {
        $type = (string) ($field['type'] ?? 'text');

        return match ($type) {
            'bool' => is_bool($value),
            'number' => is_int($value) || is_float($value),
            'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
                && \DateTimeImmutable::createFromFormat('!Y-m-d', $value) !== false,
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false
                && preg_match('#^https?://#i', $value) === 1,
            'select' => (is_string($value) || is_int($value))
                && in_array((string) $value, array_map('strval', array_values((array) ($field['choices'] ?? []))), true),
            'tags' => is_array($value) && array_is_list($value) && $this->allItemsValid($value, (string) ($field['item_type'] ?? 'text')),
            default => is_string($value) || is_int($value) || is_float($value),
        };
    }

    /**
     * @param list<mixed> $items
     */
    private function allItemsValid(array $items, string $itemType): bool
    {
        foreach ($items as $item) {
            if (!is_string($item) || trim($item) === '') {
                return false;
            }
            if ($itemType === 'email' && filter_var($item, FILTER_VALIDATE_EMAIL) === false) {
                return false;
            }
        }

        return true;
    }
}
