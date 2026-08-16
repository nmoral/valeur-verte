<?php

declare(strict_types=1);

namespace App\Controller\AdminInterne;

use App\AdminInterne\Coefficient\SeuilsSupervisionRepository;
use App\Compte\DelaiRelanceEssaiRepository;
use App\Compte\Formule;
use App\Compte\ReglagesFormuleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Configuration unifiée de l'admin (#47/#45/#61/#108) : regroupe tous les réglages
 * sur une page. Chaque section poste en asynchrone vers sa propre route, de sorte
 * qu'un paramètre se change sans recharger ni toucher aux autres. Zone « admin. ».
 */
final class ConfigurationController extends AbstractController
{
    #[Route('/configuration', name: 'admin_configuration', host: 'admin.%app.domaine%', methods: ['GET'])]
    public function __invoke(
        ReglagesFormuleRepository $formules,
        DelaiRelanceEssaiRepository $delais,
        SeuilsSupervisionRepository $seuilsSupervision,
    ): Response {
        return $this->render('admin/configuration.html.twig', [
            'formules' => [
                ['formule' => Formule::Classique, 'parametres' => $formules->pour(Formule::Classique)],
                ['formule' => Formule::Fondateur, 'parametres' => $formules->pour(Formule::Fondateur)],
            ],
            'delai_relance' => $delais->courant()->jours(),
            'seuils_supervision' => $seuilsSupervision->courants(),
        ]);
    }
}
