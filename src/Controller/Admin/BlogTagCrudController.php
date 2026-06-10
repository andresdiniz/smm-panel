<?php

namespace App\Controller\Admin;

use App\Entity\BlogTag;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;

class BlogTagCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return BlogTag::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Tag')
            ->setEntityLabelInPlural('Tags do Blog')
            ->setPageTitle(Crud::PAGE_INDEX, 'Tags do Blog')
            ->setPageTitle(Crud::PAGE_NEW, 'Nova Tag')
            ->setPageTitle(Crud::PAGE_EDIT, 'Editar Tag')
            ->setDefaultSort(['name' => 'ASC'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('name', 'Nome');
        yield TextField::new('slug', 'Slug');
    }
}
