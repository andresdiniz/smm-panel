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
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

class PaymentCrudController extends AbstractCrudController
{
    private const METHOD_CHOICES = [
        'Pix'               => Payment::METHOD_PIX,
        'Cartão de Crédito' => Payment::METHOD_CREDIT_CARD,
        'Cartão de Débito'  => Payment::METHOD_DEBIT_CARD,
    ];

    private const METHOD_BADGE = [
        'Pix'               => 'success',
        'Cartão de Crédito' => 'primary',
        'Cartão de Débito'  => 'info',
    ];

    private const STATUS_CHOICES = [
        'Pendente'   => Payment::STATUS_PENDING,
        'Aprovado'   => Payment::STATUS_APPROVED,
        'Falhou'     => Payment::STATUS_FAILED,
        'Cancelado'  => Payment::STATUS_CANCELLED,
        'Estornado'  => Payment::STATUS_REFUNDED,
    ];

    private const STATUS_BADGE = [
        'Pendente'   => 'warning',
        'Aprovado'   => 'success',
        'Falhou'     => 'danger',
        'Cancelado'  => 'danger',
        'Estornado'  => 'secondary',
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
            ->setSearchFields(['id', 'externalId', 'user.email', 'user.name'])
            ->setPaginatorPageSize(30)
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        // Acao: Aprovar pagamento manualmente
        $approveAction = Action::new('approvePayment', 'Aprovar', 'fa fa-check-circle')
            ->addCssClass('btn btn-success btn-sm')
            ->setHtmlAttributes(['title' => 'Aprovar este pagamento e creditar o saldo do usuário'])
            ->linkToRoute('admin_payment_approve', fn (Payment $p) => ['id' => $p->getId()])
            ->displayIf(fn (Payment $p) => $p->getStatus() === Payment::STATUS_PENDING);

        // Acao: Cancelar pagamento manualmente
        $cancelAction = Action::new('cancelPayment', 'Cancelar', 'fa fa-times-circle')
            ->addCssClass('btn btn-danger btn-sm')
            ->setHtmlAttributes([
                'title' => 'Cancelar este pagamento',
                'onclick' => 'return confirm("Confirma o cancelamento deste pagamento?")',
            ])
            ->linkToRoute('admin_payment_cancel', fn (Payment $p) => ['id' => $p->getId()])
            ->displayIf(fn (Payment $p) => $p->getStatus() === Payment::STATUS_PENDING);

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_DETAIL, $approveAction)
            ->add(Crud::PAGE_DETAIL, $cancelAction)
            ->add(Crud::PAGE_INDEX, $approveAction)
            ->add(Crud::PAGE_INDEX, $cancelAction)
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
                ChoiceFilter::new('method', 'Método')
                    ->setChoices(self::METHOD_CHOICES)
            )
            ->add(EntityFilter::new('user', 'Usuário'))
            ->add(DateTimeFilter::new('createdAt', 'Criado em'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')
            ->setLabel('ID')
            ->setMaxLength(6);

        yield AssociationField::new('user', 'Usuário');

        yield MoneyField::new('amountCents', 'Valor')
            ->setCurrency('BRL')
            ->setStoredAsCents();

        yield ChoiceField::new('method', 'Método')
            ->setChoices(self::METHOD_CHOICES)
            ->renderAsBadges(self::METHOD_BADGE);

        yield ChoiceField::new('status', 'Status')
            ->setChoices(self::STATUS_CHOICES)
            ->renderAsBadges(self::STATUS_BADGE);

        yield TextField::new('externalId', 'ID Externo')
            ->formatValue(static fn ($v) => $v ?? '—')
            ->hideOnForm();

        yield MoneyField::new('feeCents', 'Taxa Gateway')
            ->setCurrency('BRL')
            ->setStoredAsCents()
            ->hideOnForm();

        yield DateTimeField::new('createdAt', 'Criado em')
            ->setFormat('dd/MM/yyyy HH:mm')
            ->hideOnForm();

        yield DateTimeField::new('paidAt', 'Pago em')
            ->setFormat('dd/MM/yyyy HH:mm')
            ->formatValue(static fn ($v) => $v ? $v->format('d/m/Y H:i') : '—')
            ->hideOnForm()
            ->hideOnIndex();

        yield TextField::new('gatewayResponse', 'Resposta Gateway')
            ->hideOnIndex()
            ->hideOnForm()
            ->setMaxLength(300);
    }
}
