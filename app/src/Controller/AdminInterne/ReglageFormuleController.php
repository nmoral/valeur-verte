<?php

declare(strict_types=1);

namespace App\Controller\AdminInterne;

use App\AdminInterne\Compte\ReglageParametresFormule;
use App\Compte\Formule;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Édition des paramètres d'une formule (#61) depuis la page de configuration :
 * durée d'essai, quota de rapports, plafond de comptes. Section async, changement
 * tracé au journal d'audit. Zone « admin. », ROLE_ADMIN.
 */
final class ReglageFormuleController extends AbstractController
{
    #[Route('/configuration/formule', name: 'admin_reglage_formule', host: 'admin.%app.domaine%', methods: ['POST'])]
    public function __invoke(Request $request, ReglageParametresFormule $reglage): Response
    {
        $formule = Formule::tryFrom($request->request->getString('formule'));
        if (null === $formule) {
            return $this->json(['ok' => false, 'erreur' => 'Formule inconnue.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $reglage->regler(
                $formule,
                $request->request->getInt('duree'),
                $request->request->getInt('quota'),
                $request->request->getInt('plafond'),
                $this->getUser()?->getUserIdentifier() ?? 'inconnu',
            );
        } catch (\InvalidArgumentException $erreur) {
            return $this->json(['ok' => false, 'erreur' => $erreur->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['ok' => true]);
    }
}
