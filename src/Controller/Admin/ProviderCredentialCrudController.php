<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ProviderCredential;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminCrud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;

#[AdminCrud(routePath: '/admin/credentials', routeName: 'admin_credential')]
class ProviderCredentialCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ProviderCredential::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Credencial de API')
            ->setEntityLabelInPlural('Credenciais de APIs')
            ->setPageTitle(Crud::PAGE_INDEX, 'Credenciais de APIs Externas')
            ->setPageTitle(Crud::PAGE_NEW, 'Nova Credencial')
            ->setPageTitle(Crud::PAGE_EDIT, 'Editar Credencial')
            ->setHelp(Crud::PAGE_INDEX, 'Gerencie chaves e URLs de gateways de pagamento e providers SMM. Nenhuma credencial fica no .env.')
            ->setHelp(Crud::PAGE_NEW, 'Preencha os dados fornecidos pelo provedor. A chave é armazenada criptografada no banco.')
            ->setDefaultSort(['type' => 'ASC', 'slug' => 'ASC'])
            ->showEntityActionsInlined();
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();

        yield ChoiceField::new('type', 'Tipo')
            ->setChoices([
                'Gateway de Pagamento' => ProviderCredential::TYPE_PAYMENT,
                'Provider SMM'         => ProviderCredential::TYPE_SMM,
            ])
            ->renderAsBadges([
                ProviderCredential::TYPE_PAYMENT => 'success',
                ProviderCredential::TYPE_SMM     => 'primary',
            ]);

        yield TextField::new('slug', 'Slug')
            ->setHelp('Ex: asaas, mercadopago, pagbank, smmkings, justanother')
            ->setColumns(4);

        yield TextField::new('baseUrl', 'URL Base da API')
            ->setHelp('Ex: https://sandbox.asaas.com/api/v3')
            ->setColumns(8);

        yield TextField::new('apiKey', 'Chave de API')
            ->setHelp('Token/chave fornecida pelo provedor.')
            ->hideOnIndex();

        yield TextField::new('secretToken', 'Token Secreto (Webhook)')
            ->setHelp('Usado para validar webhooks recebidos. Opcional.')
            ->hideOnIndex()
            ->setRequired(false);

        yield BooleanField::new('active', 'Ativo')
            ->renderAsSwitch(true);

        yield DateTimeField::new('createdAt', 'Criado em')
            ->onlyOnIndex()
            ->setFormat('dd/MM/yyyy HH:mm');

        yield DateTimeField::new('updatedAt', 'Atualizado em')
            ->onlyOnIndex()
            ->setFormat('dd/MM/yyyy HH:mm');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->update(Crud::PAGE_INDEX, Action::NEW, fn (Action $a) => $a->setLabel('Nova credencial')->setIcon('fa fa-plus'))
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $a) => $a->setIcon('fa fa-edit'))
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn (Action $a) => $a->setIcon('fa fa-trash'));
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('type')->setChoices([
                'Gateway de Pagamento' => ProviderCredential::TYPE_PAYMENT,
                'Provider SMM'         => ProviderCredential::TYPE_SMM,
            ]))
            ->add(BooleanFilter::new('active'));
    }
}
