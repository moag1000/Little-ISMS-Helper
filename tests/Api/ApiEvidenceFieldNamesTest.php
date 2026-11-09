<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\ApiResource;
use App\Attribute\ApiReadOnlyEvidence;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * S6: approval and reporting evidence is read-only on the API only when it
 * carries #[ApiReadOnlyEvidence] — an unmarked new field slips through. Every
 * API-writable property whose NAME looks like evidence (who/when approved,
 * verified, reported, notified, signed, accepted …) must therefore be marked,
 * or be listed below with the reason. Until T (wave 7) every field of the
 * entity's own form passed unseen — exactly the approval fields typed in the
 * UI; and only top-level entities were read. The second test checks the other
 * direction: marked evidence is not an editable form field.
 */
final class ApiEvidenceFieldNamesTest extends KernelTestCase
{
    private const string EVIDENCE_NAME = '/(At|By|ByUser|ByPerson|Notified|Reported|Approved|Verified|Accepted|Signed|Confirmed)$|^(approv|verif|report|authori[sz]|notif|signed|accept|certif|closed|resolved|submitted|confirm)/';

    /** Reviewed: plain facts no workflow owns. property => reason */
    private const array REVIEWED = [
        'App\Entity\InternalAudit::reportDate' => 'date the audit report was issued; no workflow sets it, not evidence of a decision',
        'App\Entity\PhysicalAccessLog::authorized' => 'machine-fed access log (badge reader), no form exists',
        'App\Entity\PhysicalAccessLog::escortedBy' => 'machine-fed access log (badge reader), no form exists',
        // Reviewed in T (wave 7) — before, any field of the entity form passed unseen:
        'App\Entity\Incident::occurredAt' => 'when the incident happened: a fact the reporter states',
        'App\Entity\Incident::notificationRequired' => 'the handler\'s assessment (input to the reporting duty), not a record of a report',
        'App\Entity\Incident::reportedByUser' => 'who reported the incident (reporter), not who reported it to an authority',
        'App\Entity\Incident::reportedByPerson' => 'who reported the incident (reporter)',
        'App\Entity\Incident::reportedByDeputyPersons' => 'deputies of the reporter',
        'App\Entity\Incident::reportSummary' => 'text of the report; the submission timestamps are marked',
        'App\Entity\ChangeRequest::requestedBy' => 'requester of the change',
        'App\Entity\ChangeRequest::verificationResults' => 'what the verification found (text); verifiedBy / verifiedDate are marked',
        'App\Entity\BCExercise::reportDate' => 'date the exercise report was issued, like InternalAudit::reportDate',
        'App\Entity\Supplier::certifications' => 'the supplier\'s certificates (list), not our certification of it',
        'App\Entity\Asset::acceptableUsePolicy' => 'reference to the usage policy, no acceptance',
    ];

    /**
     * Workflow evidence a person may still type in a form, with the reason. S12
     * (wave 8) closed the list "open since T": every other marked field is
     * written by the step that produces it and shown read-only. A NEW entry
     * needs a reason why typing it in is the evidence itself.
     * class::property => reason
     */
    private const array FORM_EDITABLE_EVIDENCE = [
        'App\Entity\Control::implementationStatus' => 'S11: set by a person in the control form / SoA (feeds fulfilment); API and assistant may not',
        'App\Entity\Control::implementationPercentage' => 'S11: set by a person in the control form / SoA (feeds fulfilment); API and assistant may not',
        'App\Entity\Control::effectiveness' => 'S12: recording the effectiveness test IS typing its result; the form is ControlVoter::EDIT (manager or the control\'s responsible), and without a test of the last 12 months the rating is derived (DerivedField)',
        'App\Entity\Control::lastEffectivenessTest' => 'S12: date of that test, entered with its result by the same people; a verified corrective action writes it too',
        'App\Entity\ISMSObjective::currentValue' => 'S12: the measurement itself, entered by the objective\'s responsible (IsmsSteeringVoter::EDIT); a new value sets lastMeasuredAt (ISO 27001 9.1)',
        'App\Entity\Incident::nationalAuthorityNotified' => 'S12: names the authority (BSI, ENISA), a fact; the report times are evidence and come only from "Als gemeldet markieren"',
        'App\Entity\Incident::authorityReferenceNumber' => 'S12: the authority\'s case number, a fact the authority hands out',
    ];

    /**
     * S12: fields a form shows read-only only in a state (DerivedField::readOnlyWhen) —
     * editable before, frozen after. The factory builds a record in the frozen state.
     *
     * @return array<string, callable(): object> class::property => record in the frozen state
     */
    private static function frozenStates(): array
    {
        $acceptedRisk = static function (): \App\Entity\Risk {
            $risk = new \App\Entity\Risk();
            $risk->setFormallyAccepted(true);

            return $risk;
        };

        // the lifecycle owns the status; the fixture sets the stored value directly
        $completedExercise = static function (): \App\Entity\BCExercise {
            $exercise = new \App\Entity\BCExercise();
            (new \ReflectionProperty($exercise, 'status'))->setValue($exercise, 'completed');

            return $exercise;
        };

        return [
            'App\Entity\Risk::residualProbability' => $acceptedRisk,
            'App\Entity\Risk::residualImpact' => $acceptedRisk,
            'App\Entity\BCExercise::actualRtoAchieved' => $completedExercise,
            'App\Entity\BCExercise::actualRpoAchieved' => $completedExercise,
        ];
    }

    #[Test]
    public function evidenceNamedFieldsAreMarkedOrReviewed(): void
    {
        $problems = [];
        $checked = 0;
        foreach ($this->apiResourceClasses() as $class) {
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                $name = $property->getName();
                if (preg_match(self::EVIDENCE_NAME, $name) !== 1 || !$this->inWriteGroup($property)) {
                    continue;
                }
                ++$checked;
                $key = $class . '::' . $name;
                if ($property->getAttributes(ApiReadOnlyEvidence::class) !== [] || isset(self::REVIEWED[$key])) {
                    continue;
                }
                $problems[] = $key . ' looks like evidence and is API-writable: mark it #[ApiReadOnlyEvidence], or review it into REVIEWED';
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
        self::assertGreaterThan(10, $checked, 'the name pattern no longer finds the known fields');
        foreach (array_keys(self::REVIEWED) as $key) {
            [$class, $name] = explode('::', $key);
            self::assertTrue(property_exists($class, $name), $key . ' no longer exists — drop it from REVIEWED');
        }
    }

    /**
     * T (wave 7): the other half. A field the API keeps read-only because a
     * workflow writes it (#[ApiReadOnlyEvidence]) must not be an editable field
     * of a form either — otherwise anyone with the form types "approved by X on
     * date Y" by hand (risk acceptance before T, change approval and board risk
     * acceptance before S12). S12: every FormType bound to the class counts, not
     * only <Entity>Type (SoA, quick forms, wizards).
     */
    #[Test]
    public function evidenceFieldsAreNotEditableInAnyForm(): void
    {
        $forms = self::getContainer()->get(FormFactoryInterface::class);
        $problems = [];
        $checked = 0;
        $formsByClass = $this->formTypesByDataClass();
        foreach ($this->apiResourceClasses() as $class) {
            $evidence = array_map(
                static fn (\ReflectionProperty $p): string => $p->getName(),
                array_values(array_filter(
                    (new ReflectionClass($class))->getProperties(),
                    static fn (\ReflectionProperty $p): bool => $p->getAttributes(ApiReadOnlyEvidence::class) !== [],
                )),
            );
            if ($evidence === []) {
                continue;
            }
            foreach ($formsByClass[$class] ?? [] as $type) {
                $form = $this->form($forms, $type, new $class());
                if ($form === null) {
                    continue;
                }
                foreach ($this->editableFields($form) as $path) {
                    if (!in_array($path, $evidence, true)) {
                        continue;
                    }
                    ++$checked;
                    $key = $class . '::' . $path;
                    if (!isset(self::FORM_EDITABLE_EVIDENCE[$key]) && !isset(self::frozenStates()[$key])) {
                        $problems[] = $key . ' is workflow evidence but an editable field of ' . $type;
                    }
                }
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
        self::assertGreaterThan(0, $checked, 'no form shows an editable evidence field at all — did the form lookup break?');
        foreach (array_keys(self::FORM_EDITABLE_EVIDENCE) as $key) {
            [$class, $name] = explode('::', $key);
            self::assertTrue(property_exists($class, $name), $key . ' no longer exists — drop it from FORM_EDITABLE_EVIDENCE');
            self::assertNotSame([], (new \ReflectionProperty($class, $name))->getAttributes(ApiReadOnlyEvidence::class), $key . ' is no longer marked evidence — drop it from FORM_EDITABLE_EVIDENCE');
        }
    }

    /**
     * S12: what was approved stays as approved. In the frozen state (accepted
     * risk, completed exercise) the field is read-only in every form of the class.
     */
    #[Test]
    public function frozenFieldsAreReadOnlyInTheirFrozenState(): void
    {
        $forms = self::getContainer()->get(FormFactoryInterface::class);
        $formsByClass = $this->formTypesByDataClass();
        $checked = 0;
        foreach (self::frozenStates() as $key => $factory) {
            [$class, $field] = explode('::', $key);
            foreach ($formsByClass[$class] ?? [] as $type) {
                $form = $this->form($forms, $type, $factory());
                if ($form === null) {
                    continue;
                }
                foreach ($form as $name => $child) {
                    if ((string) ($child->getConfig()->getPropertyPath() ?? $name) !== $field) {
                        continue;
                    }
                    ++$checked;
                    self::assertTrue($child->getConfig()->getDisabled(), $key . ' is editable in ' . $type . ' although the record is in its frozen state');
                    // ... and editable before: otherwise the entry belongs to the fixed read-only fields.
                    $open = $this->form($forms, $type, new $class());
                    self::assertNotNull($open);
                    self::assertFalse($open->get($name)->getConfig()->getDisabled(), $key . ' is read-only in every state — not a frozen-state field');
                }
            }
        }
        self::assertGreaterThanOrEqual(count(self::frozenStates()), $checked, 'a frozen-state field is in no form any more');
    }

    /**
     * @param \Symfony\Component\Form\FormInterface<mixed> $form
     * @return list<string> property paths of the editable top-level fields
     */
    private function editableFields(\Symfony\Component\Form\FormInterface $form): array
    {
        $paths = [];
        foreach ($form as $name => $child) {
            $config = $child->getConfig();
            if ($config->getDisabled() || !$config->getMapped()) {
                continue;
            }
            $paths[] = (string) ($config->getPropertyPath() ?? $name);
        }

        return $paths;
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
            if (!class_exists($type) || (new ReflectionClass($type))->isAbstract()
                || !is_subclass_of($type, \Symfony\Component\Form\FormTypeInterface::class)) {
                continue;
            }
            // data_class from the type's options alone — building a form without data warns in some types
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

    /** @return \Symfony\Component\Form\FormInterface<mixed>|null */
    private function form(FormFactoryInterface $forms, string $type, ?object $data): ?\Symfony\Component\Form\FormInterface
    {
        try {
            return $forms->create($type, $data);
        } catch (\Throwable) {
            return null; // form with required options (register type, …)
        }
    }

    private function inWriteGroup(\ReflectionProperty $property): bool
    {
        foreach ($property->getAttributes(Groups::class) as $attribute) {
            foreach ($attribute->newInstance()->groups as $group) {
                if (str_ends_with($group, ':write')) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<class-string> API resources under src/Entity (subdirectories included) */
    private function apiResourceClasses(): array
    {
        $root = dirname(__DIR__, 2) . '/src/Entity/';
        $classes = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $class = 'App\\Entity\\' . str_replace('/', '\\', substr($file->getPathname(), strlen($root), -4));
            if (class_exists($class) && (new ReflectionClass($class))->getAttributes(ApiResource::class) !== []) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
