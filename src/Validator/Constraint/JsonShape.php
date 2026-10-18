<?php

declare(strict_types=1);

namespace App\Validator\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Validates a decoded JSON value against the schema a JsonBuilderType field
 * declares (shape + typed fields). The builder UI already produces this
 * shape; the constraint is the server-side guarantee for hand-crafted
 * requests and for data that reaches the form from elsewhere.
 *
 * Keys that are not declared in the schema are tolerated and left untouched,
 * so legacy data round-trips without loss.
 *
 * @phpstan-type FieldDef array{key: string, type: string, label?: string, required?: bool, choices?: array<string, string>, item_type?: string}
 */
final class JsonShape extends Constraint
{
    public string $invalidStructureMessage = 'json_builder.invalid_structure';
    public string $requiredMessage = 'json_builder.required';
    public string $invalidValueMessage = 'json_builder.invalid_value';
    public string $emptyKeyMessage = 'json_builder.empty_key';

    /**
     * @param 'list'|'map'|'object'    $shape
     * @param list<array<string, mixed>> $fields
     */
    public function __construct(
        public string $shape = 'list',
        public array $fields = [],
        public bool $scalarValue = false,
        public string $translationDomain = 'messages',
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
