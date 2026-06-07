<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceCategory;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

class ServiceCategoryCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ServiceCategory::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Categoria')
            ->setEntityLabelInPlural('Categorias')
            ->setDefaultSort(['sortOrder' => 'ASC', 'name' => 'ASC'])
            ->setSearchFields(['name', 'slug'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->reorder(Crud::PAGE_INDEX, [Action::DETAIL, Action::EDIT, Action::DELETE]);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(TextFilter::new('name', 'Nome'))
            ->add(BooleanFilter::new('isActive', 'Ativa'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        yield TextField::new('name', 'Nome da Categoria')
            ->setColumns(6);

        yield SlugField::new('slug', 'Slug')
            ->setTargetFieldName('name')
            ->setColumns(6)
            ->setHelp('Gerado automaticamente a partir do nome');

        yield IntegerField::new('sortOrder', 'Ordem')
            ->setColumns(3)
            ->setHelp('Número menor aparece primeiro')
            ->setFormTypeOption('attr', ['min' => 0]);

        yield BooleanField::new('isActive', 'Ativa')
            ->setColumns(3)
            ->renderAsSwitch(true);
    }
}
