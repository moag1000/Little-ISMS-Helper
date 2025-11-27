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
                'label' => 'patch.field.patch_id',
                'required' => true,
                'attr' => [
                    'maxlength' => 100,
                    'placeholder' => 'patch.placeholder.patch_id',
                ],
                'help' => 'patch.help.patch_id',
            ])
            ->add('title', TextType::class, [
                'label' => 'patch.field.title',
                'required' => true,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'patch.placeholder.title',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'patch.field.description',
                'required' => true,
                'attr' => [
                    'rows' => 5,
                    'placeholder' => 'patch.placeholder.description',
                ],
            ])
            ->add('vulnerability', EntityType::class, [
                'label' => 'patch.field.vulnerability',
                'class' => Vulnerability::class,
                'choice_label' => function(Vulnerability $vuln) {
                    return ($vuln->getCveId() ?? 'N/A') . ' - ' . $vuln->getTitle();
                },
                'placeholder' => 'patch.placeholder.vulnerability',
                'required' => false,
                'help' => 'patch.help.vulnerability',
            ])
            ->add('vendor', TextType::class, [
                'label' => 'patch.field.vendor',
                'required' => true,
                'attr' => [
                    'maxlength' => 100,
                    'placeholder' => 'patch.placeholder.vendor',
                ],
            ])
            ->add('product', TextType::class, [
                'label' => 'patch.field.product',
                'required' => true,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'patch.placeholder.product',
                ],
            ])
            ->add('version', TextType::class, [
                'label' => 'patch.field.version',
                'required' => false,
                'attr' => [
                    'maxlength' => 50,
                    'placeholder' => '1.2.3',
                ],
            ])
            ->add('patchType', ChoiceType::class, [
                'label' => 'patch.field.patch_type',
                'choices' => [
                    'patch.type.security' => 'security',
                    'patch.type.critical' => 'critical',
                    'patch.type.feature' => 'feature',
                    'patch.type.bugfix' => 'bugfix',
                    'patch.type.hotfix' => 'hotfix',
                ],
                'required' => true,
                'help' => 'patch.help.patch_type',
                    'choice_translation_domain' => 'patches',
            ])
            ->add('priority', ChoiceType::class, [
                'label' => 'patch.field.priority',
                'choices' => [
                    'patch.priority.critical' => 'critical',
                    'patch.priority.high' => 'high',
                    'patch.priority.medium' => 'medium',
                    'patch.priority.low' => 'low',
                ],
                'required' => true,
                'help' => 'patch.help.priority',
                    'choice_translation_domain' => 'patches',
            ])
            ->add('affectedAssets', EntityType::class, [
                'label' => 'patch.field.affected_assets',
                'class' => Asset::class,
                'choice_label' => 'name',
                'multiple' => true,
                'required' => false,
                'help' => 'patch.help.affected_assets',
                'attr' => [
                    'class' => 'form-select',
                    'size' => 5,
                ],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'patch.field.status',
                'choices' => [
                    'patch.status.pending' => 'pending',
                    'patch.status.testing' => 'testing',
                    'patch.status.approved' => 'approved',
                    'patch.status.deployed' => 'deployed',
                    'patch.status.failed' => 'failed',
                    'patch.status.rolled_back' => 'rolled_back',
                    'patch.status.not_applicable' => 'not_applicable',
                ],
                'required' => true,
                    'choice_translation_domain' => 'patches',
            ])
            ->add('releaseDate', DateType::class, [
                'label' => 'patch.field.release_date',
                'required' => true,
                'widget' => 'single_text',
            ])
            ->add('deploymentDeadline', DateType::class, [
                'label' => 'patch.field.deployment_deadline',
                'required' => false,
                'widget' => 'single_text',
                'help' => 'patch.help.deployment_deadline',
            ])
            ->add('deployedDate', DateType::class, [
                'label' => 'patch.field.deployed_date',
                'required' => false,
                'widget' => 'single_text',
            ])
            ->add('responsiblePerson', TextType::class, [
                'label' => 'patch.field.responsible_person',
                'required' => false,
                'attr' => [
                    'maxlength' => 100,
                    'placeholder' => 'patch.placeholder.responsible_person',
                ],
                'help' => 'patch.help.responsible_person',
            ])
            ->add('testingNotes', TextareaType::class, [
                'label' => 'patch.field.testing_notes',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'patch.placeholder.testing_notes',
                ],
                'help' => 'patch.help.testing_notes',
            ])
            ->add('deploymentNotes', TextareaType::class, [
                'label' => 'patch.field.deployment_notes',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                ],
            ])
            ->add('rollbackPlan', TextareaType::class, [
                'label' => 'patch.field.rollback_plan',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'patch.placeholder.rollback_plan',
                ],
                'help' => 'patch.help.rollback_plan',
            ])
            ->add('requiresDowntime', ChoiceType::class, [
                'label' => 'patch.field.requires_downtime',
                'choices' => [
                    'common.yes' => true,
                    'common.no' => false,
                ],
                'expanded' => true,
                'required' => true,
                    'choice_translation_domain' => 'messages',
            ])
            ->add('estimatedDowntimeMinutes', IntegerType::class, [
                'label' => 'patch.field.estimated_downtime_minutes',
                'required' => false,
                'attr' => [
                    'min' => 0,
                    'placeholder' => '60',
                ],
                'help' => 'patch.help.estimated_downtime_minutes',
            ])
            ->add('requiresReboot', ChoiceType::class, [
                'label' => 'patch.field.requires_reboot',
                'choices' => [
                    'common.yes' => true,
                    'common.no' => false,
                ],
                'expanded' => true,
                'required' => true,
                    'choice_translation_domain' => 'messages',
            ])
            ->add('knownIssues', TextareaType::class, [
                'label' => 'patch.field.known_issues',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'patch.placeholder.known_issues',
                ],
            ])
            ->add('downloadUrl', UrlType::class, [
                'label' => 'patch.field.download_url',
                'required' => false,
                'attr' => [
                    'placeholder' => 'https://vendor.com/patches/...',
                ],
            ])
            ->add('documentationUrl', UrlType::class, [
                'label' => 'patch.field.documentation_url',
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
