<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\ChangementEmail\ChangementEmailInvalide;
use App\Security\ChangementEmail\ConfirmationChangementEmail;
use App\Security\ChangementEmail\EnvoiDemandeChangementEmail;
use App\Security\EmailDejaUtilise;
use App\Security\EmailInvalide;
use App\Security\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Changement d'adresse email (#310). La demande part de « Mon compte » (compte
 * connecté) ; la confirmation, elle, se clique depuis la **nouvelle** boîte —
 * souvent sur un autre appareil, sans session. D'où deux surfaces distinctes :
 * une action authentifiée qui demande, une route publique qui confirme sur la
 * seule foi du jeton signé, comme la réinitialisation de mot de passe (#194).
 */
final class ChangementEmailController extends AbstractController
{
    // Épinglée aux deux zones authentifiées, comme la page qui la porte (#306) :
    // une route sans hôte répondrait aussi sur l'apex et sur widget.
    #[Route('/mon-compte/email', name: 'mon_compte_email', host: 'app.%app.domaine%', methods: ['POST'])]
    #[Route('/mon-compte/email', name: 'mon_compte_email_admin', host: 'admin.%app.domaine%', methods: ['POST'])]
    public function demander(Request $request, EnvoiDemandeChangementEmail $envoi): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        $nouvelEmail = (string) $request->request->get('nouvel_email', '');
        $routeCompte = str_contains($request->attributes->getString('_route'), '_admin')
            ? 'mon_compte_admin'
            : 'mon_compte';

        if (!$this->isCsrfTokenValid('mon_compte_email', (string) $request->request->get('_csrf_token'))) {
            return $this->reafficher($routeCompte, ['global' => 'Session expirée, merci de réessayer.']);
        }

        try {
            $envoi->pour($user, $nouvelEmail);
        } catch (EmailInvalide) {
            return $this->reafficher($routeCompte, ['nouvel_email' => 'Cette adresse email n\'est pas valide.']);
        } catch (EmailDejaUtilise) {
            // Le pro connaît déjà l'existence de son propre compte ; et pour une
            // adresse tierce, le message ne dit rien de plus que ce que
            // l'inscription dit déjà. Pas d'oracle nouveau.
            return $this->reafficher($routeCompte, ['nouvel_email' => 'Cette adresse est déjà utilisée par un compte.']);
        }

        $this->addFlash('mon_compte', \sprintf(
            'Un lien de confirmation vient d\'être envoyé à %s. Votre adresse actuelle reste active tant que vous ne l\'avez pas cliqué.',
            $nouvelEmail,
        ));

        return $this->redirectToRoute($routeCompte);
    }

    /**
     * Confirmation par lien. Route **publique** : le lien est cliqué depuis la
     * nouvelle boîte, souvent sur un autre appareil, sans session — exiger une
     * session ici reviendrait à demander de se connecter avec l'adresse qu'on
     * n'a pas encore. Le jeton fait toute la sécurité, comme pour la
     * réinitialisation de mot de passe (#194).
     */
    #[Route('/mon-compte/email/confirmer', name: 'mon_compte_email_confirmer', methods: ['GET'])]
    public function confirmer(
        Request $request,
        ConfirmationChangementEmail $confirmation,
        Security $security,
    ): Response {
        try {
            $user = $confirmation->confirmer((string) $request->query->get('jeton', ''));
        } catch (ChangementEmailInvalide) {
            return $this->render('security/changement_email_refuse.html.twig', [], new Response('', Response::HTTP_NOT_FOUND));
        }

        // Le pro déjà connecté sur l'ancien identifiant garde sa session : sans
        // ce rafraîchissement, le firewall ne retrouverait plus le compte au
        // rechargement et le déconnecterait — au moment précis où il vient de
        // prouver que le compte est le sien. Les autres sessions, elles, tombent.
        if ($this->getUser() instanceof User) {
            $security->login($user);
        }

        return $this->render('security/changement_email_confirme.html.twig', [
            'nouvelEmail' => $user->getEmail(),
        ]);
    }

    /**
     * @param array<string, string> $erreurs
     */
    private function reafficher(string $routeCompte, array $erreurs): Response
    {
        return $this->render('security/mon_compte.html.twig', [
            'erreurs' => [],
            'erreursEmail' => $erreurs,
            'route_compte' => $routeCompte,
        ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
