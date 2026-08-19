<?php

declare(strict_types=1);

namespace App\Controller\Widget;

use App\BackOffice\Branding\BrandingRepository;
use App\Widget\PagePubliqueRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page publique d'estimation hébergée, à la marque du pro (#40, décision D9).
 *
 * Liée au sous-domaine `widget.` (firewall public `^widget\.`, `security: false`).
 * L'URL est `/{token}/{slug}` : le token opaque désigne le tenant (la marque en
 * est déduite), le slug n'est qu'un habillage décoratif sans effet sur la
 * résolution. Le parcours d'estimation (adresse → premier chiffre → mur de
 * contact) est ajouté en #41.
 */
final class PagePubliqueController extends AbstractController
{
    #[Route(
        '/{token}/{slug}',
        name: 'widget_page_publique',
        host: 'widget.%app.domaine%',
        requirements: ['token' => '[A-Za-z0-9]+', 'slug' => '[^/]+'],
        methods: ['GET'],
    )]
    public function __invoke(
        string $token,
        PagePubliqueRepository $pages,
        BrandingRepository $brandings,
    ): Response {
        $page = $pages->trouverActiveParJeton($token);
        if (null === $page) {
            throw $this->createNotFoundException('Page publique inconnue ou désactivée.');
        }

        return $this->render('widget/page.html.twig', [
            'branding' => $brandings->pourTenant($page->tenantIdProprietaire()),
        ]);
    }
}
