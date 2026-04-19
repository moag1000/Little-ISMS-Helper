<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AuditFreeze;
use App\Repository\ComplianceFrameworkRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form for creating an AuditFreeze.
 *
 * Note: stichtag max=today is enforced both here and server-side in the
 * controller. freezeName must have at least 5 characters — a generous
 * guard against meaningless freeze names like "Q1" that later auditors
 * cannot map to a specific audit.
 */
class AuditFreezeType extends AbstractType
{
    public const PURPOSE_CHOICES = [
        'audit_freeze.purpose.certification' => AuditFreeze::PURPOSE_CERTIFICATION,
        'audit_freeze.purpose.surveillance' => AuditFreeze::PURPOSE_SURVEILLANCE,
        'audit_freeze.purpose.internal_audit' => AuditFreeze::PURPOSE_INTERNAL_AUDIT,
        'audit_freeze.purpose.management_review' => AuditFreeze::PURPOSE_MANAGEMENT_REVIEW,
        'audit_freeze.purpose.other' => AuditFreeze::PURPOSE_OTHER,
    ];

    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $frameworkChoices = [];
        foreach ($this->frameworkRepository->findActiveFrameworks() as $framework) {
            $label = sprintf('%s (%s)', (string) $framework->getName(), (string) $framework->getCode());
            $frameworkChoices[$label] = (string) $framework->getCode();
        }

        $today = new \DateTimeImmutable('today');

        $builder
            ->add('freezeName', TextType::class, [
                'label' => 'audit_freeze.form.name',
                'required' => true,
                'attr' => ['maxlength' => 200, 'class' => 'form-control'],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(min: 5, max: 200),
                ],
            ])
            ->add('stichtag', DateType::class, [
                'label' => 'audit_freeze.form.stichtag',
                'widget' => 'single_text',
                'required' => true,
                'input' => 'datetime_immutable',
                'attr' => [
                    'max' => $today->format('Y-m-d'),
                    'class' => 'form-control',
                ],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\LessThanOrEqual(
                        value: $today,
                        message: 'audit_freeze.form.error.stichtag_future',
                    ),
                ],
            ])
            ->add('frameworkCodes', ChoiceType::class, [
                'label' => 'audit_freeze.form.frameworks',
                'choices' => $frameworkChoices,
                'multiple' => true,
                'expanded' => true,
                'required' => true,
                'constraints' => [
                    new Assert\Count(
                        min: 1,
                        minMessage: 'audit_freeze.form.error.no_framework',
                    ),
                ],
            ])
            ->add('purpose', ChoiceType::class, [
                'label' => 'audit_freeze.form.purpose',
                'choices' => self::PURPOSE_CHOICES,
                'required' => true,
                'attr' => ['class' => 'form-select'],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'audit_freeze.form.notes',
                'required' => false,
                'attr' => ['rows' => 4, 'class' => 'form-control'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AuditFreeze::class,
            'translation_domain' => 'audit_freeze',
        ]);
    }
}
