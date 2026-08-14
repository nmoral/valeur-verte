<?php

declare(strict_types=1);

namespace App\Controller\AdminInterne;

use App\AdminInterne\Compte\ReglageDelaiRelance;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Édition du délai de relance d'essai (#108) depuis la page de configuration :
 * section async, changement tracé au journal d'audit. Zone « admin. », ROLE_ADMIN.
 */
final class DelaiRelanceController extends AbstractController
{
    #[Route('/configuration/delai-relance', name: 'admin_delai_relance', host: 'admin.%app.domaine%', methods: ['POST'])]
    public function __invoke(Request $request, ReglageDelaiRelance $reglage): Response
    {
        try {
            $reglage->regler($request->request->getInt('delai'), $this->getUser()?->getUserIdentifier() ?? 'inconnu');
        } catch (\InvalidArgumentException $erreur) {
            return $this->json(['ok' => false, 'erreur' => $erreur->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['ok' => true]);
    }
}
