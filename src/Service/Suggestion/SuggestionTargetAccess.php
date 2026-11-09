<?php

declare(strict_types=1);

namespace App\Service\Suggestion;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Checks a kind's {@see TargetAccess} for the logged-in person: the
 * `#[IsGranted]` attributes of the UI action that does the same write, with
 * the suggestion's record as subject. Fails closed: an attribute it cannot
 * evaluate, or a record of another class than the action takes, denies.
 */
class SuggestionTargetAccess
{
    /** @var array<string, list<array{attribute: string|Expression|null, subject: bool, type: class-string|null}>> */
    private array $rules = [];

    public function __construct(private readonly Security $security)
    {
    }

    public function allows(SuggestionKind $kind, ?object $subject): bool
    {
        $access = $kind->targetAccess();
        if ($access->role !== null) {
            return $this->security->isGranted($access->role);
        }
        foreach ($this->rules($kind) as $rule) {
            if ($rule['attribute'] === null) {
                return false;
            }
            if (!$rule['subject']) {
                if (!$this->security->isGranted($rule['attribute'])) {
                    return false;
                }
                continue;
            }
            if ($subject === null || ($rule['type'] !== null && !$subject instanceof $rule['type'])) {
                return false;
            }
            if (!$this->security->isGranted($rule['attribute'], $subject)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The roles the UI write needs whatever the record (ROLE_USER left out).
     * A record holder below them is not the one who decides.
     *
     * @return list<string>
     */
    public function roleFloor(SuggestionKind $kind): array
    {
        $access = $kind->targetAccess();
        if ($access->role !== null) {
            return $access->role === 'ROLE_USER' ? [] : [$access->role];
        }
        $roles = [];
        foreach ($this->rules($kind) as $rule) {
            $attribute = $rule['attribute'];
            if (!$rule['subject'] && is_string($attribute) && str_starts_with($attribute, 'ROLE_') && $attribute !== 'ROLE_USER') {
                $roles[] = $attribute;
            }
        }

        return array_values(array_unique($roles));
    }

    /**
     * The access rules of the kind's UI action (POST applies).
     *
     * @return list<array{attribute: string|Expression|null, subject: bool, type: class-string|null}>
     */
    public function rules(SuggestionKind $kind): array
    {
        $key = $kind::class;
        if (isset($this->rules[$key])) {
            return $this->rules[$key];
        }
        $action = $kind->targetAccess()->action;
        if ($action === null) {
            return $this->rules[$key] = [];
        }
        [$class, $method] = $action;
        $reflection = new \ReflectionMethod($class, $method);
        $attributes = array_merge(
            (new \ReflectionClass($class))->getAttributes(IsGranted::class),
            $reflection->getAttributes(IsGranted::class),
        );
        $rules = [];
        foreach ($attributes as $attribute) {
            $granted = $attribute->newInstance();
            $methods = $granted->methods;
            if ($methods !== [] && !in_array('POST', array_map('strtoupper', $methods), true)) {
                continue;
            }
            $value = $granted->attribute;
            $subject = $granted->subject;
            $type = is_string($subject) ? self::parameterType($reflection, $subject) : null;
            // A subject that is not a record parameter (an id, a closure) cannot be matched: deny.
            $readable = (is_string($value) || $value instanceof Expression)
                && ($subject === null || $subject instanceof Expression || $type !== null);
            $rules[] = [
                'attribute' => $readable ? $value : null,
                'subject' => $subject !== null,
                'type' => $type,
            ];
        }

        return $this->rules[$key] = $rules;
    }

    /** @return class-string|null */
    private static function parameterType(\ReflectionMethod $method, string $name): ?string
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($parameter->getName() === $name && $type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                /** @var class-string $class */
                $class = $type->getName();

                return $class;
            }
        }

        return null;
    }
}
