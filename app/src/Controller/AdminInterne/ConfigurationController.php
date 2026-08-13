<?php

declare(strict_types=1);

namespace App\Controller\AdminInterne;

use App\AdminInterne\Coefficient\SeuilsSupervisionRepository;
use App\AdminInterne\Compte\SeuilEssaiRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Configuration unifiée de l'admin (#47/#45) : regroupe tous les réglages sur une
 * page. Chaque section poste en asynchrone vers sa propre route, de sorte qu'un
 * paramètre se change sans recharger ni toucher aux autres. Zone « admin. », ROLE_ADMIN.
 */
final class ConfigurationController extends AbstractController
{
    #[Route('/configuration', name: 'admin_configuration', host: 'admin.%app.domaine%', methods: ['GET'])]
    public function __invoke(SeuilEssaiRepository $seuilEssai, SeuilsSupervisionRepository $seuilsSupervision): Response
    {
        return $this->render('admin/configuration.html.twig', [
            'seuil_essai' => $seuilEssai->courant()->valeur(),
            'seuils_supervision' => $seuilsSupervision->courants(),
        ]);
    }
}
