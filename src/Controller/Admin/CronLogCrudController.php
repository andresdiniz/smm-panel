<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CronLog;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;

class CronLogCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return CronLog::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Log de Cron')
            ->setEntityLabelInPlural('Logs de Cron')
            ->setDefaultSort(['startedAt' => 'DESC'])
            ->setSearchFields(['command', 'errorMessage', 'output'])
            ->setPaginatorPageSize(50);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(BooleanFilter::new('success', 'Sucesso'))
            ->add(DateTimeFilter::new('startedAt', 'Iniciado em'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->setLabel('ID')->setMaxLength(6);

        yield TextField::new('command', 'Comando')
            ->setColumns(5);

        yield BooleanField::new('success', 'OK')
            ->renderAsSwitch(false)
            ->setColumns(2);

        yield IntegerField::new('durationMs', 'ms')
            ->setColumns(2)
            ->hideWhenEmpty();

        yield IntegerField::new('itemsProcessed', 'Itens')
            ->setColumns(2)
            ->hideWhenEmpty();

        yield DateTimeField::new('startedAt', 'Iniciado em')
            ->setColumns(3)
            ->hideOnForm();

        yield DateTimeField::new('finishedAt', 'Finalizado em')
            ->setColumns(3)
            ->hideOnForm()
            ->hideOnIndex()
            ->hideWhenEmpty();

        yield TextField::new('errorMessage', 'Erro')
            ->setColumns(12)
            ->hideOnIndex()
            ->hideWhenEmpty();

        yield TextareaField::new('output', 'Output')
            ->setColumns(12)
            ->hideOnIndex()
            ->hideWhenEmpty();
    }
}
