<?php

declare(strict_types=1);

namespace App\Controller;

use App\Publication\FichesEpci;
use App\Publication\RepertoireEpci;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Vague 2 SEO (#363, épic #336) — hub EPCI, nœud d'autorité **intermédiaire** de la
 * cascade commune → EPCI → département. Agrège les communes membres mesurées et fait
 * remonter l'autorité vers le hub départemental (#337).
 *
 * L'URL porte le préfixe littéral ``epci/`` (``/valeur-verte/epci/{epci}``) : sans
 * lui, le segment unique entrerait en collision avec ``/valeur-verte/{departement}``
 * (hub départemental). Le slug de l'EPCI est recalculé depuis son nom
 * ({@see RepertoireEpci}). Décision produit (comme le hub #337) : la page n'existe
 * que si l'EPCI est réellement mesuré au millésime publié — sinon 404, jamais de
 * page vide.
 */
final class HubEpciController extends AbstractController
{
    #[Route(
        '/valeur-verte/epci/{epci}',
        name: 'hub_epci',
        host: '%app.domaine%',
        requirements: ['epci' => '[a-z0-9-]+'],
        methods: ['GET'],
        // Priorité au préfixe littéral « epci/ » : sans elle, /valeur-verte/epci/{slug}
        // serait happé par /valeur-verte/{departement}/{commune} (CommuneController,
        // enregistré avant) qui lirait departement=epci. Essayée d'abord, cette route
        // ne matche que /valeur-verte/epci/* ; une vraie URL commune retombe dessous.
        priority: 1,
    )]
    public function __invoke(
        string $epci,
        RepertoireEpci $epcis,
        FichesEpci $fiches,
    ): Response {
        $lEpci = $epcis->resoudre($epci);
        if (null === $lEpci) {
            throw $this->createNotFoundException(\sprintf('EPCI inconnu : %s.', $epci));
        }

        $fiche = $fiches->pour($lEpci->codeEpci);
        if (null === $fiche) {
            throw $this->createNotFoundException(\sprintf('EPCI non mesuré : %s.', $epci));
        }

        $reponse = $this->render('seo/hub-epci.html.twig', [
            'fiche' => $fiche,
            'slug' => $lEpci->slug(),
        ]);

        // Page stable tant que le millésime ne change pas : cachable par le proxy partagé.
        $reponse->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $reponse->setSharedMaxAge(3600);

        return $reponse;
    }
}
