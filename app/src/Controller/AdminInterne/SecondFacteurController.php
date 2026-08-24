<?php

declare(strict_types=1);

namespace App\Controller\AdminInterne;

use App\Security\DeuxFacteurs\CodesDeSecours;
use App\Security\DeuxFacteurs\ExigenceSecondFacteurListener;
use App\Security\DeuxFacteurs\SecondFacteurRepository;
use App\Security\DeuxFacteurs\VerificateurSecondFacteur;
use App\Security\User;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Défi du second facteur (#312) : la porte de la zone `admin.`. Le mot de passe a
 * ouvert la session, il n'a pas ouvert l'administration.
 *
 * Un compte sans facteur actif n'a **aucun** moyen de passer par ici : c'est
 * voulu. L'enrôlement s'ouvre depuis le serveur, jamais depuis l'écran — sinon
 * un mot de passe volé suffirait à enrôler l'appareil du voleur.
 */
final class SecondFacteurController extends AbstractController
{
    #[Route('/second-facteur', name: 'second_facteur_defi', host: 'admin.%app.domaine%', methods: ['GET', 'POST'])]
    public function __invoke(
        Request $request,
        SecondFacteurRepository $facteurs,
        VerificateurSecondFacteur $verificateur,
        CodesDeSecours $codesDeSecours,
        ClockInterface $horloge,
        #[Autowire(service: 'limiter.second_facteur_compte')]
        RateLimiterFactoryInterface $secondFacteurCompteLimiter,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);
        $userId = $user->getId();
        \assert(null !== $userId);

        $facteur = $facteurs->actifPourUtilisateur($userId);

        if (!$request->isMethod('POST')) {
            return $this->render('admin/second_facteur_defi.html.twig', [
                'facteurEnrole' => null !== $facteur,
                'erreur' => null,
            ]);
        }

        // Le plafond est consommé AVANT d'éprouver le code, et sans exception pour
        // le bon : sans quoi il suffirait de le trouver pour que la borne n'ait
        // servi à rien.
        $limite = $secondFacteurCompteLimiter->create((string) $userId)->consume(1);
        if (!$limite->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limite->getRetryAfter()->getTimestamp() - time()), 'Trop de tentatives. Merci de réessayer plus tard.');
        }

        $saisie = trim((string) $request->request->get('code', ''));
        $erreur = $this->refuser($request, $saisie);

        if (null === $erreur) {
            // Six chiffres : c'est un code de l'application. Autre chose : c'est un
            // code de secours. Deviner plutôt que demander évite un choix à faire
            // au pire moment, quand le téléphone manque.
            $accepte = 1 === preg_match('/^\d{6}$/', $saisie)
                ? null !== $facteur && $verificateur->verifier($facteur, $saisie, $horloge->now())
                : $codesDeSecours->consommer($userId, $saisie);

            if ($accepte) {
                if (null !== $facteur) {
                    $facteurs->enregistrer($facteur);
                }
                $request->getSession()->set(ExigenceSecondFacteurListener::CLE_SESSION, true);

                return $this->redirectToRoute('admin');
            }

            $erreur = 'Code incorrect.';
        }

        return $this->render('admin/second_facteur_defi.html.twig', [
            'facteurEnrole' => null !== $facteur,
            'erreur' => $erreur,
        ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function refuser(Request $request, string $saisie): ?string
    {
        if (!$this->isCsrfTokenValid('second_facteur', (string) $request->request->get('_csrf_token'))) {
            return 'Session expirée, merci de réessayer.';
        }

        return '' === $saisie ? 'Code incorrect.' : null;
    }
}
