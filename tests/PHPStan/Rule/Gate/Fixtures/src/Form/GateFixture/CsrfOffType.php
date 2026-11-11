<?php

declare(strict_types=1);

namespace App\Form\GateFixture;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Gate 65 fixture (T4 C8): a FormType that switches CSRF off for every form of its kind. */
final class CsrfOffType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false, // expect: gate65.formCsrfOff
        ]);
        $resolver->setDefault('csrf_protection', false); // expect: gate65.formCsrfOff
    }
}

/** Control: CSRF stays on. */
final class CsrfOnType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_protection' => true]);
    }
}

/** Control: a reviewed API form. */
final class CsrfReviewedType extends AbstractType
{
    // @csrf-exempt: bound only to the bearer-token API, which carries no cookie
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_protection' => false]);
    }
}
