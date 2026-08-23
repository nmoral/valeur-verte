<?php

declare(strict_types=1);

namespace App\Controller\Compte;

use App\AdminInterne\Compte\AdministrationComptes;
use App\Security\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Annulation d'une demande d'effacement (#308) tant que le délai de grâce court.
 * Le filet contre la suppression accidentelle : un clic suffit à revenir en
 * arrière. Épinglée à l'espace pro (`app.`), tracée au journal d'audit
 * (compte.suppression_annulee) par AdministrationComptes.
 */
final class AnnulerSuppressionCompteController extends AbstractController
{
    #[Route('/mon-compte/suppression/annuler', name: 'compte_suppression_annuler', host: 'app.%app.domaine%', methods: ['POST'])]
    public function __invoke(Request $request, AdministrationComptes $administration): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        $tenantId = $user->getTenantId();
        \assert(null !== $tenantId);

        if ($this->isCsrfTokenValid('annuler_suppression_compte', (string) $request->request->get('_csrf_token'))) {
            $administration->annulerSuppression($tenantId, $user->getUserIdentifier());
            $this->addFlash('mon_compte', 'La suppression de votre compte a été annulée.');
        }

        return $this->redirectToRoute('compte_suppression');
    }
}
