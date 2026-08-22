<?php

declare(strict_types=1);

namespace App\Controller\BackOffice;

use App\Tenant\TenantContext;
use App\Widget\PagePubliqueDuPro;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        $tenantId = $tenants->actuel();
        if (null === $tenantId) {
            throw $this->createAccessDeniedException('Aucun tenant courant.');
        }

        $page = $pages->courante($tenantId);

        return $this->render('back_office/page_publique.html.twig', [
            'page_title' => 'Ma page publique',
            'nav_active' => 'page_publique',
            'page' => $page,
            'lien' => $pages->lien($page),
        ]);
    }
}
