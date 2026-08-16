<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\MonCompte\ChangementMotDePasse;
use App\Security\MonCompte\MotDePasseActuelInvalide;
use App\Security\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page « Mon compte » (#206, AUTH-3) : changement de mot de passe d'un compte
 * connecté. Servie sur les deux zones (espace pro app. et admin interne admin.) —
 * la route n'impose pas d'hôte, chaque zone la protège par son rôle (access_control).
 * Même contrôleur, même page : aucune duplication.
 */
final class MonCompteController extends AbstractController
{
    #[Route('/mon-compte', name: 'mon_compte', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, ChangementMotDePasse $changement, Security $security): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        if (!$request->isMethod('POST')) {
            return $this->render('security/mon_compte.html.twig', ['erreurs' => []]);
        }

        $actuel = (string) $request->request->get('actuel', '');
        $nouveau = (string) $request->request->get('nouveau', '');
        $erreurs = $this->valider($request, $nouveau);

        if ([] === $erreurs) {
            try {
                $changement->changer($user, $actuel, $nouveau);
                // Conserver la session courante : le token change avec le mot de
                // passe (sinon déconnexion au prochain rafraîchissement, #199). Les
                // autres sessions ouvertes, elles, sont bien invalidées.
                $security->login($user);
                $this->addFlash('mon_compte', 'Votre mot de passe a été mis à jour.');

                return $this->redirectToRoute('mon_compte');
            } catch (MotDePasseActuelInvalide) {
                $erreurs['actuel'] = 'Mot de passe actuel incorrect.';
            }
        }

        return $this->render('security/mon_compte.html.twig', [
            'erreurs' => $erreurs,
        ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * @return array<string, string>
     */
    private function valider(Request $request, string $nouveau): array
    {
        $erreurs = [];

        if (!$this->isCsrfTokenValid('mon_compte', (string) $request->request->get('_csrf_token'))) {
            $erreurs['global'] = 'Session expirée, merci de réessayer.';
        }
        if (mb_strlen($nouveau) < 8) {
            $erreurs['nouveau'] = 'Le mot de passe doit contenir au moins 8 caractères.';
        } elseif ($nouveau !== (string) $request->request->get('confirmation', '')) {
            $erreurs['confirmation'] = 'La confirmation ne correspond pas.';
        }

        return $erreurs;
    }
}
