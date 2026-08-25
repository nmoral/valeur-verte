<?php

declare(strict_types=1);

namespace App\Controller;

use App\BackOffice\Entreprise\Siret;
use App\Onboarding\Parcours\SuiviInscription;
use App\Security\AdresseEmail;
use App\Security\CreateurDeCompte;
use App\Security\EmailDejaUtilise;
use App\Security\LimiteurInscription;
use App\Security\VerificationEmail\EnvoiVerificationEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class RegistrationController extends AbstractController
{
    #[Route('/inscription', name: 'inscription', methods: ['GET', 'POST'])]
    public function inscrire(
        Request $request,
        CreateurDeCompte $createurDeCompte,
        Security $security,
        LimiteurInscription $limiteurInscription,
        EnvoiVerificationEmail $envoiVerificationEmail,
        SuiviInscription $suiviInscription,
    ): Response {
        $email = trim((string) $request->request->get('email', ''));
        $siret = trim((string) $request->request->get('siret', ''));

        if ($request->isMethod('POST')) {
            $limiteurInscription->verifier($request->getClientIp() ?? 'inconnu');
            $motDePasse = (string) $request->request->get('password', '');
            $erreurs = $this->valider($request, $email, $motDePasse, $siret);

            if ([] === $erreurs) {
                try {
                    $siretConfirme = '' !== $siret ? Siret::depuis($siret) : null;
                    // La version acceptée est celle en vigueur au moment de la
                    // soumission, jamais un champ envoyé par le client (#228).
                    $user = $createurDeCompte->creer($email, $motDePasse, PagesLegalesController::VERSION_CGU, $siretConfirme);

                    // Vérification d'email a posteriori (ONB-1) : envoi du lien de
                    // confirmation, sans jamais bloquer l'accès au premier rapport.
                    $envoiVerificationEmail->envoyerPour($user);
                    $suiviInscription->compteCree($request->getSession());
                    $security->login($user);

                    // ONB-4 : droit à la saisie, aucun écran de bienvenue ni de
                    // configuration. La saisie vit sur le sous-domaine de l'espace
                    // pro ; on compose l'URL absolue sur ce host (scheme + host de
                    // l'espace pro, chemin de la saisie).
                    return $this->redirect($this->urlSaisie());
                } catch (EmailDejaUtilise $exception) {
                    $erreurs['email'] = $exception->getMessage();
                }
            }

            $suiviInscription->ecranAffiche($request->getSession());

            return $this->render('registration/index.html.twig', [
                'erreurs' => $erreurs,
                'email' => $email,
                'siret' => $siret,
                'cgu_accepte' => $request->request->getBoolean('cgu'),
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $suiviInscription->ecranAffiche($request->getSession());

        return $this->render('registration/index.html.twig', [
            'erreurs' => [],
            'email' => $email,
            'siret' => $siret,
            'cgu_accepte' => false,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function valider(Request $request, string $email, string $motDePasse, string $siret): array
    {
        $erreurs = [];

        if (!$this->isCsrfTokenValid('inscription', (string) $request->request->get('_csrf_token'))) {
            $erreurs['global'] = 'Session expirée, merci de réessayer.';
        }
        if (!AdresseEmail::estValide($email)) {
            $erreurs['email'] = 'Adresse email invalide.';
        }
        if (mb_strlen($motDePasse) < 8) {
            $erreurs['password'] = 'Le mot de passe doit contenir au moins 8 caractères.';
        }
        if ('' !== $siret && 1 !== preg_match('/^\d{14}$/', $siret)) {
            $erreurs['siret'] = 'Le SIRET doit comporter 14 chiffres.';
        }
        if (!$request->request->getBoolean('cgu')) {
            $erreurs['cgu'] = 'Vous devez accepter les conditions générales pour créer un compte.';
        }

        return $erreurs;
    }

    /**
     * URL absolue de la saisie sur le sous-domaine de l'espace pro. Depuis #306 la
     * route porte son propre host : le générateur produit l'URL complète, scheme
     * compris (https en prod). Plus rien à recoller — la racine de `espace_pro` était
     * préfixée à la main tant que la route de saisie n'avait pas de host, ce qui la
     * doublerait aujourd'hui.
     */
    private function urlSaisie(): string
    {
        return $this->generateUrl('back_office_audit', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
