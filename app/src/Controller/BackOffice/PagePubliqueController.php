<?php

declare(strict_types=1);

namespace App\Controller\BackOffice;

use App\Tenant\TenantContext;
use App\Widget\PagePublique;
use App\Widget\PagePubliqueDuPro;
use App\Widget\PagePubliqueRepository;
use App\Widget\SlugPage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran « Ma page publique » de l'espace pro (#38, épic #6, décision D9) : le pro
 * y lit l'adresse de sa page hébergée et la partage. Il n'y a plus de code
 * d'intégration à copier ni de domaine à déclarer — la page est chez nous.
 */
final class PagePubliqueController extends AbstractController
{
    #[Route('/page-publique', name: 'back_office_page_publique', methods: ['GET'])]
    public function __invoke(TenantContext $tenants, PagePubliqueDuPro $pages): Response
    {
        return $this->ecran($pages->courante($this->tenant($tenants)), $pages);
    }

    #[Route('/page-publique/slug', name: 'back_office_page_publique_slug', methods: ['POST'])]
    public function renommer(
        Request $request,
        TenantContext $tenants,
        PagePubliqueDuPro $pages,
        PagePubliqueRepository $depot,
    ): Response {
        $page = $pages->courante($this->tenant($tenants));

        try {
            $page->renommer(SlugPage::depuisSaisie($request->request->getString('slug')));
        } catch (\InvalidArgumentException $erreur) {
            return $this->ecran($page, $pages, $erreur->getMessage());
        }

        $depot->enregistrer($page);

        return $this->redirectToRoute('back_office_page_publique');
    }

    #[Route('/page-publique/retirer', name: 'back_office_page_publique_retirer', methods: ['POST'])]
    public function retirer(TenantContext $tenants, PagePubliqueDuPro $pages, PagePubliqueRepository $depot): Response
    {
        return $this->basculer($tenants, $pages, $depot, enLigne: false);
    }

    #[Route('/page-publique/remettre-en-ligne', name: 'back_office_page_publique_remettre', methods: ['POST'])]
    public function remettreEnLigne(TenantContext $tenants, PagePubliqueDuPro $pages, PagePubliqueRepository $depot): Response
    {
        return $this->basculer($tenants, $pages, $depot, enLigne: true);
    }

    private function basculer(
        TenantContext $tenants,
        PagePubliqueDuPro $pages,
        PagePubliqueRepository $depot,
        bool $enLigne,
    ): Response {
        $page = $pages->courante($this->tenant($tenants));
        $enLigne ? $page->activer() : $page->desactiver();
        $depot->enregistrer($page);

        return $this->redirectToRoute('back_office_page_publique');
    }

    private function tenant(TenantContext $tenants): int
    {
        $tenantId = $tenants->actuel();
        if (null === $tenantId) {
            throw $this->createAccessDeniedException('Aucun tenant courant.');
        }

        return $tenantId;
    }

    private function ecran(PagePublique $page, PagePubliqueDuPro $pages, ?string $erreur = null): Response
    {
        return $this->render('back_office/page_publique.html.twig', [
            'page_title' => 'Ma page publique',
            'nav_active' => 'page_publique',
            'page' => $page,
            'lien' => $pages->lien($page),
            'longueur_max_slug' => PagePublique::LONGUEUR_MAX_SLUG,
            'erreur' => $erreur,
        ], new Response('', null === $erreur ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
