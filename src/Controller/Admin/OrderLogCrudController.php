<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\OrderLog;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminCrud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;

#[AdminCrud(routePath: '/admin/order-log', routeName: 'admin_order_log')]
class OrderLogCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return OrderLog::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Log de Pedido')
            ->setEntityLabelInPlural('Logs de Pedidos')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setPageTitle('index', 'Logs de Retorno do Provider')
            ->setHelp('index', 'Cada linha representa uma chamada feita ao provider. Linhas com erro aparecem destacadas.');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'ID')->setMaxLength(6);
        yield AssociationField::new('order', 'Pedido');
        yield TextField::new('provider', 'Provider');
        yield TextField::new('action', 'Ação');
        yield IntegerField::new('httpStatus', 'HTTP');
        yield IntegerField::new('elapsedMs', 'ms');
        yield TextField::new('errorMessage', 'Erro')
            ->setMaxLength(120)
            ->hideOnIndex();
        yield TextareaField::new('responseBody', 'Resposta JSON')
            ->hideOnIndex()
            ->formatValue(fn ($v) => $v ? json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : null);
        yield DateTimeField::new('createdAt', 'Data')
            ->setFormat('dd/MM/yyyy HH:mm:ss');
    }
}
