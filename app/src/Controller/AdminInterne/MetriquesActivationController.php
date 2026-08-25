<?php

declare(strict_types=1);

namespace App\Controller\AdminInterne;

use App\AdminInterne\Activation\CalculMetriquesActivation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Métriques d'activation du parcours self-service (#111) : taux inscription →
 * premier rapport, délai médian et rétention à J+30, lus des horodatages en base.
 * Sans commercial au contact, ces chiffres sont la seule lecture de ce qui se
 * passe entre l'inscription et le premier rapport. Zone « admin. », ROLE_ADMIN.
 */
final class MetriquesActivationController extends AbstractController
{
    #[Route('/metriques-activation', name: 'admin_metriques_activation', host: 'admin.%app.domaine%', methods: ['GET'])]
    public function __invoke(CalculMetriquesActivation $calcul): Response
    {
        return $this->render('admin/metriques_activation.html.twig', [
            'metriques' => $calcul->calculer(),
        ]);
    }
}
