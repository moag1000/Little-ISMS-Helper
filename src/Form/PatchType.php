<?php

namespace App\Form;

use App\Entity\Patch;
use App\Entity\User;
use App\Entity\Vulnerability;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
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
            ->add('patchIdentifier', TextType::class, [
                'label' => 'patch.field.patch_identifier',
                'required' => true,
                'attr' => [
                    'maxlength' => 100,
                    'placeholder' => 'patch.placeholder.patch_identifier',
                ],
                'help' => 'patch.help.patch_identifier',
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
            ->add('patchType', ChoiceType::class, [
                'label' => 'patch.field.patch_type',
                'choices' => [
                    'patch.type.security' => 'security',
                    'patch.type.bugfix' => 'bugfix',
                    'patch.type.feature' => 'feature',
                    'patch.type.critical' => 'critical',
                ],
                'required' => true,
                'help' => 'patch.help.patch_type',
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'patch.field.status',
                'choices' => [
                    'patch.status.available' => 'available',
                    'patch.status.tested' => 'tested',
                    'patch.status.approved' => 'approved',
                    'patch.status.deployed' => 'deployed',
                    'patch.status.failed' => 'failed',
                    'patch.status.rollback' => 'rollback',
                ],
                'required' => true,
            ])
            ->add('relatedVulnerabilities', EntityType::class, [
                'label' => 'patch.field.related_vulnerabilities',
                'class' => Vulnerability::class,
                'choice_label' => function(Vulnerability $vuln) {
                    return ($vuln->getCveId() ?? 'N/A') . ' - ' . $vuln->getTitle();
                },
                'multiple' => true,
                'required' => false,
                'help' => 'patch.help.related_vulnerabilities',
                'attr' => [
                    'class' => 'form-select',
                    'size' => 5,
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
            ->add('vendor', TextType::class, [
                'label' => 'patch.field.vendor',
                'required' => false,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'patch.placeholder.vendor',
                ],
            ])
            ->add('affectedSystems', TextareaType::class, [
                'label' => 'patch.field.affected_systems',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'patch.placeholder.affected_systems',
                ],
                'help' => 'patch.help.affected_systems',
            ])
            ->add('releaseDate', DateType::class, [
                'label' => 'patch.field.release_date',
                'required' => false,
                'widget' => 'single_text',
            ])
            ->add('plannedDeploymentDate', DateType::class, [
                'label' => 'patch.field.planned_deployment_date',
                'required' => false,
                'widget' => 'single_text',
                'help' => 'patch.help.planned_deployment_date',
            ])
            ->add('actualDeploymentDate', DateType::class, [
                'label' => 'patch.field.actual_deployment_date',
                'required' => false,
                'widget' => 'single_text',
            ])
            ->add('testResults', TextareaType::class, [
                'label' => 'patch.field.test_results',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'patch.placeholder.test_results',
                ],
                'help' => 'patch.help.test_results',
            ])
            ->add('rollbackProcedure', TextareaType::class, [
                'label' => 'patch.field.rollback_procedure',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'patch.placeholder.rollback_procedure',
                ],
                'help' => 'patch.help.rollback_procedure',
            ])
            ->add('installationInstructions', TextareaType::class, [
                'label' => 'patch.field.installation_instructions',
                'required' => false,
                'attr' => [
                    'rows' => 5,
                    'placeholder' => 'patch.placeholder.installation_instructions',
                ],
            ])
            ->add('downloadUrl', UrlType::class, [
                'label' => 'patch.field.download_url',
                'required' => false,
                'attr' => [
                    'placeholder' => 'https://vendor.com/patches/...',
                ],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'patch.field.notes',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                ],
            ])
            ->add('responsiblePerson', EntityType::class, [
                'label' => 'patch.field.responsible_person',
                'class' => User::class,
                'choice_label' => 'email',
                'placeholder' => 'patch.placeholder.responsible_person',
                'required' => false,
                'help' => 'patch.help.responsible_person',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Patch::class,
        ]);
    }
}
