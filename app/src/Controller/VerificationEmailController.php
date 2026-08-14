<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\User;
use App\Security\UserRepository;
use App\Security\VerificationEmail\EnvoiVerificationEmail;
use App\Security\VerificationEmail\JetonVerificationEmail;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Confirmation d'email (#105, ONB-1). Route publique : le lien de l'email est
 * cliqué sans session, la signature du jeton fait toute la sécurité. Idempotent —
 * un second clic reste sans effet. Un jeton invalide ou un utilisateur inconnu
 * renvoie 404, sans distinguer les deux (pas d'oracle d'existence).
 */
final class VerificationEmailController extends AbstractController
{
    #[Route('/verification-email', name: 'verification_email', methods: ['GET'])]
    public function confirmer(
        Request $request,
        JetonVerificationEmail $jetons,
        UserRepository $utilisateurs,
        ClockInterface $horloge,
    ): Response {
        $userId = $request->query->getInt('utilisateur');
        $jeton = (string) $request->query->get('jeton', '');

        if (0 === $userId || !$jetons->estValide($userId, $jeton)) {
            throw $this->createNotFoundException();
        }

        $user = $utilisateurs->find($userId);
        if (null === $user) {
            throw $this->createNotFoundException();
        }

        if (!$user->emailEstVerifie()) {
            $user->marquerEmailVerifie($horloge->now());
            $utilisateurs->enregistrer($user);
        }

        return $this->render('security/verification_email_confirmee.html.twig');
    }

    /**
     * Renvoi depuis le bandeau de rappel de l'espace pro (compte connecté). Protégé
     * par le rôle du sous-domaine app. (chemin non couvert par la règle publique).
     */
    #[Route('/verification-email/renvoyer', name: 'verification_email_renvoyer', methods: ['GET'])]
    public function renvoyer(EnvoiVerificationEmail $envoi): Response
    {
        $user = $this->getUser();
        if ($user instanceof User && !$user->emailEstVerifie()) {
            $envoi->envoyerPour($user);
        }

        $this->addFlash('verification', 'Un nouvel email de confirmation vient de vous être envoyé.');

        return $this->redirectToRoute('back_office_audit');
    }
}
