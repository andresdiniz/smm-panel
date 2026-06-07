<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Service;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

class ServiceCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Service::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Serviço')
            ->setEntityLabelInPlural('Serviços')
            ->setDefaultSort(['id' => 'DESC'])
            ->setSearchFields(['name', 'category', 'externalServiceId', 'providerSlug'])
            ->setPaginatorPageSize(30)
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->reorder(Crud::PAGE_INDEX, [Action::DETAIL, Action::EDIT, Action::DELETE]);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(TextFilter::new('category', 'Categoria'))
            ->add(TextFilter::new('providerSlug', 'Provedor'))
            ->add(BooleanFilter::new('active', 'Ativo'));
    }

    public function configureFields(string $pageName): iterable
    {
        $isIndex = $pageName === Crud::PAGE_INDEX;

        // Index columns only
        if ($isIndex) {
            yield IdField::new('id');
            yield TextField::new('name', 'Nome');
            yield TextField::new('category', 'Categoria');
            yield TextField::new('providerSlug', 'Provedor');
            yield MoneyField::new('pricePerThousandCents', 'Preço/1k')
                ->setCurrency('BRL')
                ->setStoredAsCents(true);
            yield IntegerField::new('minQty', 'Mín.');
            yield IntegerField::new('maxQty', 'Máx.');
            yield BooleanField::new('active', 'Ativo')->renderAsSwitch(true);
            return;
        }

        // ── Aba: Informações ─────────────────────────────────────────────────
        yield FormField::addTab('Informações')->setIcon('fa fa-info-circle');

        yield TextField::new('name', 'Nome do Serviço')
            ->setColumns(8);

        yield BooleanField::new('active', 'Ativo')
            ->setColumns(4)
            ->renderAsSwitch(true);

        yield TextField::new('category', 'Categoria')
            ->setColumns(6);

        yield TextField::new('providerSlug', 'Provedor')
            ->setColumns(6)
            ->setHelp('Slug do provedor SMM (ex: smmking, medialikes)');

        yield TextareaField::new('description', 'Descrição')
            ->setColumns(12)
            ->setNumOfRows(3);

        // ── Aba: Preços & Limites ─────────────────────────────────────────────
        yield FormField::addTab('Preços & Limites')->setIcon('fa fa-dollar-sign');

        yield MoneyField::new('pricePerThousandCents', 'Preço por 1.000')
            ->setCurrency('BRL')
            ->setStoredAsCents(true)
            ->setColumns(4)
            ->setHelp('Valor em centavos por 1.000 unidades');

        yield IntegerField::new('minQty', 'Qtd. Mínima')
            ->setColumns(4);

        yield IntegerField::new('maxQty', 'Qtd. Máxima')
            ->setColumns(4);

        // ── Aba: Integração ───────────────────────────────────────────────────
        yield FormField::addTab('Integração')->setIcon('fa fa-plug');

        yield TextField::new('externalServiceId', 'ID Externo')
            ->setColumns(6)
            ->setHelp('ID do serviço na API do provedor');

        yield TextField::new('providerSlug', 'Provedor')
            ->setColumns(6);
    }
}
