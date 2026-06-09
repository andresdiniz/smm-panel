<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Payment;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

class PaymentCrudController extends AbstractCrudController
{
    /**
     * renderAsBadges() faz lookup pelo LABEL exibido — chaves devem ser os labels.
     */
    private const METHOD_CHOICES = [
        'Pix'               => 'pix',
        'Cart\u00e3o de Cr\u00e9dito' => 'credit_card',
        'Cart\u00e3o de D\u00e9bito'  => 'debit_card',
    ];

    private const METHOD_BADGE = [
        'Pix'               => 'success',
        'Cart\u00e3o de Cr\u00e9dito' => 'primary',
        'Cart\u00e3o de D\u00e9bito'  => 'info',
    ];

    private const STATUS_CHOICES = [
        'Pendente'  => 'pending',
        'Aprovado'  => 'approved',
        'Recusado'  => 'refused',
        'Estornado' => 'refunded',
    ];

    private const STATUS_BADGE = [
        'Pendente'  => 'warning',
        'Aprovado'  => 'success',
        'Recusado'  => 'danger',
        'Estornado' => 'secondary',
    ];

    public static function getEntityFqcn(): string
    {
        return Payment::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Pagamento')
            ->setEntityLabelInPlural('Pagamentos')
            ->setPageTitle(Crud::PAGE_INDEX, 'Gerenciar Pagamentos')
            ->setDefaultSort(['id' => 'DESC'])
            ->setSearchFields(['id', 'gatewayTxId', 'user.email', 'user.name'])
            ->setPaginatorPageSize(30)
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(
                ChoiceFilter::new('status', 'Status')
                    ->setChoices(self::STATUS_CHOICES)
            )
            ->add(
                ChoiceFilter::new('method', 'M\u00e9todo')
                    ->setChoices(self::METHOD_CHOICES)
            )
            ->add(EntityFilter::new('user', 'Usu\u00e1rio'))
            ->add(DateTimeFilter::new('createdAt', 'Criado em'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')
            ->setLabel('ID')
            ->setMaxLength(6);

        yield AssociationField::new('user', 'Usu\u00e1rio');

        yield MoneyField::new('amountCents', 'Valor')
            ->setCurrency('BRL')
            ->setStoredAsCents();

        yield ChoiceField::new('method', 'M\u00e9todo')
            ->setChoices(self::METHOD_CHOICES)
            ->renderAsBadges(self::METHOD_BADGE);

        yield ChoiceField::new('status', 'Status')
            ->setChoices(self::STATUS_CHOICES)
            ->renderAsBadges(self::STATUS_BADGE);

        // ID Gateway: exibe "\u2014" quando nulo em vez de "Null"
        yield TextField::new('gatewayTxId', 'ID Gateway')
            ->formatValue(static fn ($v) => $v ?? '\u2014')
            ->hideOnForm();

        yield MoneyField::new('feeCents', 'Taxa Gateway')
            ->setCurrency('BRL')
            ->setStoredAsCents()
            ->hideOnForm();

        yield DateTimeField::new('createdAt', 'Criado em')
            ->setFormat('dd/MM/yyyy HH:mm')
            ->hideOnForm();

        // "Atualizado em" s\u00f3 na tela de detalhe — n\u00e3o agrega no index
        yield DateTimeField::new('updatedAt', 'Atualizado em')
            ->setFormat('dd/MM/yyyy HH:mm')
            ->hideOnForm()
            ->hideOnIndex();
    }
}
