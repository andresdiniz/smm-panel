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
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
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
            ->setEntityLabelInSingular('Provedor / API')
            ->setEntityLabelInPlural('Provedores & APIs')
            ->setPageTitle(Crud::PAGE_INDEX, 'Provedores & APIs Externas')
            ->setPageTitle(Crud::PAGE_NEW, 'Novo Provedor')
            ->setPageTitle(Crud::PAGE_EDIT, 'Editar Provedor')
            ->setHelp(Crud::PAGE_INDEX, 'Gerencie chaves e URLs de gateways de pagamento e provedores SMM. Nenhuma credencial fica no .env.')
            ->setHelp(Crud::PAGE_NEW,   'Preencha os dados fornecidos pelo provedor. A chave é armazenada criptografada no banco.')
            ->setDefaultSort(['type' => 'ASC', 'slug' => 'ASC'])
            ->showEntityActionsInlined()
            ->setPaginatorPageSize(25);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->reorder(Crud::PAGE_INDEX, [Action::DETAIL, Action::EDIT, Action::DELETE])
            ->update(Crud::PAGE_INDEX, Action::NEW,    fn (Action $a) => $a->setLabel('Novo provedor')->setIcon('fa fa-plus'))
            ->update(Crud::PAGE_INDEX, Action::EDIT,   fn (Action $a) => $a->setIcon('fa fa-pen')->setLabel('Editar'))
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn (Action $a) => $a->setIcon('fa fa-trash')->setLabel('Excluir'))
            ->update(Crud::PAGE_INDEX, Action::DETAIL, fn (Action $a) => $a->setIcon('fa fa-eye')->setLabel('Ver'));
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('type', 'Tipo')->setChoices([
                'Gateway de Pagamento' => ProviderCredential::TYPE_PAYMENT,
                'Provedor SMM'         => ProviderCredential::TYPE_SMM,
            ]))
            ->add(BooleanFilter::new('active', 'Ativo'));
    }

    public function configureFields(string $pageName): iterable
    {
        $isIndex = $pageName === Crud::PAGE_INDEX;

        // ── Colunas do índice ────────────────────────────────────────────────
        if ($isIndex) {
            yield IdField::new('id');
            yield ChoiceField::new('type', 'Tipo')
                ->setChoices([
                    'Gateway de Pagamento' => ProviderCredential::TYPE_PAYMENT,
                    'Provedor SMM'         => ProviderCredential::TYPE_SMM,
                ])
                ->renderAsBadges([
                    ProviderCredential::TYPE_PAYMENT => 'success',
                    ProviderCredential::TYPE_SMM     => 'primary',
                ]);
            yield TextField::new('slug', 'Slug');
            yield TextField::new('baseUrl', 'URL Base');
            yield BooleanField::new('active', 'Ativo')->renderAsSwitch(true);
            yield DateTimeField::new('updatedAt', 'Atualizado')->setFormat('dd/MM/yy HH:mm');
            return;
        }

        // ── Aba: Identificação ───────────────────────────────────────────────
        yield FormField::addTab('Identificação')->setIcon('fa fa-id-card');

        yield ChoiceField::new('type', 'Tipo')
            ->setChoices([
                'Gateway de Pagamento' => ProviderCredential::TYPE_PAYMENT,
                'Provedor SMM'         => ProviderCredential::TYPE_SMM,
            ])
            ->setColumns(4);

        yield TextField::new('slug', 'Slug')
            ->setColumns(4)
            ->setHelp('Identificador único. Ex: asaas, mercadopago, smmkings');

        yield BooleanField::new('active', 'Ativo')
            ->setColumns(4)
            ->renderAsSwitch(true);

        yield TextField::new('baseUrl', 'URL Base da API')
            ->setColumns(12)
            ->setHelp('Endpoint raiz do provedor. Ex: https://sandbox.asaas.com/api/v3');

        // ── Aba: Credenciais ─────────────────────────────────────────────────
        yield FormField::addTab('Credenciais')->setIcon('fa fa-key');

        yield TextField::new('apiKey', 'Chave de API')
            ->setColumns(12)
            ->setHelp('Token/chave fornecida pelo provedor. Armazenada de forma segura.');

        yield TextField::new('secretToken', 'Token Secreto (Webhook)')
            ->setColumns(12)
            ->setHelp('Usado para validar webhooks recebidos pelo sistema. Opcional.')
            ->setRequired(false);
    }
}
