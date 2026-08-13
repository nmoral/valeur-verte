<?php

declare(strict_types=1);

namespace App\Controller\AdminInterne;

use App\AdminInterne\Coefficient\ReglageSeuilsSupervision;
use App\AdminInterne\Coefficient\SeuilsSupervisionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Édition des seuils d'alerte de la supervision (#45) : réglage éditable dans
 * l'admin, tout changement tracé au journal d'audit. Zone « admin. », ROLE_ADMIN.
 */
final class SeuilsSupervisionController extends AbstractController
{
    #[Route('/coefficients/seuils', name: 'admin_seuils_supervision', host: 'admin.%app.domaine%', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, ReglageSeuilsSupervision $reglage, SeuilsSupervisionRepository $seuils): Response
    {
        if ($request->isMethod('POST')) {
            $async = $request->isXmlHttpRequest();
            try {
                $reglage->regler(
                    (float) $request->request->getString('erreur_mediane_max'),
                    (float) $request->request->getString('gain_ec_max'),
                    $this->auteur(),
                );
            } catch (\InvalidArgumentException $erreur) {
                if ($async) {
                    return $this->json(['ok' => false, 'erreur' => $erreur->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                return $this->render('admin/seuils_supervision.html.twig', [
                    'seuils' => $seuils->courants(),
                    'erreur' => $erreur->getMessage(),
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            if ($async) {
                return $this->json(['ok' => true]);
            }

            return $this->redirectToRoute('admin_seuils_supervision');
        }

        return $this->render('admin/seuils_supervision.html.twig', [
            'seuils' => $seuils->courants(),
        ]);
    }

    private function auteur(): string
    {
        return $this->getUser()?->getUserIdentifier() ?? 'inconnu';
    }
}
