<?php

namespace App\Controller\Admin;

use App\Entity\BlogPost;
use App\Enum\BlogPostStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

class BlogPostCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return BlogPost::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Post')
            ->setEntityLabelInPlural('Posts do Blog')
            ->setPageTitle(Crud::PAGE_INDEX, 'Gerenciar Blog')
            ->setPageTitle(Crud::PAGE_NEW, '✏️ Novo Post')
            ->setPageTitle(Crud::PAGE_EDIT, '✏️ Editar Post')
            ->setPageTitle(Crud::PAGE_DETAIL, '👁 Visualizar Post')
            ->setDefaultSort(['publishedAt' => 'DESC'])
            ->setSearchFields(['title', 'excerpt', 'slug'])
            ->showEntityActionsInlined()
            ->setHelp(Crud::PAGE_NEW, 'Deixe o slug em branco para gerar automaticamente a partir do título.');
    }

    public function configureActions(Actions $actions): Actions
    {
        $viewOnSite = Action::new('viewOnSite', 'Ver no site', 'fa fa-external-link')
            ->linkToUrl(fn(BlogPost $p) => '/blog/' . $p->getSlug())
            ->setHtmlAttributes(['target' => '_blank'])
            ->displayIf(fn(BlogPost $p) => $p->isPublished());

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_DETAIL, $viewOnSite)
            ->add(Crud::PAGE_EDIT, $viewOnSite);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('category', 'Categoria'))
            ->add(ChoiceFilter::new('status', 'Status')->setChoices([
                'Rascunho'  => BlogPostStatus::Draft->value,
                'Publicado' => BlogPostStatus::Published->value,
                'Arquivado' => BlogPostStatus::Archived->value,
            ]));
    }

    private function statusField(): ChoiceField
    {
        return ChoiceField::new('status', 'Status')
            ->setChoices([
                'Rascunho'  => BlogPostStatus::Draft,
                'Publicado' => BlogPostStatus::Published,
                'Arquivado' => BlogPostStatus::Archived,
            ])
            ->renderAsBadges([
                BlogPostStatus::Draft->value      => 'warning',
                BlogPostStatus::Published->value  => 'success',
                BlogPostStatus::Archived->value   => 'secondary',
            ]);
    }

    public function configureFields(string $pageName): iterable
    {
        // INDEX
        if ($pageName === Crud::PAGE_INDEX) {
            yield IdField::new('id', '#')->addCssClass('text-muted small');
            yield TextField::new('title', 'Título');
            yield AssociationField::new('category', 'Categoria');
            yield $this->statusField();
            yield IntegerField::new('views', 'Views');
            yield DateTimeField::new('publishedAt', 'Publicado em')->setFormat('dd/MM/yy HH:mm');
            return;
        }

        // DETAIL
        if ($pageName === Crud::PAGE_DETAIL) {
            yield IdField::new('id');
            yield TextField::new('title', 'Título');
            yield TextField::new('slug', 'Slug');
            yield AssociationField::new('category', 'Categoria');
            yield AssociationField::new('tags', 'Tags');
            yield $this->statusField();
            yield IntegerField::new('views', 'Views');
            yield DateTimeField::new('publishedAt', 'Publicado em');
            yield DateTimeField::new('createdAt', 'Criado em');
            yield DateTimeField::new('updatedAt', 'Atualizado em');
            yield TextField::new('thumbnail', 'Thumbnail');
            yield TextField::new('metaTitle', 'Meta título');
            yield TextareaField::new('metaDescription', 'Meta descrição');
            yield TextareaField::new('content', 'Conteúdo')->renderAsHtml();
            return;
        }

        // NEW / EDIT
        yield TextField::new('title', 'Título');
        yield TextField::new('slug', 'Slug')->setHelp('Deixe em branco para gerar automaticamente.');
        yield TextareaField::new('excerpt', 'Resumo')->setNumOfRows(3)->setRequired(false);
        yield TextEditorField::new('content', 'Conteúdo')->setNumOfRows(25);
        yield TextField::new('thumbnail', 'URL da capa')->setRequired(false);
        yield AssociationField::new('category', 'Categoria')->setRequired(false);
        yield AssociationField::new('tags', 'Tags')->setFormTypeOption('by_reference', false)->setRequired(false);
        yield $this->statusField();
        yield DateTimeField::new('publishedAt', 'Data de publicação')->setRequired(false)->setFormat('dd/MM/yyyy HH:mm');
        yield TextField::new('metaTitle', 'Meta título (SEO)')->setRequired(false);
        yield TextareaField::new('metaDescription', 'Meta descrição (SEO)')->setNumOfRows(2)->setRequired(false);
    }
}
