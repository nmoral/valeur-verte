<?php

declare(strict_types=1);

namespace App\Controller\AdminInterne;

use App\AdminInterne\Signalement\SignalementRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Retours « ce chiffre me semble faux » (#49) : liste les signalements des pros
 * avec leur contexte, pour la boucle de calibration. Zone « admin. », ROLE_ADMIN.
 */
final class SignalementsController extends AbstractController
{
    #[Route('/signalements', name: 'admin_signalements', host: 'admin.%app.domaine%', methods: ['GET'])]
    public function __invoke(SignalementRepository $signalements): Response
    {
        return $this->render('admin/signalements.html.twig', [
            'signalements' => $signalements->recents(),
        ]);
    }
}
