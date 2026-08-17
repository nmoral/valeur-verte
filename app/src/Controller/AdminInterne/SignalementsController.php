<?php

declare(strict_types=1);

namespace App\Controller\AdminInterne;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Retours « ce chiffre me semble faux » (#49) : la liste (tri, recherche,
 * pagination) est rendue par le LiveComponent ListeSignalements. Zone « admin. »,
 * réservée à ROLE_ADMIN.
 */
final class SignalementsController extends AbstractController
{
    #[Route('/signalements', name: 'admin_signalements', host: 'admin.%app.domaine%', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('admin/signalements.html.twig');
    }
}
