<?php

declare(strict_types=1);

namespace App\Form\Util;

use Symfony\Component\Form\FormInterface;

/**
 * Turn a field of a built form read-only for this person (S22), keeping every
 * option it was built with (entity class, choices, labels) — unlike
 * {@see DerivedField}, which rebuilds a derived field from a few options.
 * Disabled fields are not submitted; the stored value stays and is shown.
 */
final class ReadOnlyField
{
    /**
     * @param FormInterface<mixed> $form
     * @param string|null $help a translated help text saying who changes the field
     */
    public static function apply(FormInterface $form, string $name, ?string $help = null): void
    {
        if (!$form->has($name)) {
            return;
        }
        $config = $form->get($name)->getConfig();
        $options = [...$config->getOptions(), 'disabled' => true, 'required' => false];
        if ($help !== null) {
            // already translated: the field's own domain has no such key, and the translator hands back an unknown text unchanged
            $options['help'] = $help;
            $options['help_translation_parameters'] = [];
        }
        // @intentional-bind: disabled fields are not submitted, the stored value stays and is shown read-only
        $form->add($name, $config->getType()->getInnerType()::class, $options);
    }
}
