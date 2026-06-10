<?php

namespace App\Form;

use App\Entity\BlogTag;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BlogTagType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nome da tag',
                'attr'  => ['placeholder' => 'Ex: Symfony'],
            ])
            ->add('slug', TextType::class, [
                'label' => 'Slug',
                'attr'  => ['placeholder' => 'Ex: symfony'],
                'help'  => 'Gerado automaticamente a partir do nome se deixado em branco.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => BlogTag::class]);
    }
}
