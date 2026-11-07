<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\IsmsRegisterEntry;
use App\Enum\RegisterType;
use App\Form\Trait\OwnerPickerFormTrait;
use App\Service\Register\RegisterLinkTypes;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One form for every register; the register type decides which optional
 * fields and which link pickers it shows ({@see RegisterType::fields()},
 * {@see RegisterType::linkFields()}). Link pickers are unmapped and written
 * back to the generic link rows on submit.
 */
final class IsmsRegisterEntryType extends AbstractType implements SectionMapInterface
{
    use OwnerPickerFormTrait;

    public const string LINK_PREFIX = 'link_';

    public function __construct(private readonly RegisterLinkTypes $linkTypes)
    {
    }

    /** Static fields only; the link pickers form the `links` section at render time. */
    public static function getSectionMap(): array
    {
        return [
            'overview' => ['title', 'description', 'category', 'rating', 'reference', 'frequency', 'effectiveFrom'],
            'team' => ['ownerUser', 'ownerPerson', 'deputyPersons'],
            'audit_metadata' => ['nextReviewDate'],
        ];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var RegisterType $type */
        $type = $options['register_type'];

        $builder
            ->add('title', TextType::class, [
                'label' => 'register.type.' . $type->value . '.field.title',
                'attr' => ['maxlength' => 255],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'register.type.' . $type->value . '.field.description',
                'required' => false,
                'attr' => ['rows' => 4],
            ]);

        if ($type->uses('category')) {
            $choices = [];
            foreach ($type->categories() as $value) {
                $choices['register.type.' . $type->value . '.category.' . $value] = $value;
            }
            $builder->add('category', ChoiceType::class, [
                'label' => $type->labelKey('category'),
                'choices' => $choices,
                'required' => false,
                'placeholder' => 'register.placeholder.choose',
            ]);
        }
        if ($type->uses('rating')) {
            $builder->add('rating', ChoiceType::class, [
                'label' => $type->labelKey('rating'),
                'choices' => [
                    'register.rating.1' => 1, 'register.rating.2' => 2, 'register.rating.3' => 3,
                    'register.rating.4' => 4, 'register.rating.5' => 5,
                ],
                'required' => false,
                'placeholder' => 'register.placeholder.choose',
            ]);
        }
        if ($type->uses('reference')) {
            $builder->add('reference', TextType::class, [
                'label' => $type->labelKey('reference'),
                'required' => false,
                'help' => 'register.type.' . $type->value . '.help.reference',
                'attr' => ['maxlength' => 255],
            ]);
        }
        if ($type->uses('frequency')) {
            $choices = [];
            foreach (RegisterType::frequencies() as $value) {
                $choices['register.frequency.' . $value] = $value;
            }
            $builder->add('frequency', ChoiceType::class, [
                'label' => 'register.field.frequency',
                'choices' => $choices,
                'required' => false,
                'placeholder' => 'register.placeholder.choose',
            ]);
        }
        if ($type->uses('effectiveFrom')) {
            $builder->add('effectiveFrom', DateType::class, [
                'label' => $type->labelKey('effectiveFrom'),
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ]);
        }

        $this->addOwnerPicker($builder, [
            'user_field' => 'ownerUser',
            'person_field' => 'ownerPerson',
            'deputies_field' => 'deputyPersons',
            'user_label' => 'register.type.' . $type->value . '.field.owner',
            'translation_domain' => 'register',
            'user_help' => 'register.help.owner',
            'person_help' => 'register.help.owner_person',
            'deputies_help' => 'register.help.deputies',
            'user_placeholder' => 'register.placeholder.owner_user',
            'person_placeholder' => 'register.placeholder.owner_person',
            'person_label' => 'register.field.owner_person',
            'deputies_label' => 'register.field.deputies',
        ]);

        $builder->add('nextReviewDate', DateType::class, [
            'label' => $type === RegisterType::COMMUNICATION ? 'register.field.next_date' : 'register.field.next_review_date',
            'widget' => 'single_text',
            'input' => 'datetime_immutable',
            'required' => false,
            'help' => 'register.help.next_review_date',
        ]);

        foreach ($this->linkTypes->fieldsFor($type) as $field) {
            $this->addLinkPicker($builder, $field);
        }

        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event): void {
            $entry = $event->getData();
            if (!$entry instanceof IsmsRegisterEntry) {
                return;
            }
            foreach ($this->linkTypes->fieldsFor($entry->getType()) as $field) {
                $event->getForm()->get(self::LINK_PREFIX . $field)
                    ->setData($this->linkTypes->load($field, $entry->linkedIds($field)));
            }
        });
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $entry = $event->getData();
            if (!$entry instanceof IsmsRegisterEntry) {
                return;
            }
            foreach ($this->linkTypes->fieldsFor($entry->getType()) as $field) {
                $records = $event->getForm()->get(self::LINK_PREFIX . $field)->getData() ?? [];
                $ids = [];
                foreach ($records as $record) {
                    if ($record !== $entry) {
                        $ids[] = (int) $record->getId();
                    }
                }
                $entry->replaceLinks($field, $ids);
            }
        });
    }

    private function addLinkPicker(FormBuilderInterface $builder, string $field): void
    {
        $entryTypes = $this->linkTypes->entryTypesOf($field);
        $builder->add(self::LINK_PREFIX . $field, EntityType::class, [
            'label' => 'register.link.' . $field,
            'class' => $this->linkTypes->classOf($field),
            'choice_label' => RegisterLinkTypes::labelOf(...),
            'multiple' => true,
            'required' => false,
            'mapped' => false,
            'query_builder' => $entryTypes === null ? null : static fn (EntityRepository $r) => $r->createQueryBuilder('e')
                ->where('e.type IN (:types)')
                ->andWhere('e.archivedAt IS NULL')
                ->setParameter('types', $entryTypes)
                ->orderBy('e.title', \SortDirection::Ascending),
            'attr' => ['data-controller' => 'tom-select'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => IsmsRegisterEntry::class,
            'translation_domain' => 'register',
        ]);
        $resolver->setRequired('register_type');
        $resolver->setAllowedTypes('register_type', RegisterType::class);
    }
}
