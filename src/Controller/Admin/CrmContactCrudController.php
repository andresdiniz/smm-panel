<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CrmContact;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

class CrmContactCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return CrmContact::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle('index', 'CRM Contacts')
            ->setEntityLabelInSingular('CRM Contact')
            ->setEntityLabelInPlural('CRM Contacts')
            ->setDefaultSort(['id' => 'DESC'])
            ->setSearchFields(['utmSource', 'utmCampaign', 'utmMedium'])
            ->setRouteName('admin_ea_crm_contact'); // nome único para evitar colisão com CrmController
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            AssociationField::new('user', 'User'),
            ArrayField::new('tags', 'Tags')->hideOnIndex(),
            TextField::new('utmSource', 'UTM Source')->hideOnIndex(),
            TextField::new('utmCampaign', 'UTM Campaign')->hideOnIndex(),
            TextField::new('utmMedium', 'UTM Medium')->hideOnIndex(),
            DateTimeField::new('createdAt', 'Created At')->hideOnForm(),
            DateTimeField::new('updatedAt', 'Updated At')->hideOnForm(),
        ];
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(TextFilter::new('utmSource', 'UTM Source'))
            ->add(TextFilter::new('utmCampaign', 'UTM Campaign'))
            ->add(DateTimeFilter::new('createdAt', 'Created At'));
    }
}
