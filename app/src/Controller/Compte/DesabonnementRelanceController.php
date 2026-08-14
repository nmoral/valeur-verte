<?php

declare(strict_types=1);

namespace App\Controller\Compte;

use App\Compte\CompteRepository;
use App\Compte\JetonDesabonnementRelance;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Désabonnement à la relance de fin d'essai (#108). Route publique : le lien de
 * l'email est cliqué sans session, la signature du jeton fait toute la sécurité.
 * Idempotent — un second clic reste sans effet. Un jeton invalide ou un tenant
 * inconnu renvoie 404, sans distinguer les deux (pas d'oracle d'existence).
 */
final class DesabonnementRelanceController extends AbstractController
{
    #[Route('/desabonnement/relance', name: 'compte_desabonnement_relance', methods: ['GET'])]
    public function __invoke(
        Request $request,
        JetonDesabonnementRelance $jetons,
        CompteRepository $comptes,
        ClockInterface $horloge,
    ): Response {
        $tenantId = $request->query->getInt('tenant');
        $jeton = (string) $request->query->get('jeton', '');

        if (0 === $tenantId || !$jetons->estValide($tenantId, $jeton)) {
            throw $this->createNotFoundException();
        }

        $compte = $comptes->pourTenant($tenantId);
        if (null === $compte) {
            throw $this->createNotFoundException();
        }

        if (!$compte->estDesabonneDeLaRelance()) {
            $compte->seDesabonnerDeLaRelance($horloge->now());
            $comptes->enregistrer($compte);
        }

        return $this->render('compte/desabonnement.html.twig');
    }
}
