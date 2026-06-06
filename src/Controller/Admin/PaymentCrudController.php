<?php

namespace App\Controller\Admin;

use App\Entity\Payment;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;

class PaymentCrudController extends AbstractCrudController
{
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
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            AssociationField::new('user', 'Usuário'),
            MoneyField::new('amountCents', 'Valor')
                ->setCurrency('BRL')
                ->setStoredAsCents(),
            ChoiceField::new('method', 'Método')
                ->setChoices([
                    'Pix'              => 'pix',
                    'Cartão de Crédito' => 'credit_card',
                    'Cartão de Débito'  => 'debit_card',
                ])
                ->renderAsBadges([
                    'pix'         => 'success',
                    'credit_card' => 'primary',
                    'debit_card'  => 'info',
                ]),
            ChoiceField::new('status', 'Status')
                ->setChoices([
                    'Pendente'  => 'pending',
                    'Aprovado'  => 'approved',
                    'Recusado'  => 'refused',
                    'Estornado' => 'refunded',
                ])
                ->renderAsBadges([
                    'pending'  => 'warning',
                    'approved' => 'success',
                    'refused'  => 'danger',
                    'refunded' => 'secondary',
                ]),
            TextField::new('gatewayTxId', 'ID Gateway')->hideOnForm(),
            MoneyField::new('feeCents', 'Taxa Gateway')
                ->setCurrency('BRL')
                ->setStoredAsCents()
                ->hideOnForm(),
            DateTimeField::new('createdAt', 'Criado em')
                ->hideOnForm()
                ->setFormat('dd/MM/yyyy HH:mm'),
            DateTimeField::new('updatedAt', 'Atualizado em')
                ->hideOnForm()
                ->setFormat('dd/MM/yyyy HH:mm'),
        ];
    }
}
