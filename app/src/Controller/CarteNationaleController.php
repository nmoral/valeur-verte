<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ancienne page-carte nationale (#337), retirée au profit de l'étude (#391) :
 * `/valeur-verte-par-departement` porte désormais la carte **et** l'annuaire des
 * départements. On conserve l'URL historique `/carte-valeur-verte-france` en
 * **redirection permanente (301)** vers l'étude — les backlinks et l'autorité SEO
 * accumulés ne sont pas perdus, mais il n'y a plus qu'une page à maintenir. La
 * route sort du sitemap (plus une cible d'indexation propre, seulement un renvoi).
 */
final class CarteNationaleController extends AbstractController
{
    #[Route(
        '/carte-valeur-verte-france',
        name: 'carte_nationale',
        host: '%app.domaine%',
        methods: ['GET'],
    )]
    public function __invoke(): Response
    {
        return $this->redirectToRoute('publication', [], Response::HTTP_MOVED_PERMANENTLY);
    }
}
