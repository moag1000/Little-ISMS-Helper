<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form for database configuration during setup wizard.
 */
class DatabaseConfigurationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'setup.database.type',
                'choices' => [
                    'MySQL' => 'mysql',
                    'MariaDB' => 'mariadb',
                    'PostgreSQL' => 'postgresql',
                    'SQLite' => 'sqlite',
                ],
                'data' => 'mysql',
                'required' => true,
                'attr' => [
                    'class' => 'form-select',
                    'data-controller' => 'database-type',
                    'data-action' => 'change->database-type#toggle',
                ],
                'help' => 'setup.database.type_help',
            ])
            ->add('host', TextType::class, [
                'label' => 'setup.database.host',
                'data' => 'localhost',
                'required' => false,
                'constraints' => [
                    new Assert\When(
                        expression: 'this.getParent()["type"].getData() !== "sqlite"',
                        constraints: [new Assert\NotBlank()],
                    ),
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'localhost',
                    'data-database-type-target' => 'hostField',
                ],
                'help' => 'setup.database.host_help',
            ])
            ->add('port', IntegerType::class, [
                'label' => 'setup.database.port',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => '3306',
                    'data-database-type-target' => 'portField',
                ],
                'help' => 'setup.database.port_help',
            ])
            ->add('name', TextType::class, [
                'label' => 'setup.database.name',
                'data' => 'little_isms_helper',
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Regex([
                        'pattern' => '/^[a-zA-Z0-9_]+$/',
                        'message' => 'setup.database.name_invalid',
                    ]),
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'little_isms_helper',
                ],
                'help' => 'setup.database.name_help',
            ])
            ->add('user', TextType::class, [
                'label' => 'setup.database.user',
                'required' => false,
                'constraints' => [
                    new Assert\When(
                        expression: 'this.getParent()["type"].getData() !== "sqlite"',
                        constraints: [new Assert\NotBlank()],
                    ),
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'root',
                    'data-database-type-target' => 'userField',
                ],
                'help' => 'setup.database.user_help',
            ])
            ->add('password', PasswordType::class, [
                'label' => 'setup.database.password',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => '••••••••',
                    'data-database-type-target' => 'passwordField',
                ],
                'help' => 'setup.database.password_help',
            ])
            ->add('serverVersion', TextType::class, [
                'label' => 'setup.database.server_version',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => '8.0',
                    'data-database-type-target' => 'versionField',
                ],
                'help' => 'setup.database.server_version_help',
            ]);

        // Add event listener to set default values based on database type
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            $type = $data['type'] ?? 'mysql';

            // Set default port if not provided
            if (empty($data['port'])) {
                $data['port'] = match ($type) {
                    'postgresql' => 5432,
                    'mysql', 'mariadb' => 3306,
                    default => null,
                };
            }

            // Set default server version if not provided
            if (empty($data['serverVersion'])) {
                $data['serverVersion'] = match ($type) {
                    'postgresql' => '14',
                    'mariadb' => '10.6',
                    'mysql' => '8.0',
                    default => null,
                };
            }

            // Set default user if not provided (not for SQLite)
            if ($type !== 'sqlite' && empty($data['user'])) {
                $data['user'] = match ($type) {
                    'postgresql' => 'postgres',
                    default => 'root',
                };
            }

            $event->setData($data);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'database_config',
        ]);
    }
}
