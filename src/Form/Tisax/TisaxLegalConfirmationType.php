<?php

declare(strict_types=1);

namespace App\Form\Tisax;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;

/**
 * Step 0 — Legal disclaimer form.
 *
 * The user must actively confirm that they hold a valid ENX / VDA
 * licence for the workbook they are about to upload.
 * This confirmation is persisted as a TisaxLicenseConfirmation record.
 */
final class TisaxLegalConfirmationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('licenceConfirmed', CheckboxType::class, [
            'label'       => 'tisax.import.disclaimer.checkbox_label',
            'required'    => true,
            'constraints' => [
                new IsTrue(message: 'tisax.import.disclaimer.must_confirm'),
            ],
            'attr' => [
                'data-testid' => 'tisax-licence-confirm',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'tisax_isa',
        ]);
    }
}
