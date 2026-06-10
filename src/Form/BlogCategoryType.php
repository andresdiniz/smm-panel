<?php

namespace App\Form;

use App\Entity\BlogCategory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BlogCategoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nome',
                'attr'  => ['placeholder' => 'Ex: Tutoriais'],
            ])
            ->add('slug', TextType::class, [
                'label' => 'Slug (URL)',
                'attr'  => ['placeholder' => 'Ex: tutoriais'],
                'help'  => 'Apenas letras minúsculas, números e hífens.',
            ])
            ->add('description', TextareaType::class, [
                'label'    => 'Descrição',
                'required' => false,
                'attr'     => ['rows' => 3],
            ])
            ->add('color', ColorType::class, [
                'label'    => 'Cor (badge)',
                'required' => false,
                'data'     => '#01696f',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => BlogCategory::class]);
    }
}
