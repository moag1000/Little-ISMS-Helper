<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AuditFinding;
use App\Entity\ThreatLedPenetrationTest;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * DORA Art. 26 threat-led penetration test (TIBER-EU / RTS on TLPT).
 *
 * Status and the actual test dates are not in the form: the lifecycle
 * (planned → scoping → red_team → reporting → closed) moves the status, and
 * {@see \App\Lifecycle\EventListener\TlptExecutionDatesListener} stamps the
 * start of the red-team phase and the end of the test from the transitions.
 */
final class ThreatLedPenetrationTestType extends AbstractType implements SectionMapInterface
{
    public static function getSectionMap(): array
    {
        return [
            'overview' => ['engagementNumber', 'title'],
            'scope_definition' => ['scope', 'threatIntelligenceBasis', 'jurisdictionCodes'],
            'team' => ['providerType', 'testProvider'],
            'schedule' => ['plannedStartDate', 'plannedEndDate'],
            'results' => ['executiveSummary', 'findings'],
        ];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('engagementNumber', TextType::class, [
                'label' => 'dora.tlpt.field.engagement_number',
                'attr' => ['maxlength' => 50],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 50)],
            ])
            ->add('title', TextType::class, [
                'label' => 'dora.tlpt.field.title',
                'attr' => ['maxlength' => 255],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 255)],
            ])
            ->add('scope', TextareaType::class, [
                'label' => 'dora.tlpt.field.scope',
                'required' => false,
                'attr' => ['rows' => 4],
                'help' => 'dora.tlpt.help.scope',
            ])
            ->add('threatIntelligenceBasis', TextareaType::class, [
                'label' => 'dora.tlpt.field.threat_intelligence_basis',
                'required' => false,
                'attr' => ['rows' => 4],
                'help' => 'dora.tlpt.help.threat_intelligence_basis',
            ])
            ->add('jurisdictionCodes', CountryType::class, [
                'label' => 'dora.tlpt.field.jurisdictions',
                'multiple' => true,
                'required' => false,
                'attr' => ['data-controller' => 'tom-select'],
                'help' => 'dora.tlpt.help.jurisdictions',
            ])
            ->add('providerType', ChoiceType::class, [
                'label' => 'dora.tlpt.field.provider_type',
                'choices' => [
                    'dora.tlpt.provider.external' => ThreatLedPenetrationTest::PROVIDER_EXTERNAL,
                    'dora.tlpt.provider.internal' => ThreatLedPenetrationTest::PROVIDER_INTERNAL,
                ],
                'help' => 'dora.tlpt.help.provider_type',
            ])
            ->add('testProvider', TextType::class, [
                'label' => 'dora.tlpt.field.test_provider',
                'required' => false,
                'attr' => ['maxlength' => 255],
                'constraints' => [new Assert\Length(max: 255)],
            ])
            ->add('plannedStartDate', DateType::class, [
                'label' => 'dora.tlpt.field.planned_start_date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('plannedEndDate', DateType::class, [
                'label' => 'dora.tlpt.field.planned_end_date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'help' => 'dora.tlpt.help.planned_end_date',
            ])
            ->add('executiveSummary', TextareaType::class, [
                'label' => 'dora.tlpt.field.executive_summary',
                'required' => false,
                'attr' => ['rows' => 5],
                'help' => 'dora.tlpt.help.executive_summary',
            ])
            ->add('findings', EntityType::class, [
                'class' => AuditFinding::class,
                'label' => 'dora.tlpt.field.findings',
                'multiple' => true,
                'required' => false,
                // Tenant filter: only the organisation's own findings are offered.
                'choice_label' => static fn (AuditFinding $f): string => trim(($f->getFindingNumber() ?? '') . ' ' . ($f->getTitle() ?? '')),
                'attr' => ['data-controller' => 'tom-select'],
                'help' => 'dora.tlpt.help.findings',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ThreatLedPenetrationTest::class,
            'translation_domain' => 'dora',
        ]);
    }
}
