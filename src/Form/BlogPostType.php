<?php

namespace App\Form;

use App\Entity\BlogCategory;
use App\Entity\BlogPost;
use App\Entity\BlogTag;
use App\Enum\BlogPostStatus;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BlogPostType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // --- Conteúdo principal ---
            ->add('title', TextType::class, [
                'label' => 'Título',
                'attr'  => [
                    'placeholder'      => 'Título do post',
                    'data-slug-source' => 'true',
                ],
            ])
            ->add('slug', TextType::class, [
                'label' => 'Slug (URL)',
                'attr'  => [
                    'placeholder'     => 'gerado-automaticamente',
                    'data-slug-target'=> 'true',
                ],
                'help' => 'Deixe em branco para gerar automaticamente a partir do título.',
            ])
            ->add('excerpt', TextareaType::class, [
                'label'    => 'Resumo',
                'required' => false,
                'attr'     => [
                    'rows'        => 3,
                    'placeholder' => 'Breve descrição exibida nas listagens…',
                ],
            ])
            ->add('content', TextareaType::class, [
                'label' => 'Conteúdo',
                'attr'  => [
                    'rows'            => 20,
                    'class'           => 'blog-editor',
                    'data-controller' => 'ckeditor',
                ],
            ])
            // --- Mídia / Organização ---
            ->add('thumbnail', UrlType::class, [
                'label'    => 'URL da imagem de capa',
                'required' => false,
                'attr'     => ['placeholder' => 'https://…/imagem.jpg'],
            ])
            ->add('category', EntityType::class, [
                'label'        => 'Categoria',
                'class'        => BlogCategory::class,
                'choice_label' => 'name',
                'placeholder'  => '— Sem categoria —',
                'required'     => false,
            ])
            ->add('tags', EntityType::class, [
                'label'        => 'Tags',
                'class'        => BlogTag::class,
                'choice_label' => 'name',
                'multiple'     => true,
                'expanded'     => false,
                'required'     => false,
                'attr'         => ['class' => 'select2'],
            ])
            // --- Publicação ---
            ->add('status', EnumType::class, [
                'label'    => 'Status',
                'class'    => BlogPostStatus::class,
                'choice_label' => fn(BlogPostStatus $s) => $s->label(),
            ])
            ->add('publishedAt', DateTimeType::class, [
                'label'    => 'Data de publicação',
                'required' => false,
                'widget'   => 'single_text',
                'html5'    => true,
            ])
            // --- SEO ---
            ->add('metaTitle', TextType::class, [
                'label'    => 'Meta título (SEO)',
                'required' => false,
                'attr'     => ['placeholder' => 'Deixe em branco para usar o título do post'],
            ])
            ->add('metaDescription', TextareaType::class, [
                'label'    => 'Meta descrição (SEO)',
                'required' => false,
                'attr'     => ['rows' => 2, 'maxlength' => 300, 'placeholder' => 'Até 300 caracteres'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => BlogPost::class]);
    }
}
