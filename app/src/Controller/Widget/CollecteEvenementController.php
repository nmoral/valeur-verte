<?php

declare(strict_types=1);

namespace App\Controller\Widget;

use App\Widget\JournalParcoursWidget;
use App\Widget\PagePubliqueRepository;
use App\Widget\PlafondWidget;
use App\Widget\SessionParcoursWidget;
use App\Widget\TypeEvenementWidget;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Collecte des étapes de parcours déclarées par le navigateur (#90, épic #6).
 *
 * Le parcours est rendu côté serveur : la plupart des étapes s'y constatent
 * seules. Restent celles qui n'existent que dans la page — l'adresse est saisie,
 * le détail est déplié — que le navigateur déclare ici. Seules **celles-là** sont
 * acceptées : une étape que le serveur constate (premier chiffre affiché, lead
 * soumis) ne se déclare jamais de l'extérieur, sinon n'importe qui fabriquerait
 * un funnel flatteur.
 *
 * Endpoint public, donc plafonné par IP (#44) et rattaché à une page active. Il
 * ne reçoit qu'un type et un identifiant de visite bien formé : aucune donnée
 * personnelle, aucune charge utile libre.
 */
final class CollecteEvenementController extends AbstractController
{
    #[Route(
        '/{token}/evenement',
        name: 'widget_evenement',
        host: 'widget.%app.domaine%',
        requirements: ['token' => '[A-Za-z0-9]+'],
        methods: ['POST'],
    )]
    public function __invoke(
        string $token,
        Request $request,
        PagePubliqueRepository $pages,
        PlafondWidget $plafond,
        SessionParcoursWidget $sessions,
        JournalParcoursWidget $journal,
    ): Response {
        $plafond->verifierEvenement($request->getClientIp());

        $page = $pages->trouverActiveParJeton($token);
        if (null === $page) {
            throw $this->createNotFoundException('Page publique inconnue ou désactivée.');
        }

        $type = TypeEvenementWidget::tryFrom($request->request->getString('type'));
        if (null === $type || !$type->estDeclarableParLeVisiteur()) {
            throw new UnprocessableEntityHttpException();
        }

        // Un événement sans visite rattachable ne dit rien du parcours : on le
        // refuse plutôt que d'ouvrir une visite fantôme depuis l'extérieur.
        $session = $request->request->getString('session_parcours');
        if (!$sessions->estValide($session)) {
            throw new UnprocessableEntityHttpException();
        }

        $journal->enregistrer($page, $session, $type);

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
