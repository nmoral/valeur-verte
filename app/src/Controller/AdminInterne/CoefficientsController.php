<?php

declare(strict_types=1);

namespace App\Controller\AdminInterne;

use App\AdminInterne\Coefficient\MailleSupervisee;
use App\AdminInterne\Coefficient\SupervisionCoefficients;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Supervision des coefficients (#45) : tableau par maille, les mailles signalées
 * remontées en tête pour qu'une aberration soit vue avant affichage client.
 * Zone « admin. », ROLE_ADMIN.
 */
final class CoefficientsController extends AbstractController
{
    #[Route('/coefficients', name: 'admin_coefficients', host: 'admin.%app.domaine%', methods: ['GET'])]
    public function __invoke(SupervisionCoefficients $supervision): Response
    {
        $mailles = $supervision->mailles();
        usort(
            $mailles,
            static fn (MailleSupervisee $a, MailleSupervisee $b): int => ($b->estAberrante() <=> $a->estAberrante())
                ?: strcmp($a->codeInsee(), $b->codeInsee()),
        );

        return $this->render('admin/coefficients.html.twig', [
            'mailles' => $mailles,
        ]);
    }
}
