<?php

declare(strict_types=1);

namespace App\Security\DeuxFacteurs;

use App\Erreur\Zone;
use App\Security\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Ferme la zone `admin.` tant que le second facteur n'a pas été validé (#312).
 *
 * Après le mot de passe, l'admin est **connecté mais pas autorisé**. La fermeture
 * porte sur la zone entière plutôt que sur des pages choisies : protéger page par
 * page, c'est se condamner à en oublier une — et celle qu'on oublie est justement
 * celle qu'on n'avait pas vue.
 *
 * Priorité 6 : après le pare-feu (priorité 8), qui doit avoir authentifié la
 * requête pour qu'on sache à qui on a affaire, et avant le routage.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 6)]
final readonly class ExigenceSecondFacteurListener
{
    /**
     * Marque posée en session par le défi réussi. En session et non dans le jeton :
     * elle doit tomber avec la session, jamais survivre à une reconnexion.
     */
    public const string CLE_SESSION = 'second_facteur_valide';

    /**
     * Chemins qui restent ouverts dans la zone fermée : le défi lui-même (sinon la
     * redirection boucle), l'enrôlement (c'est par là qu'on sort d'un compte sans
     * facteur), la déconnexion (un admin coincé doit pouvoir partir) et la sonde
     * de santé (#313), qui n'a ni session ni téléphone.
     */
    private const array CHEMINS_OUVERTS = ['/second-facteur', '/logout', '/sante'];

    public function __construct(
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $requete = $event->getRequest();
        if (Zone::Admin !== Zone::depuisHote($requete->getHost())) {
            return;
        }

        $chemin = $requete->getPathInfo();
        foreach (self::CHEMINS_OUVERTS as $ouvert) {
            if ($chemin === $ouvert || str_starts_with($chemin, $ouvert.'/')) {
                return;
            }
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || !$this->security->isGranted('ROLE_ADMIN')) {
            // Pas encore authentifié : c'est au pare-feu de renvoyer vers /login.
            return;
        }

        $session = $requete->hasSession() ? $requete->getSession() : null;
        if (null !== $session && true === $session->get(self::CLE_SESSION)) {
            return;
        }

        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate('second_facteur_defi', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ));
    }
}
