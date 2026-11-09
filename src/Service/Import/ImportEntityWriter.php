<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Asset;
use App\Entity\Person;
use App\Entity\Tenant;
use App\Entity\User;
use App\Exception\Import\ImportRowInvalidException;
use App\Service\Owner\OwnerText;
use App\Service\Owner\OwnerTextResolver;
use App\Service\ProtectionRequirementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Applies one import row to a record and checks it like every other writer (S22).
 *
 * Before S22 the bulk import only type-checked setters: entity constraints
 * (`#[Assert\Url]` on a patch download link, `#[SameTenantOwner]`, the callback
 * rules of DSR / risk / consent) and the S12 freeze of a formally accepted risk
 * (`AcceptedRiskUnchanged`) were skipped. Now every row runs the Symfony
 * validator — in the preview (row error shown before anything is written) and
 * again in the commit (backstop). A derived value is not lowered by a row
 * either: an asset's availability that follows the BIA (P5) stays at least the
 * BIA value.
 */
class ImportEntityWriter
{
    public function __construct(
        private readonly ?ValidatorInterface $validator = null,
        private readonly ?OwnerTextResolver $ownerTexts = null,
        private readonly ?ProtectionRequirementService $protectionRequirements = null,
        private readonly ?EntityManagerInterface $em = null,
    ) {
    }

    /**
     * Instantiate a new record of the type, in the organisation, with the row's values.
     *
     * @param array<string, mixed> $entityData
     *
     * @throws ImportRowInvalidException when a value does not fit or the record is invalid
     */
    public function create(string $entityType, array $entityData, Tenant $tenant, bool $preview = false): object
    {
        $fqcn = 'App\\Entity\\' . $entityType;
        $entity = new $fqcn();
        if (method_exists($entity, 'setTenant')) {
            $entity->setTenant($tenant);
        }
        $this->apply($entity, $entityData, $tenant, $preview);
        $this->assertValid($entity);

        return $entity;
    }

    /**
     * Write the row onto a stored record. A refused row leaves the record as it
     * was loaded; in the preview ($preview) the record is always reset afterwards.
     *
     * @param array<string, mixed> $entityData
     *
     * @throws ImportRowInvalidException when a value does not fit or the record is invalid
     */
    public function update(object $entity, array $entityData, Tenant $tenant, bool $preview = false): void
    {
        try {
            $this->assertDerivedKept($entity, $entityData);
            $this->apply($entity, $entityData, $tenant, $preview);
            $this->assertValid($entity);
        } catch (\Throwable $e) {
            // A setter threw after earlier ones ran, or the result is invalid: drop the
            // half-applied row, or the next flush would write it without audit entry.
            $this->em?->refresh($entity);

            throw $e;
        } finally {
            if ($preview && $this->em?->contains($entity)) {
                $this->em->refresh($entity);
            }
        }
    }

    /**
     * Apply a flat property array via setter methods ("set" + ucfirst(property)).
     *
     * Properties whose setter does not exist are skipped. An owner given as text
     * that named nobody known ({@see OwnerText}) becomes a Person of the
     * organisation — in the preview only a stand-in that is never stored. Every
     * value is checked against its setter's parameter type before the first
     * setter runs, so a mismatch refuses the row without touching the record.
     *
     * @param array<string, mixed> $entityData
     *
     * @throws ImportRowInvalidException when a value does not fit its setter
     */
    public function apply(object $entity, array $entityData, Tenant $tenant, bool $preview = false): void
    {
        $setters = [];
        foreach ($entityData as $property => $value) {
            if ($value instanceof OwnerText) {
                $target = $preview ? $this->previewOwner($tenant, $value->text) : $this->ownerTexts?->resolve($tenant, $value->text);
                if ($target !== null) {
                    $setters[$target instanceof User ? $value->userSetter : $value->personSetter] = $target;
                }
                continue;
            }
            $setter = 'set' . ucfirst($property);
            if (!method_exists($entity, $setter)) {
                continue;
            }
            $type = ((new \ReflectionMethod($entity, $setter))->getParameters()[0] ?? null)?->getType();
            if ($type instanceof \ReflectionNamedType && !self::fitsType($type, $value)) {
                $message = sprintf('Field "%s": a %s value does not fit %s.', $property, get_debug_type($value), $type);

                throw new ImportRowInvalidException(0, [$message], $message);
            }
            $setters[$setter] = $value;
        }

        foreach ($setters as $setter => $value) {
            $entity->$setter($value);
        }
    }

    /**
     * The entity constraints, as the web form, REST and the assistant check them.
     *
     * @throws ImportRowInvalidException listing every violation
     */
    public function assertValid(object $entity): void
    {
        if ($this->validator === null) {
            return;
        }
        $messages = [];
        foreach ($this->validator->validate($entity) as $violation) {
            $path = $violation->getPropertyPath();
            $messages[] = ($path !== '' ? 'Field "' . $path . '": ' : '') . $violation->getMessage();
        }
        if ($messages !== []) {
            throw new ImportRowInvalidException(0, $messages, implode('; ', $messages));
        }
    }

    /**
     * P5: an asset's availability that follows the BIA of its business process is
     * read-only in the form; a row does not lower it below the BIA value either.
     *
     * @param array<string, mixed> $entityData
     */
    private function assertDerivedKept(object $entity, array $entityData): void
    {
        if (!$entity instanceof Asset || !array_key_exists('availabilityValue', $entityData)
            || $this->protectionRequirements === null || $entity->getId() === null) {
            return;
        }
        $bia = $this->protectionRequirements->calculateAvailabilityRequirement($entity);
        if (($bia['source'] ?? null) !== 'bcm' || !is_int($entityData['availabilityValue'])
            || $entityData['availabilityValue'] >= (int) $bia['value']) {
            return;
        }
        $message = sprintf(
            'Field "availabilityValue": follows the BIA of "%s" (at least %d); a lower value comes as a suggestion, not by import.',
            (string) ($bia['process']?->getName() ?? ''),
            (int) $bia['value'],
        );

        throw new ImportRowInvalidException(0, [$message], $message);
    }

    private function previewOwner(Tenant $tenant, string $text): User|Person|null
    {
        $found = $this->ownerTexts?->find($tenant, $text);
        if ($found !== null) {
            return $found;
        }
        $name = trim($text);

        // the commit records it as a new Person of the organisation; the preview only stands in for it
        return $name === '' ? null : (new Person())->setFullName(mb_substr($name, 0, 255))->setPersonType('other')->setTenant($tenant);
    }

    /** Whether $value passes a strict-types call to a parameter of this type. */
    private static function fitsType(\ReflectionNamedType $type, mixed $value): bool
    {
        if ($value === null) {
            return $type->allowsNull();
        }
        $name = $type->getName();
        if (!$type->isBuiltin()) {
            return in_array($name, ['self', 'static'], true) || $value instanceof $name;
        }

        return match ($name) {
            'int'               => is_int($value),
            'float'             => is_int($value) || is_float($value),
            'string'            => is_string($value),
            'bool'              => is_bool($value),
            'array', 'iterable' => is_array($value),
            default             => true, // mixed, object, …
        };
    }
}
