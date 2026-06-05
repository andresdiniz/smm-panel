<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\CrmContact;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;

class CrmContactCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string { return CrmContact::class; }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Contato CRM')
            ->setEntityLabelInPlural('Contatos CRM')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('user', 'Usuário');
        yield ChoiceField::new('status')
            ->setChoices(\App\Enum\CrmContactStatus::cases())
            ->formatValue(fn($v) => $v?->label());
        yield TextField::new('utmSource', 'UTM Source');
        yield TextField::new('utmMedium', 'UTM Medium');
        yield TextField::new('utmCampaign', 'UTM Campaign');
        yield TextField::new('referrer', 'Referrer');
        yield ArrayField::new('tags', 'Tags');
        yield TextareaField::new('notes', 'Observações')->hideOnIndex();
        yield ArrayField::new('events', 'Histórico de eventos')->hideOnIndex();
        yield DateTimeField::new('createdAt', 'Criado em')->hideOnForm();
        yield DateTimeField::new('updatedAt', 'Atualizado em')->hideOnForm();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status'))
            ->add(DateTimeFilter::new('createdAt'));
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }
}
