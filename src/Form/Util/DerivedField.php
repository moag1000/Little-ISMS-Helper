<?php

declare(strict_types=1);

namespace App\Form\Util;

use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

/**
 * A value the tool derives from other records (P5): treatment progress from
 * the controls, asset availability from the BIA. While a derivation applies
 * the field is read-only and says where the value comes from; changes arrive
 * as a suggestion in the queue. Without a derivation the field stays editable.
 */
final class DerivedField
{
    private const array KEPT_OPTIONS = ['label', 'attr', 'row_attr', 'label_attr', 'translation_domain'];

    /**
     * @param callable(mixed): (array{0: string, 1: array<string, string|int>}|null) $derivedFrom
     *        returns [help key, help parameters] while the field is derived, else null
     */
    public static function readOnlyWhen(FormBuilderInterface $builder, string $field, callable $derivedFrom): void
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event) use ($field, $derivedFrom): void {
            $form = $event->getForm();
            $data = $event->getData();
            if (!$form->has($field) || $data === null) {
                return;
            }
            $source = $derivedFrom($data);
            if ($source === null) {
                return;
            }
            $config = $form->get($field)->getConfig();
            $keep = array_intersect_key($config->getOptions(), array_flip(self::KEPT_OPTIONS));
            $form->add($field, $config->getType()->getInnerType()::class, [
                ...$keep,
                'disabled' => true,
                'required' => false,
                'help' => $source[0],
                'help_translation_parameters' => $source[1],
            ]);
        });
    }
}
