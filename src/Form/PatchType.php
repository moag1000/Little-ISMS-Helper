<?php

namespace App\Form;

use App\Entity\Asset;
use App\Entity\Patch;
use App\Entity\Vulnerability;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PatchType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('patchId', TextType::class, [
                'label' => 'field.patch_id',
                'required' => true,
                'attr' => [
                    'maxlength' => 100,
                    'placeholder' => 'placeholder.patch_id',
                ],
                'help' => 'help.patch_id',
            ])
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
                    'rows' => 5,
                    'placeholder' => 'placeholder.description',
                ],
            ])
            ->add('vulnerability', EntityType::class, [
                'label' => 'field.vulnerability',
                'class' => Vulnerability::class,
                'choice_label' => function(Vulnerability $vuln) {
                    return ($vuln->getCveId() ?? 'N/A') . ' - ' . $vuln->getTitle();
                },
                'placeholder' => 'placeholder.vulnerability',
                'required' => false,
                'help' => 'help.vulnerability',
            ])
            ->add('vendor', TextType::class, [
                'label' => 'field.vendor',
                'required' => true,
                'attr' => [
                    'maxlength' => 100,
                    'placeholder' => 'placeholder.vendor',
                ],
            ])
            ->add('product', TextType::class, [
                'label' => 'field.product',
                'required' => true,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'placeholder.product',
                ],
            ])
            ->add('version', TextType::class, [
                'label' => 'field.version',
                'required' => false,
                'attr' => [
                    'maxlength' => 50,
                    'placeholder' => '1.2.3',
                ],
            ])
            ->add('patchType', ChoiceType::class, [
                'label' => 'field.patch_type',
                'choices' => [
                    'type.security' => 'security',
                    'type.critical' => 'critical',
                    'type.feature' => 'feature',
                    'type.bugfix' => 'bugfix',
                    'type.hotfix' => 'hotfix',
                ],
                'required' => true,
                'help' => 'help.patch_type',
                    'choice_translation_domain' => 'patches',
            ])
            ->add('priority', ChoiceType::class, [
                'label' => 'field.priority',
                'choices' => [
                    'priority.critical' => 'critical',
                    'priority.high' => 'high',
                    'priority.medium' => 'medium',
                    'priority.low' => 'low',
                ],
                'required' => true,
                'help' => 'help.priority',
                    'choice_translation_domain' => 'patches',
            ])
            ->add('affectedAssets', EntityType::class, [
                'label' => 'field.affected_assets',
                'class' => Asset::class,
                'choice_label' => 'name',
                'multiple' => true,
                'required' => false,
                'help' => 'help.affected_assets',
                'attr' => [
                    'class' => 'form-select',
                    'size' => 5,
                ],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'field.status',
                'choices' => [
                    'status.pending' => 'pending',
                    'status.testing' => 'testing',
                    'status.approved' => 'approved',
                    'status.deployed' => 'deployed',
                    'status.failed' => 'failed',
                    'status.rolled_back' => 'rolled_back',
                    'status.not_applicable' => 'not_applicable',
                ],
                'required' => true,
                    'choice_translation_domain' => 'patches',
            ])
            ->add('releaseDate', DateType::class, [
                'label' => 'field.release_date',
                'required' => true,
                'widget' => 'single_text',
            ])
            ->add('deploymentDeadline', DateType::class, [
                'label' => 'field.deployment_deadline',
                'required' => false,
                'widget' => 'single_text',
                'help' => 'help.deployment_deadline',
            ])
            ->add('deployedDate', DateType::class, [
                'label' => 'field.deployed_date',
                'required' => false,
                'widget' => 'single_text',
            ])
            ->add('responsiblePerson', TextType::class, [
                'label' => 'field.responsible_person',
                'required' => false,
                'attr' => [
                    'maxlength' => 100,
                    'placeholder' => 'placeholder.responsible_person',
                ],
                'help' => 'help.responsible_person',
            ])
            ->add('testingNotes', TextareaType::class, [
                'label' => 'field.testing_notes',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'placeholder.testing_notes',
                ],
                'help' => 'help.testing_notes',
            ])
            ->add('deploymentNotes', TextareaType::class, [
                'label' => 'field.deployment_notes',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                ],
            ])
            ->add('rollbackPlan', TextareaType::class, [
                'label' => 'field.rollback_plan',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'placeholder.rollback_plan',
                ],
                'help' => 'help.rollback_plan',
            ])
            ->add('requiresDowntime', ChoiceType::class, [
                'label' => 'field.requires_downtime',
                'choices' => [
                    'common.yes' => true,
                    'common.no' => false,
                ],
                'expanded' => true,
                'required' => true,
                    'choice_translation_domain' => 'messages',
            ])
            ->add('estimatedDowntimeMinutes', IntegerType::class, [
                'label' => 'field.estimated_downtime_minutes',
                'required' => false,
                'attr' => [
                    'min' => 0,
                    'placeholder' => '60',
                ],
                'help' => 'help.estimated_downtime_minutes',
            ])
            ->add('requiresReboot', ChoiceType::class, [
                'label' => 'field.requires_reboot',
                'choices' => [
                    'common.yes' => true,
                    'common.no' => false,
                ],
                'expanded' => true,
                'required' => true,
                    'choice_translation_domain' => 'messages',
            ])
            ->add('knownIssues', TextareaType::class, [
                'label' => 'field.known_issues',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'placeholder.known_issues',
                ],
            ])
            ->add('downloadUrl', UrlType::class, [
                'label' => 'field.download_url',
                'required' => false,
                'attr' => [
                    'placeholder' => 'https://vendor.com/patches/...',
                ],
            ])
            ->add('documentationUrl', UrlType::class, [
                'label' => 'field.documentation_url',
                'required' => false,
                'attr' => [
                    'placeholder' => 'https://vendor.com/docs/...',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Patch::class,
            'translation_domain' => 'patches',
        ]);
    }
}
