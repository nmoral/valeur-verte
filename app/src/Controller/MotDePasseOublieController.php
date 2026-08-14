<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\MotDePasseOublie\DemandeReinitialisation;
use App\Security\MotDePasseOublie\JetonInvalide;
use App\Security\MotDePasseOublie\ReinitialisationMotDePasse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Mot de passe oublié (#194, AUTH-1). Routes publiques (l'utilisateur n'est par
 * définition pas connecté) : la demande envoie un lien signé à usage unique, la
 * réinitialisation le consomme. La confirmation de demande est **neutre** — jamais
 * d'indice sur l'existence du compte (anti-énumération).
 */
final class MotDePasseOublieController extends AbstractController
{
    #[Route('/mot-de-passe-oublie', name: 'mot_de_passe_oublie_demande', methods: ['GET', 'POST'])]
    public function demander(
        Request $request,
        DemandeReinitialisation $demande,
        #[Autowire(service: 'limiter.mot_de_passe_oublie_ip')]
        RateLimiterFactoryInterface $motDePasseOublieIpLimiter,
    ): Response {
        if (!$request->isMethod('POST')) {
            return $this->render('security/mot_de_passe_oublie_demande.html.twig');
        }

        $limite = $motDePasseOublieIpLimiter->create($request->getClientIp() ?? 'inconnu')->consume(1);
        if (!$limite->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limite->getRetryAfter()->getTimestamp() - time()), 'Trop de demandes. Merci de réessayer plus tard.');
        }

        if ($this->isCsrfTokenValid('mot_de_passe_oublie', (string) $request->request->get('_csrf_token'))) {
            $demande->pour(trim((string) $request->request->get('email', '')));
        }

        // Réponse identique qu'un compte existe ou non, et même en cas de jeton CSRF
        // périmé : aucun oracle d'existence.
        return $this->render('security/mot_de_passe_oublie_confirmation.html.twig');
    }

    #[Route('/mot-de-passe-oublie/reinitialiser', name: 'mot_de_passe_oublie_reinitialiser', methods: ['GET', 'POST'])]
    public function reinitialiser(Request $request, ReinitialisationMotDePasse $reinitialisation): Response
    {
        $jeton = (string) $request->query->get('jeton', '');

        if (!$request->isMethod('POST')) {
            if (!$reinitialisation->estUtilisable($jeton)) {
                return $this->rendreLienInvalide();
            }

            return $this->render('security/mot_de_passe_oublie_reinitialiser.html.twig', [
                'jeton' => $jeton,
                'erreurs' => [],
            ]);
        }

        $jeton = (string) $request->request->get('jeton', $jeton);
        $motDePasse = (string) $request->request->get('password', '');
        $erreurs = $this->validerReinitialisation($request, $motDePasse);

        if ([] === $erreurs) {
            try {
                $reinitialisation->reinitialiser($jeton, $motDePasse);
                $this->addFlash('success', 'Votre mot de passe a été mis à jour. Vous pouvez vous connecter.');

                return $this->redirectToRoute('login');
            } catch (JetonInvalide) {
                return $this->rendreLienInvalide();
            }
        }

        return $this->render('security/mot_de_passe_oublie_reinitialiser.html.twig', [
            'jeton' => $jeton,
            'erreurs' => $erreurs,
        ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function rendreLienInvalide(): Response
    {
        return $this->render(
            'security/mot_de_passe_oublie_invalide.html.twig',
            [],
            new Response('', Response::HTTP_NOT_FOUND),
        );
    }

    /**
     * @return array<string, string>
     */
    private function validerReinitialisation(Request $request, string $motDePasse): array
    {
        $erreurs = [];

        if (!$this->isCsrfTokenValid('reinitialisation', (string) $request->request->get('_csrf_token'))) {
            $erreurs['global'] = 'Session expirée, merci de réessayer.';
        }
        if (mb_strlen($motDePasse) < 8) {
            $erreurs['password'] = 'Le mot de passe doit contenir au moins 8 caractères.';
        }

        return $erreurs;
    }
}
