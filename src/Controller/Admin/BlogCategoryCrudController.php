<?php

namespace App\Controller\Admin;

use App\Entity\BlogCategory;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ColorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class BlogCategoryCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return BlogCategory::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Categoria')
            ->setEntityLabelInPlural('Categorias do Blog')
            ->setPageTitle(Crud::PAGE_INDEX,  '🗂️ Categorias do Blog')
            ->setPageTitle(Crud::PAGE_NEW,    '🗂️ Nova Categoria')
            ->setPageTitle(Crud::PAGE_EDIT,   '🗂️ Editar Categoria')
            ->setDefaultSort(['name' => 'ASC'])
            ->setSearchFields(['name', 'slug'])
            ->setPaginatorPageSize(30)
            ->showEntityActionsInlined();
    }

    public function configureAssets(Assets $assets): Assets
    {
        return $assets->addHtmlContentToBody(<<<'HTML'
        <script>
        document.addEventListener("DOMContentLoaded", function () {
            const nameInput = document.querySelector("[name$='[name]']");
            const slugInput = document.querySelector("[name$='[slug]']");
            function slugify(str) {
                return str.toLowerCase()
                    .normalize("NFD").replace(/[\u0300-\u036f]/g, "")
                    .replace(/[ç]/g, "c")
                    .replace(/[^a-z0-9\s-]/g, "")
                    .trim().replace(/\s+/g, "-").replace(/-+/g, "-");
            }
            if (nameInput && slugInput) {
                if (!slugInput.value) slugInput.dataset.auto = "1";
                nameInput.addEventListener("input", function () {
                    if (slugInput.dataset.auto === "1") slugInput.value = slugify(this.value);
                });
                slugInput.addEventListener("input", function () {
                    slugInput.dataset.auto = this.value ? "0" : "1";
                });
                slugInput.addEventListener("blur", function () {
                    if (this.value) this.value = slugify(this.value);
                });
            }
        });
        </script>
        HTML);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->update(Crud::PAGE_INDEX, Action::NEW,    fn(Action $a) => $a->setIcon('fa fa-plus')->setLabel('Nova Categoria'))
            ->update(Crud::PAGE_INDEX, Action::EDIT,   fn(Action $a) => $a->setIcon('fa fa-pen')->setLabel(''))
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn(Action $a) => $a->setIcon('fa fa-trash')->setLabel(''));
    }

    public function configureFields(string $pageName): iterable
    {
        if ($pageName === Crud::PAGE_INDEX) {
            yield IdField::new('id', '#')->addCssClass('text-muted small');
            yield TextField::new('name', 'Nome')
                ->formatValue(fn($v, $entity) => sprintf(
                    '<span style="display:inline-flex;align-items:center;gap:.4rem;"><span style="width:12px;height:12px;border-radius:50%%;background:%s;flex-shrink:0;"></span>%s</span>',
                    htmlspecialchars($entity->getColor() ?? '#6c757d'),
                    htmlspecialchars($v)
                ))->renderAsHtml();
            yield TextField::new('slug', 'Slug');
            yield TextField::new('color', 'Cor')
                ->formatValue(fn($v) => $v
                    ? sprintf('<span style="display:inline-flex;align-items:center;gap:.5rem;"><span style="width:20px;height:20px;border-radius:4px;background:%s;border:1px solid #dee2e6;"></span><code>%s</code></span>', htmlspecialchars($v), htmlspecialchars($v))
                    : '—')
                ->renderAsHtml();
            yield IntegerField::new('posts', 'Posts')
                ->formatValue(fn($v, $entity) => $entity->getPosts()->count())
                ->setLabel('Posts');
            return;
        }

        yield FormField::addFieldset('📂 Dados da Categoria');
        yield TextField::new('name', 'Nome')
            ->setRequired(true)
            ->setColumns(6)
            ->setHelp('Nome exibido no blog.');
        yield TextField::new('slug', 'Slug')
            ->setRequired(false)
            ->setColumns(6)
            ->setHelp('Gerado automaticamente. Ex: <code>marketing-digital</code>')
            ->setFormTypeOption('attr', ['placeholder' => 'gerado-automaticamente', 'autocomplete' => 'off']);
        yield TextField::new('color', 'Cor do badge (hex)')
            ->setRequired(false)
            ->setColumns(4)
            ->setHelp('Ex: <code>#3b82f6</code>')
            ->setFormTypeOption('attr', ['placeholder' => '#3b82f6', 'type' => 'color']);
        yield TextareaField::new('description', 'Descrição')
            ->setRequired(false)
            ->setColumns(8)
            ->setNumOfRows(2)
            ->setHelp('Aparece na página de listagem da categoria.');
    }
}
