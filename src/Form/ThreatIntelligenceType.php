<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Asset;
use App\Entity\Person;
use App\Entity\ThreatIntelligence;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Form type for ThreatIntelligence.
 *
 * Note: ThreatIntelligence is primarily managed via API Platform (REST).
 * This FormType is provided for future web-controller integration and
 * covers all user-editable fields including the Tri-State assignee slot.
 */
class ThreatIntelligenceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'field.title',
                'required' => true,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'placeholder.title',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'field.description',
                'required' => true,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'placeholder.description',
                ],
            ])
            ->add('threatType', ChoiceType::class, [
                'label' => 'field.threat_type',
                'choices' => [
                    'type.malware' => 'malware',
                    'type.phishing' => 'phishing',
                    'type.ransomware' => 'ransomware',
                    'type.ddos' => 'ddos',
                    'type.zero_day' => 'zero_day',
                    'type.apt' => 'apt',
                    'type.insider_threat' => 'insider_threat',
                    'type.social_engineering' => 'social_engineering',
                    'type.data_breach' => 'data_breach',
                    'type.vulnerability' => 'vulnerability',
                    'type.other' => 'other',
                ],
                'required' => true,
                'choice_translation_domain' => 'threat',
            ])
            ->add('severity', ChoiceType::class, [
                'label' => 'field.severity',
                'choices' => [
                    'severity.critical' => 'critical',
                    'severity.high' => 'high',
                    'severity.medium' => 'medium',
                    'severity.low' => 'low',
                    'severity.informational' => 'informational',
                ],
                'required' => true,
                'choice_translation_domain' => 'threat',
            ])
            ->add('source', TextType::class, [
                'label' => 'field.source',
                'required' => false,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'placeholder.source',
                ],
            ])
            ->add('cveId', TextType::class, [
                'label' => 'field.cve_id',
                'required' => false,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'placeholder.cve_id',
                ],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'field.status',
                'choices' => [
                    'status_type.new' => 'new',
                    'status_type.analyzing' => 'analyzing',
                    'status_type.mitigated' => 'mitigated',
                    'status_type.monitoring' => 'monitoring',
                    'status_type.closed' => 'closed',
                ],
                'required' => true,
                'choice_translation_domain' => 'threat',
            ])
            ->add('detectionDate', DateType::class, [
                'label' => 'field.detection_date',
                'required' => true,
                'widget' => 'single_text',
            ])
            ->add('mitigationDate', DateType::class, [
                'label' => 'field.mitigation_date',
                'required' => false,
                'widget' => 'single_text',
            ])
            ->add('cvssScore', IntegerType::class, [
                'label' => 'field.cvss_score',
                'required' => false,
                'attr' => [
                    'min' => 0,
                    'max' => 10,
                    'placeholder' => '0–10',
                ],
                'help' => 'help.cvss_score',
            ])
            ->add('mitigationRecommendations', TextareaType::class, [
                'label' => 'field.mitigation_recommendations',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('actionsTaken', TextareaType::class, [
                'label' => 'field.actions_taken',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('references', TextareaType::class, [
                'label' => 'field.references',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('affectsOrganization', ChoiceType::class, [
                'label' => 'field.affects_organization',
                'required' => true,
                'choices' => [
                    'choice.yes' => true,
                    'choice.no' => false,
                ],
                'choice_translation_domain' => 'threat',
                'expanded' => true,
            ])
            ->add('affectedAssets', EntityType::class, [
                'label' => 'field.affected_assets',
                'class' => Asset::class,
                'choice_label' => 'name',
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'attr' => [
                    'data-controller' => 'tom-select',
                ],
                'help' => 'help.affected_assets',
            ])
            // Tri-State assignee fields
            ->add('assignedTo', EntityType::class, [
                'label' => 'field.assigned_to_user',
                'class' => User::class,
                'choice_label' => fn(User $u): string => $u->getFullName() . ' (' . $u->getEmail() . ')',
                'required' => false,
                'placeholder' => 'placeholder.assigned_to_user',
                'help' => 'help.assigned_to_user',
            ])
            ->add('assignedPerson', EntityType::class, [
                'label' => 'field.assigned_person',
                'class' => Person::class,
                'choice_label' => fn(Person $p): string => $p->getFullName() ?? '',
                'required' => false,
                'placeholder' => 'placeholder.assigned_person',
                'help' => 'help.assigned_person',
            ])
            ->add('assignedDeputyPersons', EntityType::class, [
                'label' => 'field.assigned_deputy_persons',
                'class' => Person::class,
                'choice_label' => fn(Person $p): string => $p->getFullName() ?? '',
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'attr' => [
                    'data-controller' => 'tom-select',
                ],
                'help' => 'help.assigned_deputy_persons',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ThreatIntelligence::class,
            'translation_domain' => 'threat',
            'constraints' => [
                new Callback([$this, 'validateAssigneeSlot']),
            ],
        ]);
    }

    public function validateAssigneeSlot(?ThreatIntelligence $entity, ExecutionContextInterface $context): void
    {
        if ($entity === null) {
            return;
        }
        if ($entity->getAssignedTo() === null && $entity->getAssignedPerson() === null) {
            $context->buildViolation('error.assignee_required_user_or_person')
                ->atPath('assignedTo')
                ->addViolation();
        }
    }
}
