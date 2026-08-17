<?php

declare(strict_types=1);

namespace App\Controller\AdminInterne;

use App\Compte\PlafondFondateursAtteint;
use App\Compte\ValidationCandidatureFondateur;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Candidatures fondateur (#61) : l'éditeur voit les comptes en attente (avec leur
 * contact), puis valide — bascule en Fondateur, dans la limite du plafond — ou
 * refuse. Zone « admin. », ROLE_ADMIN.
 */
final class CandidaturesFondateurController extends AbstractController
{
    #[Route('/comptes/candidatures', name: 'admin_candidatures', host: 'admin.%app.domaine%', methods: ['GET', 'POST'])]
    public function __invoke(
        Request $request,
        ValidationCandidatureFondateur $validation,
    ): Response {
        if ($request->isMethod('POST')) {
            $tenantId = $request->request->getInt('tenant');
            try {
                if ('refuser' === $request->request->getString('action')) {
                    $validation->refuser($tenantId);
                } else {
                    $validation->valider($tenantId);
                }
            } catch (PlafondFondateursAtteint $erreur) {
                return $this->rendre($erreur->getMessage());
            }

            return $this->redirectToRoute('admin_candidatures');
        }

        return $this->rendre();
    }

    private function rendre(?string $erreur = null): Response
    {
        $reponse = new Response('', null === $erreur ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);

        return $this->render('admin/candidatures.html.twig', [
            'erreur' => $erreur,
        ], $reponse);
    }
}
