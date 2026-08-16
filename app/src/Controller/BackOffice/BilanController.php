<?php

declare(strict_types=1);

namespace App\Controller\BackOffice;

use App\Compte\SuiviCompte;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran de bilan d'usage (#108) : « N rapports générés, M contacts capturés » —
 * le chiffre d'usage qui fait la vente, pas un formulaire de paiement. Sert d'écran
 * de bascule à la fin de l'essai, mais reste consultable à tout moment.
 */
final class BilanController extends AbstractController
{
    #[Route('/compte/bilan', name: 'back_office_bilan', methods: ['GET'])]
    public function __invoke(SuiviCompte $suivi): Response
    {
        $bilan = $suivi->bilanCourant();
        if (null === $bilan) {
            return $this->redirectToRoute('espace_pro');
        }

        return $this->render('back_office/bilan.html.twig', [
            'page_title' => 'Bilan d\'usage',
            'nav_active' => '',
            'bilan' => $bilan,
            'essai' => $suivi->essaiCourant(),
        ]);
    }
}
