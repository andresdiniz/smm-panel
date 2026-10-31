<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label'       => 'Nome completo',
                'constraints' => [
                    new NotBlank(['message' => 'Informe seu nome.']),
                    new Length(['min' => 2, 'max' => 120, 'minMessage' => 'Mínimo 2 caracteres.']),
                ],
            ])
            ->add('email', EmailType::class, [
                'label'       => 'E-mail',
                'constraints' => [
                    new NotBlank(['message' => 'Informe seu e-mail.']),
                    new Email(['message' => 'E-mail inválido.']),
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type'            => PasswordType::class,
                'mapped'          => false,
                'first_options'   => ['label' => 'Senha'],
                'second_options'  => ['label' => 'Confirmar senha'],
                'invalid_message' => 'As senhas não coincidem.',
                'constraints'     => [
                    new NotBlank(['message' => 'Defina uma senha.']),
                    new Length([
                        'min'        => 8,
                        'max'        => 4096,
                        'minMessage' => 'A senha deve ter ao menos 8 caracteres.',
                    ]),
                ],
            ])
            ->add('agreeTerms', CheckboxType::class, [
                'mapped'      => false,
                'label'       => 'Aceito os termos de uso',
                'constraints' => [
                    new IsTrue(['message' => 'Você precisa aceitar os termos para continuar.']),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
