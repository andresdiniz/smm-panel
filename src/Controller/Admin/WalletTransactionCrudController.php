<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\WalletTransaction;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class WalletTransactionCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return WalletTransaction::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Transaction')
            ->setEntityLabelInPlural('Transactions')
            ->setDefaultSort(['id' => 'DESC'])
            ->setSearchFields(['description']);
    }

    public function configureActions(Actions $actions): Actions
    {
        // Transactions are read-only — no create/edit/delete
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            AssociationField::new('wallet', 'Wallet'),
            ChoiceField::new('type', 'Type')
                ->setChoices([
                    'Credit' => 'credit',
                    'Debit'  => 'debit',
                    'Refund' => 'refund',
                ]),
            IntegerField::new('amountCents', 'Amount (cents)'),
            IntegerField::new('balanceAfterCents', 'Balance After (cents)'),
            TextField::new('description', 'Description'),
            DateTimeField::new('createdAt', 'Created At')->hideOnForm(),
        ];
    }
}
