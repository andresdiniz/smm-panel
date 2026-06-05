<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\Order;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;

class OrderCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string { return Order::class; }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Pedido')
            ->setEntityLabelInPlural('Pedidos')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureFields(string $pageName): iterable
    {
        yield IntegerField::new('id')->hideOnForm();
        yield AssociationField::new('user', 'Usuário');
        yield AssociationField::new('service', 'Serviço');
        yield ChoiceField::new('status')
            ->setChoices(\App\Enum\OrderStatus::cases())
            ->formatValue(fn($v) => $v?->label());
        yield IntegerField::new('quantity', 'Qtd');
        yield IntegerField::new('priceCents', 'Preço (centavos)');
        yield IntegerField::new('costCents', 'Custo (centavos)');
        yield TextField::new('externalId', 'ID Provider')->hideOnForm();
        yield IntegerField::new('deliveredCount', 'Entregues')->hideOnForm();
        yield DateTimeField::new('createdAt', 'Criado em')->hideOnForm();
        yield DateTimeField::new('completedAt', 'Concluído em')->hideOnForm();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status'))
            ->add(DateTimeFilter::new('createdAt'));
    }
}
