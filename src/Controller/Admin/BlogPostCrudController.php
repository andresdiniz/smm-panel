<?php

namespace App\Controller\Admin;

use App\Entity\BlogPost;
use App\Enum\BlogPostStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

class BlogPostCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return BlogPost::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Post')
            ->setEntityLabelInPlural('Posts do Blog')
            ->setPageTitle(Crud::PAGE_INDEX,  'Blog — Posts')
            ->setPageTitle(Crud::PAGE_NEW,    'Novo Post')
            ->setPageTitle(Crud::PAGE_EDIT,   'Editar Post')
            ->setPageTitle(Crud::PAGE_DETAIL, 'Detalhes do Post')
            ->setDefaultSort(['publishedAt' => 'DESC', 'createdAt' => 'DESC'])
            ->setSearchFields(['title', 'excerpt', 'slug', 'metaTitle'])
            ->setPaginatorPageSize(20)
            ->showEntityActionsInlined();
    }

    public function configureAssets(Assets $assets): Assets
    {
        // JS inline: gera slug a partir do título e contador de chars SEO
        return $assets->addHtmlContentToBody(<<<'JS'
        <script>
        document.addEventListener("DOMContentLoaded", function () {
            // --- slug auto-generate ---
            const titleInput = document.querySelector("input[name*='[title]']");
            const slugInput  = document.querySelector("input[name*='[slug]']");
            function slugify(str) {
                return str.toLowerCase()
                    .normalize("NFD").replace(/[\u0300-\u036f]/g, "")
                    .replace(/[^a-z0-9\s-]/g, "")
                    .trim().replace(/\s+/g, "-");
            }
            if (titleInput && slugInput) {
                titleInput.addEventListener("input", function () {
                    if (slugInput.dataset.manual !== "1") {
                        slugInput.value = slugify(this.value);
                    }
                });
                slugInput.addEventListener("input",  function () {
                    this.dataset.manual = this.value ? "1" : "0";
                });
            }

            // --- SEO char counters ---
            function addCounter(selector, max) {
                const el = document.querySelector(selector);
                if (!el) return;
                const counter = document.createElement("small");
                counter.className = "text-muted ms-1";
                const update = () => {
                    const len = el.value.length;
                    counter.textContent = len + "/" + max;
                    counter.style.color = len > max ? "#dc3545" : (len > max * 0.85 ? "#fd7e14" : "");
                };
                el.parentNode.appendChild(counter);
                el.addEventListener("input", update);
                update();
            }
            addCounter("input[name*='[metaTitle]']",       60);
            addCounter("textarea[name*='[metaDescription]']", 160);
        });
        </script>
        JS);
    }

    public function configureActions(Actions $actions): Actions
    {
        $viewOnSite = Action::new('viewOnSite', 'Ver no site', 'fa fa-arrow-up-right-from-square')
            ->linkToUrl(fn(BlogPost $p) => '/blog/' . $p->getSlug())
            ->setHtmlAttributes(['target' => '_blank'])
            ->displayIf(fn(BlogPost $p) => $p->isPublished())
            ->setCssClass('btn btn-sm btn-outline-secondary');

        $publishNow = Action::new('publishNow', 'Publicar agora', 'fa fa-rocket')
            ->linkToCrudAction('publishNow')
            ->displayIf(fn(BlogPost $p) => !$p->isPublished())
            ->setCssClass('btn btn-sm btn-success');

        return $actions
            ->add(Crud::PAGE_INDEX,  Action::DETAIL)
            ->add(Crud::PAGE_DETAIL, $viewOnSite)
            ->add(Crud::PAGE_EDIT,   $viewOnSite)
            ->add(Crud::PAGE_INDEX,  $publishNow)
            ->update(Crud::PAGE_INDEX, Action::NEW, fn(Action $a) => $a->setIcon('fa fa-plus')->setLabel('Novo Post'))
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn(Action $a) => $a->setIcon('fa fa-pen'))
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn(Action $a) => $a->setIcon('fa fa-trash'));
    }

    public function publishNow(\EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext $context): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        /** @var BlogPost $post */
        $post = $context->getEntity()->getInstance();
        $post->setStatus(BlogPostStatus::Published);
        if (!$post->getPublishedAt()) {
            $post->setPublishedAt(new \DateTimeImmutable());
        }
        $this->container->get('doctrine')->getManager()->flush();
        $this->addFlash('success', '"\'.$post->getTitle().'\" foi publicado!');
        return $this->redirect($context->getReferrer() ?? $this->generateUrl('admin'));
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('category', 'Categoria'))
            ->add(ChoiceFilter::new('status', 'Status')->setChoices([
                'Rascunho'  => BlogPostStatus::Draft->value,
                'Publicado' => BlogPostStatus::Published->value,
                'Arquivado' => BlogPostStatus::Archived->value,
            ]));
    }

    // ------------------------------------------------------------------ helpers

    private function statusField(): ChoiceField
    {
        return ChoiceField::new('status', 'Status')
            ->setChoices([
                'Rascunho'  => BlogPostStatus::Draft,
                'Publicado' => BlogPostStatus::Published,
                'Arquivado' => BlogPostStatus::Archived,
            ])
            ->renderAsBadges([
                BlogPostStatus::Draft->value      => 'warning',
                BlogPostStatus::Published->value  => 'success',
                BlogPostStatus::Archived->value   => 'secondary',
            ]);
    }

    // ------------------------------------------------------------------ fields

    public function configureFields(string $pageName): iterable
    {
        // ── INDEX ────────────────────────────────────────────────────────────
        if ($pageName === Crud::PAGE_INDEX) {
            yield IdField::new('id', '#')->addCssClass('text-muted small');
            yield TextField::new('title', 'Título');
            yield TextField::new('thumbnail', 'Capa')
                ->formatValue(fn($v) => $v
                    ? sprintf('<img src="%s" width="60" height="40" style="object-fit:cover;border-radius:4px" loading="lazy">', htmlspecialchars($v))
                    : '<span class="text-muted">—</span>')
                ->renderAsHtml()
                ->setLabel('Capa');
            yield AssociationField::new('category', 'Categoria');
            yield $this->statusField();
            yield IntegerField::new('views', 'Views');
            yield DateTimeField::new('publishedAt', 'Publicado em')->setFormat('dd/MM/yy HH:mm');
            return;
        }

        // ── DETAIL ───────────────────────────────────────────────────────────
        if ($pageName === Crud::PAGE_DETAIL) {
            yield FormField::addColumn(8);
            yield FormField::addFieldset('Conteúdo');
            yield TextField::new('title', 'Título');
            yield TextField::new('slug', 'Slug');
            yield TextareaField::new('excerpt', 'Resumo');
            yield TextareaField::new('content', 'Conteúdo')->renderAsHtml();

            yield FormField::addColumn(4);
            yield FormField::addFieldset('Publicação');
            yield $this->statusField();
            yield AssociationField::new('category', 'Categoria');
            yield AssociationField::new('tags', 'Tags');
            yield IntegerField::new('views', 'Views');
            yield DateTimeField::new('publishedAt', 'Publicado em');
            yield DateTimeField::new('createdAt', 'Criado em');
            yield DateTimeField::new('updatedAt', 'Atualizado em');

            yield FormField::addFieldset('SEO / Meta');
            yield TextField::new('thumbnail', 'URL da capa');
            yield TextField::new('metaTitle', 'Meta Título');
            yield TextareaField::new('metaDescription', 'Meta Descrição');
            return;
        }

        // ── NEW / EDIT ───────────────────────────────────────────────────────
        // Coluna principal (8/12)
        yield FormField::addColumn(8);

        yield FormField::addFieldset('📝 Conteúdo');
        yield TextField::new('title', 'Título do post')
            ->setRequired(true)
            ->setHelp('O título que aparece no topo do artigo.');
        yield TextField::new('slug', 'Slug (URL)')
            ->setRequired(false)
            ->setHelp('Gerado automaticamente a partir do título. Ex: <code>meu-primeiro-post</code>')
            ->setFormTypeOption('attr', ['placeholder' => 'gerado-automaticamente']);
        yield TextareaField::new('excerpt', 'Resumo / Chamada')
            ->setNumOfRows(3)
            ->setRequired(false)
            ->setHelp('Aparece nos cards de listagem. Máx. ~160 caracteres.');
        yield TextEditorField::new('content', 'Conteúdo')
            ->setNumOfRows(30)
            ->setRequired(true);

        yield FormField::addFieldset('🔍 SEO & Meta');
        yield TextField::new('thumbnail', 'URL da imagem de capa')
            ->setRequired(false)
            ->setHelp('Cole a URL completa da imagem. Ideal: 1200×630 px.');
        yield TextField::new('metaTitle', 'Meta Título')
            ->setRequired(false)
            ->setHelp('Se vazio, usa o título do post. Ideal: até 60 caracteres.');
        yield TextareaField::new('metaDescription', 'Meta Descrição')
            ->setNumOfRows(2)
            ->setRequired(false)
            ->setHelp('Resumo para motores de busca. Ideal: até 160 caracteres.');

        // Coluna lateral (4/12)
        yield FormField::addColumn(4);

        yield FormField::addFieldset('📢 Publicação');
        yield $this->statusField()->setHelp('Rascunho = invisível, Publicado = visível no blog.');
        yield DateTimeField::new('publishedAt', 'Data de publicação')
            ->setRequired(false)
            ->setFormat('dd/MM/yyyy HH:mm')
            ->setHelp('Deixe em branco para usar a data atual ao publicar.');

        yield FormField::addFieldset('📂 Classificação');
        yield AssociationField::new('category', 'Categoria')
            ->setRequired(false);
        yield AssociationField::new('tags', 'Tags')
            ->setFormTypeOption('by_reference', false)
            ->setRequired(false)
            ->setHelp('Selecione uma ou mais tags.');
    }
}
