<?php

declare(strict_types=1);

namespace App\Controller\AdminInterne;

use App\AdminInterne\Compte\ReglageSeuilEssai;
use App\AdminInterne\Compte\SeuilEssaiRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Édition du seuil d'essai (#47) : réglage produit modifiable dans l'admin, tout
 * changement étant tracé au journal d'audit. Zone « admin. », ROLE_ADMIN.
 */
final class SeuilEssaiController extends AbstractController
{
    #[Route('/comptes/seuil', name: 'admin_seuil_essai', host: 'admin.%app.domaine%', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, ReglageSeuilEssai $reglage, SeuilEssaiRepository $seuils): Response
    {
        if ($request->isMethod('POST')) {
            try {
                $reglage->regler($request->request->getInt('seuil'), $this->auteur());
            } catch (\InvalidArgumentException $erreur) {
                return $this->render('admin/seuil_essai.html.twig', [
                    'seuil' => $seuils->courant()->valeur(),
                    'erreur' => $erreur->getMessage(),
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            return $this->redirectToRoute('admin_seuil_essai');
        }

        return $this->render('admin/seuil_essai.html.twig', [
            'seuil' => $seuils->courant()->valeur(),
        ]);
    }

    private function auteur(): string
    {
        return $this->getUser()?->getUserIdentifier() ?? 'inconnu';
    }
}
