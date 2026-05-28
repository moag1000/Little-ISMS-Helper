<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AuditProgram;
use App\Entity\User;
use App\Form\SectionMapInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * AuditProgramType — SectionPolicy P-2 (>6 fields => SectionMapInterface).
 * Status is lifecycle-managed (Pattern A) — disabled + mapped=false.
 */
final class AuditProgramType extends AbstractType implements SectionMapInterface
{
    public static function getSectionMap(): array
    {
        return [
            'basic_info'     => ['name', 'description', 'objectives', 'scope', 'frequency'],
            'dates'          => ['startDate', 'endDate'],
            'responsibility' => ['programmeOwner'],
            'status'         => ['status'],
        ];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label'       => 'audit_program.field.name',
                'attr'        => ['maxlength' => 255, 'placeholder' => 'audit_program.placeholder.name'],
                'constraints' => [new NotBlank(message: 'audit_program.validation.name_required')],
                'help'        => 'audit_program.help.name',
            ])
            ->add('description', TextareaType::class, [
                'label'    => 'audit_program.field.description',
                'required' => false,
                'attr'     => ['rows' => 3],
                'help'     => 'audit_program.help.description',
            ])
            ->add('objectives', TextareaType::class, [
                'label'    => 'audit_program.field.objectives',
                'required' => false,
                'attr'     => ['rows' => 4],
                'help'     => 'audit_program.help.objectives',
            ])
            ->add('scope', TextareaType::class, [
                'label'    => 'audit_program.field.scope',
                'required' => false,
                'attr'     => ['rows' => 4],
                'help'     => 'audit_program.help.scope',
            ])
            ->add('frequency', ChoiceType::class, [
                'label'                     => 'audit_program.field.frequency',
                'required'                  => false,
                'placeholder'               => 'audit_program.placeholder.frequency',
                'choices'                   => [
                    'audit_program.frequency.annual'    => 'annual',
                    'audit_program.frequency.biennial'  => 'biennial',
                    'audit_program.frequency.quarterly' => 'quarterly',
                    'audit_program.frequency.monthly'   => 'monthly',
                ],
                'choice_translation_domain' => 'audit_program',
                'help'                      => 'audit_program.help.frequency',
            ])
            ->add('startDate', DateType::class, [
                'label'  => 'audit_program.field.start_date',
                'widget' => 'single_text',
                'input'  => 'datetime_immutable',
                'help'   => 'audit_program.help.start_date',
            ])
            ->add('endDate', DateType::class, [
                'label'  => 'audit_program.field.end_date',
                'widget' => 'single_text',
                'input'  => 'datetime_immutable',
                'help'   => 'audit_program.help.end_date',
            ])
            ->add('programmeOwner', EntityType::class, [
                'label'        => 'audit_program.field.programme_owner',
                'class'        => User::class,
                'choice_label' => fn (User $u): string => $u->getFullName() . ' (' . $u->getEmail() . ')',
                'placeholder'  => 'audit_program.placeholder.programme_owner',
                'required'     => false,
                'attr'         => ['data-controller' => 'tom-select'],
                'help'         => 'audit_program.help.programme_owner',
            ])
            ->add('status', ChoiceType::class, [
                'label'                     => 'audit_program.field.status',
                'choices'                   => [
                    'audit_program.status.planning'  => 'planning',
                    'audit_program.status.active'    => 'active',
                    'audit_program.status.completed' => 'completed',
                    'audit_program.status.archived'  => 'archived',
                ],
                'disabled'                  => true,
                'mapped'                    => false,
                'required'                  => false,
                'help'                      => 'audit_program.help.status_readonly',
                'choice_translation_domain' => 'audit_program',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => AuditProgram::class,
            'translation_domain' => 'audit_program',
        ]);
    }
}
