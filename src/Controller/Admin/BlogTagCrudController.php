<?php

namespace App\Controller\Admin;

use App\Entity\BlogTag;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class BlogTagCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return BlogTag::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Tag')
            ->setEntityLabelInPlural('Tags do Blog')
            ->setPageTitle(Crud::PAGE_INDEX, '🏷️ Tags do Blog')
            ->setPageTitle(Crud::PAGE_NEW,   '🏷️ Nova Tag')
            ->setPageTitle(Crud::PAGE_EDIT,  '🏷️ Editar Tag')
            ->setDefaultSort(['name' => 'ASC'])
            ->setSearchFields(['name', 'slug'])
            ->setPaginatorPageSize(50)
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
            ->update(Crud::PAGE_INDEX, Action::NEW,    fn(Action $a) => $a->setIcon('fa fa-plus')->setLabel('Nova Tag'))
            ->update(Crud::PAGE_INDEX, Action::EDIT,   fn(Action $a) => $a->setIcon('fa fa-pen')->setLabel(''))
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn(Action $a) => $a->setIcon('fa fa-trash')->setLabel(''));
    }

    public function configureFields(string $pageName): iterable
    {
        if ($pageName === Crud::PAGE_INDEX) {
            yield IdField::new('id', '#')->addCssClass('text-muted small');
            yield TextField::new('name', 'Tag')
                ->formatValue(fn($v) => sprintf(
                    '<span style="display:inline-block;padding:.2rem .65rem;background:#f1f3f5;border-radius:999px;font-size:.82rem;font-weight:500;">%s</span>',
                    htmlspecialchars('#' . $v)
                ))->renderAsHtml();
            yield TextField::new('slug', 'Slug');
            yield IntegerField::new('posts', 'Posts')
                ->formatValue(fn($v, $entity) => $entity->getPosts()->count())
                ->setLabel('Posts');
            return;
        }

        yield FormField::addFieldset('🏷️ Dados da Tag');
        yield TextField::new('name', 'Nome da tag')
            ->setRequired(true)
            ->setColumns(6)
            ->setHelp('Ex: <code>Instagram</code>, <code>TikTok</code>, <code>SEO</code>');
        yield TextField::new('slug', 'Slug')
            ->setRequired(false)
            ->setColumns(6)
            ->setHelp('Gerado automaticamente. Ex: <code>instagram</code>')
            ->setFormTypeOption('attr', ['placeholder' => 'gerado-automaticamente', 'autocomplete' => 'off']);
    }
}
