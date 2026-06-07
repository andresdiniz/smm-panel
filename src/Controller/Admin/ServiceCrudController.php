<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Service;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

class ServiceCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Service::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Service')
            ->setEntityLabelInPlural('Services')
            ->setDefaultSort(['id' => 'DESC'])
            ->setSearchFields(['name', 'category', 'externalServiceId', 'providerSlug']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(TextFilter::new('category'))
            ->add(TextFilter::new('providerSlug'))
            ->add(BooleanFilter::new('active'));
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),

            TextField::new('category', 'Category'),
            TextField::new('name', 'Name'),
            TextareaField::new('description', 'Description')->hideOnIndex(),

            IntegerField::new('pricePerThousandCents', 'Price/1k (cents)')
                ->setHelp('Price in cents per 1000 units'),
            IntegerField::new('minQty', 'Min Qty'),
            IntegerField::new('maxQty', 'Max Qty'),

            BooleanField::new('active', 'Active'),

            TextField::new('externalServiceId', 'External Service ID')->hideOnIndex(),
            TextField::new('providerSlug', 'Provider Slug'),
        ];
    }
}
