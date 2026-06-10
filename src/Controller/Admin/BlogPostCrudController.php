<?php

namespace App\Controller\Admin;

use App\Entity\BlogPost;
use App\Entity\BlogCategory;
use App\Entity\BlogTag;
use App\Enum\BlogPostStatus;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\HttpFoundation\RedirectResponse;

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
            ->setPageTitle(Crud::PAGE_INDEX,  '✍️ Posts do Blog')
            ->setPageTitle(Crud::PAGE_NEW,    '✍️ Novo Post')
            ->setPageTitle(Crud::PAGE_EDIT,   '✏️ Editar Post')
            ->setPageTitle(Crud::PAGE_DETAIL, '📄 Detalhes do Post')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['title', 'excerpt', 'slug', 'metaTitle'])
            ->setPaginatorPageSize(20)
            ->showEntityActionsInlined()
            ->setHelp(Crud::PAGE_NEW, '<div class="alert alert-info"><b>💡 Dica:</b> O slug é gerado automaticamente a partir do título. Você pode criar categorias e tags diretamente neste formulário clicando em <b>"+ Criar"</b>.</div>')
            ->setHelp(Crud::PAGE_EDIT, '<div class="alert alert-info"><b>💡 Dica:</b> Você pode criar categorias e tags diretamente neste formulário clicando em <b>"+ Criar"</b>.</div>');
    }

    public function configureAssets(Assets $assets): Assets
    {
        return $assets->addHtmlContentToBody(<<<'HTML'
        <style>
            /* ── Card de preview de capa ── */
            #thumb-preview { display:none; margin-top:.5rem; border-radius:8px; overflow:hidden; max-height:200px; }
            #thumb-preview img { width:100%; height:200px; object-fit:cover; }
            /* ── Contador SEO ── */
            .seo-counter { font-size:.75rem; color:#6c757d; margin-top:.25rem; display:block; }
            .seo-counter.warn  { color:#fd7e14; }
            .seo-counter.error { color:#dc3545; }
            /* ── Badge status no select ── */
            .ea-field-status select { font-weight:600; }
        </style>
        <script>
        document.addEventListener("DOMContentLoaded", function () {

            /* ══ 1. SLUG automático ══════════════════════════════════════════ */
            const titleInput = document.querySelector("[name$='[title]']");
            const slugInput  = document.querySelector("[name$='[slug]']");

            function slugify(str) {
                return str
                    .toLowerCase()
                    .normalize("NFD")
                    .replace(/[\u0300-\u036f]/g, "")
                    .replace(/[ç]/g, "c")
                    .replace(/[^a-z0-9\s-]/g, "")
                    .trim()
                    .replace(/\s+/g, "-")
                    .replace(/-+/g, "-");
            }

            if (titleInput && slugInput) {
                /* Se o slug está vazio, marca como automático */
                if (!slugInput.value) slugInput.dataset.auto = "1";

                titleInput.addEventListener("input", function () {
                    if (slugInput.dataset.auto === "1") {
                        slugInput.value = slugify(this.value);
                        updateSlugPreview();
                    }
                });

                slugInput.addEventListener("input", function () {
                    slugInput.dataset.auto = this.value ? "0" : "1";
                    updateSlugPreview();
                });

                slugInput.addEventListener("blur", function () {
                    if (this.value) this.value = slugify(this.value);
                });

                /* Preview da URL abaixo do campo slug */
                function updateSlugPreview() {
                    let preview = document.getElementById("slug-preview");
                    if (!preview) {
                        preview = document.createElement("small");
                        preview.id = "slug-preview";
                        preview.style.cssText = "display:block;margin-top:.3rem;color:#6c757d;font-family:monospace;font-size:.78rem;";
                        slugInput.parentNode.appendChild(preview);
                    }
                    preview.textContent = slugInput.value
                        ? "🔗 /blog/" + slugInput.value
                        : "";
                }
                updateSlugPreview();

                /* Botão para regenerar slug */
                const regenBtn = document.createElement("button");
                regenBtn.type = "button";
                regenBtn.innerHTML = "↺ Regenerar";
                regenBtn.style.cssText = "margin-top:.35rem;font-size:.75rem;padding:.2rem .6rem;border:1px solid #dee2e6;border-radius:4px;background:#f8f9fa;cursor:pointer;";
                regenBtn.addEventListener("click", function () {
                    slugInput.value = slugify(titleInput.value);
                    slugInput.dataset.auto = "1";
                    updateSlugPreview();
                });
                slugInput.parentNode.appendChild(regenBtn);
            }

            /* ══ 2. Preview da imagem de capa ════════════════════════════════ */
            const thumbInput = document.querySelector("[name$='[thumbnail]']");
            if (thumbInput) {
                const wrapper = document.createElement("div");
                wrapper.id = "thumb-preview";
                const img = document.createElement("img");
                img.alt = "Preview";
                wrapper.appendChild(img);
                thumbInput.parentNode.appendChild(wrapper);

                function updateThumb() {
                    const url = thumbInput.value.trim();
                    if (url && url.startsWith("http")) {
                        img.src = url;
                        wrapper.style.display = "block";
                        img.onerror = function () { wrapper.style.display = "none"; };
                    } else {
                        wrapper.style.display = "none";
                    }
                }
                thumbInput.addEventListener("input", updateThumb);
                updateThumb();
            }

            /* ══ 3. Contadores SEO ══════════════════════════════════════════ */
            function addCounter(selector, max, label) {
                const el = document.querySelector(selector);
                if (!el) return;
                const c = document.createElement("span");
                c.className = "seo-counter";
                const update = () => {
                    const len = el.value.length;
                    c.textContent = len + "/" + max + " caracteres" + (label ? " — " + label : "");
                    c.className = "seo-counter" + (len > max ? " error" : len > max * .85 ? " warn" : "");
                };
                el.parentNode.appendChild(c);
                el.addEventListener("input", update);
                update();
            }
            addCounter("[name$='[metaTitle]']",       60,  "ideal até 60");
            addCounter("[name$='[metaDescription]']", 160, "ideal até 160");
            addCounter("[name$='[excerpt]']",         160, "aparece nos cards");

        });
        </script>
        HTML);
    }

    public function configureActions(Actions $actions): Actions
    {
        $viewOnSite = Action::new('viewOnSite', 'Ver no site', 'fa fa-arrow-up-right-from-square')
            ->linkToUrl(fn(BlogPost $p) => '/blog/' . $p->getSlug())
            ->setHtmlAttributes(['target' => '_blank'])
            ->displayIf(fn(BlogPost $p) => $p->isPublished())
            ->setCssClass('btn btn-sm btn-outline-secondary');

        $publishNow = Action::new('publishNow', 'Publicar', 'fa fa-rocket')
            ->linkToCrudAction('publishNow')
            ->displayIf(fn(BlogPost $p) => !$p->isPublished())
            ->setCssClass('btn btn-sm btn-success');

        $archiveIt = Action::new('archivePost', 'Arquivar', 'fa fa-archive')
            ->linkToCrudAction('archivePost')
            ->displayIf(fn(BlogPost $p) => $p->isPublished())
            ->setCssClass('btn btn-sm btn-outline-warning');

        return $actions
            ->add(Crud::PAGE_INDEX,  Action::DETAIL)
            ->add(Crud::PAGE_DETAIL, $viewOnSite)
            ->add(Crud::PAGE_EDIT,   $viewOnSite)
            ->add(Crud::PAGE_INDEX,  $publishNow)
            ->add(Crud::PAGE_INDEX,  $archiveIt)
            ->add(Crud::PAGE_DETAIL, $publishNow)
            ->add(Crud::PAGE_DETAIL, $archiveIt)
            ->update(Crud::PAGE_INDEX, Action::NEW,    fn(Action $a) => $a->setIcon('fa fa-plus')->setLabel('Novo Post'))
            ->update(Crud::PAGE_INDEX, Action::EDIT,   fn(Action $a) => $a->setIcon('fa fa-pen')->setLabel(''))
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn(Action $a) => $a->setIcon('fa fa-trash')->setLabel(''));
    }

    public function publishNow(\EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext $context): RedirectResponse
    {
        /** @var BlogPost $post */
        $post = $context->getEntity()->getInstance();
        $post->setStatus(BlogPostStatus::Published);
        if (!$post->getPublishedAt()) {
            $post->setPublishedAt(new \DateTimeImmutable());
        }
        $this->container->get('doctrine')->getManager()->flush();
        $this->addFlash('success', sprintf('✅ "%s" foi publicado!', $post->getTitle()));
        return $this->redirect($context->getReferrer() ?? $this->generateUrl('admin'));
    }

    public function archivePost(\EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext $context): RedirectResponse
    {
        /** @var BlogPost $post */
        $post = $context->getEntity()->getInstance();
        $post->setStatus(BlogPostStatus::Archived);
        $this->container->get('doctrine')->getManager()->flush();
        $this->addFlash('warning', sprintf('📦 "%s" foi arquivado.', $post->getTitle()));
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

    // ── helpers ──────────────────────────────────────────────────────────────

    private function statusField(): ChoiceField
    {
        return ChoiceField::new('status', 'Status')
            ->setChoices([
                '📝 Rascunho'  => BlogPostStatus::Draft,
                '🟢 Publicado' => BlogPostStatus::Published,
                '📦 Arquivado' => BlogPostStatus::Archived,
            ])
            ->renderAsBadges([
                BlogPostStatus::Draft->value     => 'warning',
                BlogPostStatus::Published->value => 'success',
                BlogPostStatus::Archived->value  => 'secondary',
            ]);
    }

    // ── fields ───────────────────────────────────────────────────────────────

    public function configureFields(string $pageName): iterable
    {
        /* ── INDEX ── */
        if ($pageName === Crud::PAGE_INDEX) {
            yield IdField::new('id', '#')->addCssClass('text-muted small');
            yield TextField::new('title', 'Título');
            yield TextField::new('thumbnail', 'Capa')
                ->formatValue(fn($v) => $v
                    ? sprintf('<img src="%s" width="72" height="46" style="object-fit:cover;border-radius:6px;" loading="lazy">', htmlspecialchars($v))
                    : '<span style="font-size:1.4rem;">🖼️</span>')
                ->renderAsHtml()
                ->setLabel('Capa');
            yield AssociationField::new('category', 'Categoria');
            yield $this->statusField();
            yield IntegerField::new('views', '👁️');
            yield DateTimeField::new('publishedAt', 'Publicado')->setFormat('dd/MM/yy HH:mm');
            return;
        }

        /* ── DETAIL ── */
        if ($pageName === Crud::PAGE_DETAIL) {
            yield FormField::addColumn(8);
            yield FormField::addFieldset('📄 Conteúdo');
            yield TextField::new('title', 'Título');
            yield TextField::new('slug', 'Slug');
            yield TextareaField::new('excerpt', 'Resumo');
            yield TextareaField::new('content', 'Conteúdo')->renderAsHtml();
            yield FormField::addColumn(4);
            yield FormField::addFieldset('📢 Publicação');
            yield $this->statusField();
            yield AssociationField::new('category', 'Categoria');
            yield AssociationField::new('tags', 'Tags');
            yield IntegerField::new('views', 'Visualizações');
            yield DateTimeField::new('publishedAt', 'Publicado em');
            yield DateTimeField::new('createdAt', 'Criado em');
            yield DateTimeField::new('updatedAt', 'Atualizado em');
            yield FormField::addFieldset('🔍 SEO');
            yield TextField::new('thumbnail', 'URL da capa');
            yield TextField::new('metaTitle', 'Meta Título');
            yield TextareaField::new('metaDescription', 'Meta Descrição');
            return;
        }

        /* ── NEW / EDIT ── */
        yield FormField::addColumn(8);

        yield FormField::addFieldset('📝 Conteúdo do Post');
        yield TextField::new('title', 'Título')
            ->setRequired(true)
            ->setColumns(12)
            ->setHelp('O título aparece no topo do artigo e nas listagens.');

        yield TextField::new('slug', 'Slug (URL amigável)')
            ->setRequired(false)
            ->setColumns(12)
            ->setHelp('Gerado automaticamente. Ex: <code>meu-post</code> → <code>/blog/meu-post</code>')
            ->setFormTypeOption('attr', ['placeholder' => 'gerado-automaticamente', 'autocomplete' => 'off']);

        yield TextareaField::new('excerpt', 'Resumo')
            ->setNumOfRows(3)
            ->setColumns(12)
            ->setRequired(false)
            ->setHelp('Exibido nos cards da listagem. Máx. 160 caracteres recomendados.');

        yield TextEditorField::new('content', 'Conteúdo')
            ->setNumOfRows(25)
            ->setColumns(12)
            ->setRequired(true);

        yield FormField::addFieldset('🔍 SEO & Meta Tags');
        yield TextField::new('thumbnail', 'URL da imagem de capa')
            ->setRequired(false)
            ->setColumns(12)
            ->setHelp('Cole a URL da imagem (ideal: 1200×630 px). Um preview aparecerá abaixo.');
        yield TextField::new('metaTitle', 'Meta Título')
            ->setRequired(false)
            ->setColumns(12)
            ->setHelp('Se vazio, usa o título. Ideal: até 60 caracteres.');
        yield TextareaField::new('metaDescription', 'Meta Descrição')
            ->setNumOfRows(2)
            ->setColumns(12)
            ->setRequired(false)
            ->setHelp('Resumo para motores de busca. Ideal: até 160 caracteres.');

        /* Coluna lateral */
        yield FormField::addColumn(4);

        yield FormField::addFieldset('📢 Publicação');
        yield $this->statusField()
            ->setColumns(12)
            ->setHelp('Rascunho = invisível no blog.');
        yield DateTimeField::new('publishedAt', 'Data de publicação')
            ->setRequired(false)
            ->setColumns(12)
            ->setFormat('dd/MM/yyyy HH:mm')
            ->setHelp('Deixe vazio para usar a data atual ao publicar.');

        yield FormField::addFieldset('📂 Classificação');
        yield AssociationField::new('category', 'Categoria')
            ->setRequired(false)
            ->setColumns(12)
            ->autocomplete()
            ->setCrudController(BlogCategoryCrudController::class)
            ->setHelp('Selecione ou <a href="javascript:void(0)" onclick="window.open(\'/admin?crudAction=new&crudControllerFqcn=App%5C%5CController%5C%5CAdmin%5C%5CBlogCategoryCrudController\', \'_blank\')">crie uma nova categoria</a>.');

        yield AssociationField::new('tags', 'Tags')
            ->setRequired(false)
            ->setColumns(12)
            ->autocomplete()
            ->setCrudController(BlogTagCrudController::class)
            ->setFormTypeOption('by_reference', false)
            ->setHelp('Selecione ou <a href="javascript:void(0)" onclick="window.open(\'/admin?crudAction=new&crudControllerFqcn=App%5C%5CController%5C%5CAdmin%5C%5CBlogTagCrudController\', \'_blank\')">crie uma nova tag</a>.');
    }
}
