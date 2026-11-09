<?php

declare(strict_types=1);

namespace App\Tests\Security;

use ApiPlatform\Metadata\ApiResource;
use App\Entity\User;
use App\Service\Import\EvidenceFieldFilter;
use App\Service\Import\Schema\ImportSchemaRegistry;
use App\Service\WriteRule\WriteRules;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Gate 96 (S22): one write rule, every write path.
 *
 * Rules that lived only in a FormType were bypassed by the bulk import, REST and
 * the assistant (MCP `save_record` runs REST operations). Since S22 a rule sits on
 * the entity property — `#[CreateOnly]`, `#[ManagerOrResponsibleField]`,
 * `#[ApiReadOnlyEvidence]` — and this test checks, for every such property, that
 * each path reads it:
 *
 * - Doctrine: the property is a mapped field or association (a typo protects nothing);
 * - web form: in every FormType bound to the class, a plain user (ROLE_USER, not
 *   responsible) sees the field of a stored record read-only; a manager may edit it;
 * - import: a create-only or evidence field never reaches an UPDATE of a stored record,
 *   evidence never a new one ({@see EvidenceFieldFilter});
 * - REST / MCP: a create-only field is in no `:write` group (only `:create`).
 *   The flush guard behind REST and MCP is exercised in {@see WriteRulePathsTest}.
 */
final class WritePathParityTest extends KernelTestCase
{
    #[Test]
    public function everyRuleSitsOnAMappedProperty(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $problems = [];
        $count = 0;
        foreach (self::ruledClasses() as $class => $rules) {
            $meta = $em->getClassMetadata($class);
            foreach (array_keys($rules) as $field) {
                ++$count;
                if (!$meta->hasField($field) && !$meta->hasAssociation($field)) {
                    $problems[] = $class . '::' . $field . ' carries a write rule but is not stored by Doctrine';
                }
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
        self::assertGreaterThan(40, $count, 'the rule lookup no longer finds the S22 fields');
    }

    #[Test]
    public function aPlainUserSeesRuledFieldsReadOnlyInEveryForm(): void
    {
        $forms = self::getContainer()->get(FormFactoryInterface::class);
        $byClass = $this->formTypesByDataClass();
        $problems = [];
        $checked = 0;
        foreach (self::ruledClasses() as $class => $rules) {
            foreach ($byClass[$class] ?? [] as $type) {
                $this->actAs(['ROLE_USER']);
                $form = $this->form($forms, $type, self::stored($class));
                if ($form === null) {
                    continue;
                }
                foreach ($this->fieldsByPath($form) as $path => $child) {
                    if (!isset($rules[$path])) {
                        continue;
                    }
                    ++$checked;
                    if (!$child->getConfig()->getDisabled()) {
                        $problems[] = sprintf('%s::%s (%s) is editable for a plain user in %s', $class, $path, $rules[$path], $type);
                    }
                }

                // ... and a manager still edits it: the rule narrows who, it does not freeze the field.
                $this->actAs(['ROLE_MANAGER']);
                $managerForm = $this->form($forms, $type, self::stored($class));
                foreach ($managerForm === null ? [] : $this->fieldsByPath($managerForm) as $path => $child) {
                    if (isset($rules[$path]) && $child->getConfig()->getDisabled()
                        && !$this->disabledForEverybody($forms, $type, $class, $path)) {
                        $problems[] = sprintf('%s::%s is read-only for a manager in %s', $class, $path, $type);
                    }
                }
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
        self::assertGreaterThan(30, $checked, 'no form shows a ruled field any more — did the form lookup break?');
    }

    #[Test]
    public function theImportNeverMovesACreateOnlyOrEvidenceField(): void
    {
        $registry = self::getContainer()->get(ImportSchemaRegistry::class);
        $problems = [];
        $checked = 0;
        foreach ($registry->supportedEntityTypes() as $type) {
            $schema = $registry->getSchemaFor($type);
            if ($schema === null) {
                continue;
            }
            $class = $schema->entityClass;
            $protectedOnUpdate = [...EvidenceFieldFilter::importProtectedFields($class), ...WriteRules::createOnlyFields($class)];
            foreach ($schema->fields() as $field) {
                if (!in_array($field->name, $protectedOnUpdate, true)) {
                    continue;
                }
                ++$checked;
                $row = [$field->name => 'x'];
                if (EvidenceFieldFilter::forUpdate($class, $row) !== []) {
                    $problems[] = sprintf('import UPDATE of %s writes %s', $type, $field->name);
                }
                if (in_array($field->name, EvidenceFieldFilter::importProtectedFields($class), true)
                    && EvidenceFieldFilter::forCreate($class, $row) !== []) {
                    $problems[] = sprintf('import CREATE of %s writes the evidence field %s', $type, $field->name);
                }
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
        self::assertGreaterThan(5, $checked, 'the import schemas no longer offer a protected field at all — did the lookup break?');
        // detectedAt stays importable for NEW incidents (the anchor of a migrated incident)
        self::assertSame(['detectedAt' => 'x'], EvidenceFieldFilter::forCreate(\App\Entity\Incident::class, ['detectedAt' => 'x']));
    }

    #[Test]
    public function aCreateOnlyFieldIsInNoApiWriteGroup(): void
    {
        $problems = [];
        foreach (self::ruledClasses() as $class => $rules) {
            if ((new \ReflectionClass($class))->getAttributes(ApiResource::class) === []) {
                continue;
            }
            foreach ($rules as $field => $rule) {
                if ($rule !== WriteRules::CREATE_ONLY) {
                    continue;
                }
                foreach (self::property($class, $field)?->getAttributes(Groups::class) ?? [] as $attribute) {
                    foreach ($attribute->newInstance()->groups as $group) {
                        if (str_ends_with($group, ':write')) {
                            $problems[] = $class . '::' . $field . ' is create-only but in the API group ' . $group;
                        }
                    }
                }
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * Entities under src/Entity with at least one write rule.
     *
     * @return array<class-string, array<string, string>>
     */
    private static function ruledClasses(): array
    {
        $root = dirname(__DIR__, 2) . '/src/Entity/';
        $classes = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $class = 'App\\Entity\\' . str_replace('/', '\\', substr($file->getPathname(), strlen($root), -4));
            if (!class_exists($class) || (new \ReflectionClass($class))->isAbstract()) {
                continue;
            }
            $rules = WriteRules::of($class);
            if ($rules !== []) {
                $classes[$class] = $rules;
            }
        }

        return $classes;
    }

    /** A record that counts as stored (it has an id) and belongs to no organisation, so nobody holds it. */
    private static function stored(string $class): object
    {
        $record = new $class();
        $id = self::property($class, 'id');
        $id?->setValue($record, 987654);

        return $record;
    }

    /** @param list<string> $roles */
    private function actAs(array $roles): void
    {
        $user = (new User())->setEmail('parity@example.test')->setRoles($roles);
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    /**
     * Whether the field is read-only even for a manager on this record (the form
     * freezes it for everybody, e.g. a derived value) — then it is no S22 finding.
     */
    private function disabledForEverybody(FormFactoryInterface $forms, string $type, string $class, string $path): bool
    {
        $this->actAs(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $form = $this->form($forms, $type, self::stored($class));
        $child = $form === null ? null : ($this->fieldsByPath($form)[$path] ?? null);

        return $child === null || $child->getConfig()->getDisabled();
    }

    /**
     * @param FormInterface<mixed> $form
     * @return array<string, FormInterface<mixed>> property path → field
     */
    private function fieldsByPath(FormInterface $form): array
    {
        $fields = [];
        foreach ($form as $name => $child) {
            if ($child->getConfig()->getMapped()) {
                $fields[(string) ($child->getConfig()->getPropertyPath() ?? $name)] = $child;
            }
        }

        return $fields;
    }

    /** @return FormInterface<mixed>|null */
    private function form(FormFactoryInterface $forms, string $type, object $data): ?FormInterface
    {
        try {
            return $forms->create($type, $data);
        } catch (\Throwable) {
            return null; // form with required options (wizard step, …)
        }
    }

    /**
     * Every FormType under src/Form, grouped by its data_class.
     *
     * @return array<class-string, list<class-string>>
     */
    private function formTypesByDataClass(): array
    {
        $registry = self::getContainer()->get('form.registry');
        $root = dirname(__DIR__, 2) . '/src/Form/';
        $byClass = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php' || !str_ends_with($file->getFilename(), 'Type.php')) {
                continue;
            }
            $type = 'App\\Form\\' . str_replace('/', '\\', substr($file->getPathname(), strlen($root), -4));
            if (!class_exists($type) || (new \ReflectionClass($type))->isAbstract()
                || !is_subclass_of($type, \Symfony\Component\Form\FormTypeInterface::class)) {
                continue;
            }
            try {
                $dataClass = $registry->getType($type)->getOptionsResolver()->resolve([])['data_class'] ?? null;
            } catch (\Throwable) {
                continue; // required options
            }
            if (is_string($dataClass)) {
                $byClass[$dataClass][] = $type;
            }
        }

        return $byClass;
    }

    private static function property(string $class, string $field): ?\ReflectionProperty
    {
        for ($reflection = new \ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
            if ($reflection->hasProperty($field)) {
                return $reflection->getProperty($field);
            }
        }

        return null;
    }
}
