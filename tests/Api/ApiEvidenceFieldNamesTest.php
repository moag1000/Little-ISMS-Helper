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
 * or be a field of the entity's own form (a person enters it in the UI too,
 * so the API may as well), or be listed below with the reason.
 */
final class ApiEvidenceFieldNamesTest extends KernelTestCase
{
    private const string EVIDENCE_NAME = '/(At|By|ByUser|ByPerson|Notified|Reported|Approved|Verified|Accepted|Signed|Confirmed)$|^(approv|verif|report|authori[sz]|notif|signed|accept|certif|closed|resolved|submitted|confirm)/';

    /** Reviewed: plain facts no workflow owns. property => reason */
    private const array REVIEWED = [
        'App\Entity\InternalAudit::reportDate' => 'date the audit report was issued; no workflow sets it, not evidence of a decision',
        'App\Entity\PhysicalAccessLog::authorized' => 'machine-fed access log (badge reader), no form exists',
        'App\Entity\PhysicalAccessLog::escortedBy' => 'machine-fed access log (badge reader), no form exists',
    ];

    #[Test]
    public function evidenceNamedFieldsAreMarkedOrEnteredInTheForm(): void
    {
        $forms = self::getContainer()->get(FormFactoryInterface::class);
        $problems = [];
        $checked = 0;
        foreach (glob(dirname(__DIR__, 2) . '/src/Entity/*.php') ?: [] as $file) {
            $class = 'App\\Entity\\' . basename($file, '.php');
            if (!class_exists($class) || (new ReflectionClass($class))->getAttributes(ApiResource::class) === []) {
                continue;
            }
            $formFields = $this->formFields($forms, $class);
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                $name = $property->getName();
                if (preg_match(self::EVIDENCE_NAME, $name) !== 1 || !$this->inWriteGroup($property)) {
                    continue;
                }
                ++$checked;
                $key = $class . '::' . $name;
                if ($property->getAttributes(ApiReadOnlyEvidence::class) !== [] || in_array($name, $formFields, true) || isset(self::REVIEWED[$key])) {
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

    /** @return list<string> property paths of the entity's FormType, if it has one */
    private function formFields(FormFactoryInterface $forms, string $class): array
    {
        $type = 'App\\Form\\' . (new ReflectionClass($class))->getShortName() . 'Type';
        if (!class_exists($type)) {
            return [];
        }
        try {
            $form = $forms->create($type, new $class());
        } catch (\Throwable) {
            return []; // form with required options (register type, …): nothing counts as entered
        }
        $fields = [];
        foreach ($form as $name => $child) {
            $fields[] = (string) ($child->getConfig()->getPropertyPath() ?? $name);
        }

        return $fields;
    }
}
