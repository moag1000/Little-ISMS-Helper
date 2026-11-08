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
        'App\Entity\Risk::acceptanceJustification' => 'the requester\'s reasoning, written with the acceptance request; who approved is marked',
        'App\Entity\Risk::acceptanceExpiryDate' => 'review horizon the requester proposes (ISO 27001 8.3); only the form and the import set it',
    ];

    /**
     * Workflow evidence a person may still type in the entity form, with the reason.
     * class::property => reason
     */
    private const array FORM_EDITABLE_EVIDENCE = [
        'App\Entity\Risk::decisionApprovedByUser' => 'no workflow writes it: the form is where the 6.1.3 decision approval is documented',
        'App\Entity\Risk::decisionApprovalDate' => 'no workflow writes it: the form is where the 6.1.3 decision approval is documented',
        'App\Entity\Control::implementationStatus' => 'S11: set by a person in the control form / SoA (feeds fulfilment); API and assistant may not',
        'App\Entity\Control::implementationPercentage' => 'S11: set by a person in the control form / SoA (feeds fulfilment); API and assistant may not',
        // Pre-existing at T (wave 7): the form documents by hand what a workflow or action also writes.
        // Whether each stays hand-editable is open (reported to the coordination); a NEW one fails.
        'App\Entity\Incident::closedAt' => 'open since T',
        'App\Entity\Incident::earlyWarningReportedAt' => 'open since T',
        'App\Entity\Incident::detailedNotificationReportedAt' => 'open since T',
        'App\Entity\Incident::finalReportSubmittedAt' => 'open since T',
        'App\Entity\Incident::nationalAuthorityNotified' => 'open since T',
        'App\Entity\Incident::authorityReferenceNumber' => 'open since T',
        'App\Entity\Incident::initialReportSubmittedAt' => 'open since T',
        'App\Entity\Incident::intermediateReportSubmittedAt' => 'open since T',
        'App\Entity\Incident::doraFinalReportSubmittedAt' => 'open since T',
        'App\Entity\BusinessContinuityPlan::lastTested' => 'open since T',
        'App\Entity\ChangeRequest::actualImplementationDate' => 'open since T',
        'App\Entity\ChangeRequest::approvedDate' => 'open since T',
        'App\Entity\ChangeRequest::approvalComments' => 'open since T',
        'App\Entity\ChangeRequest::implementedBy' => 'open since T',
        'App\Entity\ChangeRequest::verifiedBy' => 'open since T',
        'App\Entity\ChangeRequest::verifiedDate' => 'open since T',
        'App\Entity\ChangeRequest::closedDate' => 'open since T',
        'App\Entity\BCExercise::reportCompleted' => 'open since T',
        'App\Entity\BCExercise::actualRtoAchieved' => 'open since T',
        'App\Entity\Control::effectiveness' => 'open since T',
        'App\Entity\Control::lastEffectivenessTest' => 'open since T',
        'App\Entity\Supplier::boardLevelRiskAcceptance' => 'open since T',
        'App\Entity\ISMSObjective::currentValue' => 'open since T',
    ];

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
     * of the entity's own form either — otherwise anyone with the form types
     * "approved by X on date Y" by hand (risk acceptance before T).
     */
    #[Test]
    public function evidenceFieldsAreNotEditableInTheEntityForm(): void
    {
        $forms = self::getContainer()->get(FormFactoryInterface::class);
        $problems = [];
        $checked = 0;
        foreach ($this->apiResourceClasses() as $class) {
            $evidence = array_values(array_filter(
                (new ReflectionClass($class))->getProperties(),
                static fn (\ReflectionProperty $p): bool => $p->getAttributes(ApiReadOnlyEvidence::class) !== [],
            ));
            if ($evidence === []) {
                continue;
            }
            $form = $this->form($forms, $class);
            if ($form === null) {
                continue;
            }
            foreach ($form as $name => $child) {
                $path = (string) ($child->getConfig()->getPropertyPath() ?? $name);
                foreach ($evidence as $property) {
                    if ($property->getName() !== $path) {
                        continue;
                    }
                    ++$checked;
                    if (!$child->getConfig()->getDisabled() && !isset(self::FORM_EDITABLE_EVIDENCE[$class . '::' . $path])) {
                        $problems[] = $class . '::' . $path . ' is workflow evidence but an editable field of ' . $form->getConfig()->getType()->getInnerType()::class;
                    }
                }
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
        self::assertGreaterThan(0, $checked, 'no form shows an evidence field at all — did the form lookup break?');
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

    /** @return \Symfony\Component\Form\FormInterface<mixed>|null the entity's FormType, if it has one */
    private function form(FormFactoryInterface $forms, string $class): ?\Symfony\Component\Form\FormInterface
    {
        $type = 'App\\Form\\' . (new ReflectionClass($class))->getShortName() . 'Type';
        if (!class_exists($type)) {
            return null;
        }
        try {
            return $forms->create($type, new $class());
        } catch (\Throwable) {
            return null; // form with required options (register type, …)
        }
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
