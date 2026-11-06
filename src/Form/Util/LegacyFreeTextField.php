<?php

declare(strict_types=1);

namespace App\Form\Util;

use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

/**
 * Legacy free-text person fields (owner, responsible person, contact person
 * typed as plain text before the User/Person pickers existed) are shown only
 * while they still hold a value (UI wave F, review P3). An empty one is taken
 * off the form, so new records offer only the pickers; a filled one stays
 * visible until somebody clears it, so no old value disappears unseen.
 */
final class LegacyFreeTextField
{
    public static function hideWhenEmpty(FormBuilderInterface $builder, string $field): void
    {
        $builder->addEventListener(FormEvents::POST_SET_DATA, static function (FormEvent $event) use ($field): void {
            $form = $event->getForm();
            if (!$form->has($field)) {
                return;
            }
            $value = $form->get($field)->getData();
            if ($value === null || (is_string($value) && trim($value) === '')) {
                $form->remove($field);
            }
        });
    }
}
