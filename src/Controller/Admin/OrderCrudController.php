<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Order;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

class OrderCrudController extends AbstractCrudController
{
    private const STATUS_CHOICES = [
        'Pendente'     => Order::STATUS_PENDING,
        'Processando'  => Order::STATUS_PROCESSING,
        'Em andamento' => Order::STATUS_IN_PROGRESS,
        'Concluído'    => Order::STATUS_COMPLETED,
        'Parcial'      => Order::STATUS_PARTIAL,
        'Cancelado'    => Order::STATUS_CANCELLED,
        'Reembolsado'  => Order::STATUS_REFUNDED,
    ];

    private const STATUS_BADGE = [
        'Pendente'     => 'warning',
        'Processando'  => 'info',
        'Em andamento' => 'primary',
        'Concluído'    => 'success',
        'Parcial'      => 'secondary',
        'Cancelado'    => 'danger',
        'Reembolsado'  => 'dark',
    ];

    public static function getEntityFqcn(): string { return Order::class; }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Pedido')
            ->setEntityLabelInPlural('Pedidos')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['id', 'targetUrl', 'externalOrderId', 'user.email', 'user.name'])
            ->setPaginatorPageSize(30)
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->reorder(Crud::PAGE_INDEX, [Action::DETAIL, Action::EDIT, Action::DELETE]);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status', 'Status')->setChoices(self::STATUS_CHOICES))
            ->add(EntityFilter::new('user', 'Usuário'))
            ->add(EntityFilter::new('service', 'Serviço'))
            ->add(DateTimeFilter::new('createdAt', 'Criado em'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->setLabel('ID')->setMaxLength(6);

        yield AssociationField::new('user', 'Usuário')->setColumns(4);
        yield AssociationField::new('service', 'Serviço')->setColumns(4);

        yield ChoiceField::new('status', 'Status')
            ->setChoices(self::STATUS_CHOICES)
            ->renderAsBadges(self::STATUS_BADGE)
            ->setColumns(4);

        yield MoneyField::new('amountCents', 'Valor')
            ->setCurrency('BRL')
            ->setStoredAsCents(true)
            ->setColumns(3);

        yield IntegerField::new('quantity', 'Qtd')->setColumns(3);

        yield UrlField::new('targetUrl', 'URL alvo')
            ->setColumns(6)
            ->hideOnIndex();

        // Campos nullable — exibe '—' quando vazio
        yield TextField::new('externalOrderId', 'ID Externo')
            ->setColumns(4)
            ->hideOnIndex()
            ->formatValue(fn ($v) => $v ?? '—');

        yield IntegerField::new('startCount', 'Start Count')
            ->setColumns(3)
            ->hideOnIndex()
            ->formatValue(fn ($v) => $v !== null ? $v : '—');

        yield IntegerField::new('remains', 'Restante')
            ->setColumns(3)
            ->hideOnIndex()
            ->formatValue(fn ($v) => $v !== null ? $v : '—');

        yield DateTimeField::new('createdAt', 'Criado em')
            ->setColumns(4)
            ->hideOnForm();

        yield DateTimeField::new('updatedAt', 'Atualizado em')
            ->setColumns(4)
            ->hideOnForm()
            ->hideOnIndex()
            ->formatValue(fn ($v) => $v ? $v->format('d/m/Y H:i:s') : '—');

        yield DateTimeField::new('completedAt', 'Concluído em')
            ->setColumns(4)
            ->hideOnForm()
            ->hideOnIndex()
            ->formatValue(fn ($v) => $v ? $v->format('d/m/Y H:i:s') : '—');

        // ── Logs do Provider: só na página de detalhe ──
        if ($pageName === Crud::PAGE_DETAIL) {
            yield TextareaField::new('targetUrl', 'Logs do Provider')
                ->setTemplatePath('admin/fields/order_logs.html.twig')
                ->setColumns(12)
                ->hideOnForm()
                ->hideOnIndex();
        }
    }
}
