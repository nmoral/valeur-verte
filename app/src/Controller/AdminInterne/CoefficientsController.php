<?php

declare(strict_types=1);

namespace App\Controller\AdminInterne;

use App\AdminInterne\Coefficient\MailleSupervisee;
use App\AdminInterne\Coefficient\PageMailles;
use App\AdminInterne\Coefficient\SupervisionCoefficients;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Supervision des coefficients (#45) : tableau par maille, les mailles signalées
 * remontées en tête pour qu'une aberration soit vue avant affichage client.
 * Liste paginée (le tri précède le découpage → signalées en tête globalement).
 * Zone « admin. », ROLE_ADMIN.
 */
final class CoefficientsController extends AbstractController
{
    private const PAR_PAGE = 50;

    #[Route('/coefficients', name: 'admin_coefficients', host: 'admin.%app.domaine%', methods: ['GET'])]
    public function __invoke(Request $request, SupervisionCoefficients $supervision): Response
    {
        $mailles = $supervision->mailles();
        usort(
            $mailles,
            static fn (MailleSupervisee $a, MailleSupervisee $b): int => ($b->estAberrante() <=> $a->estAberrante())
                ?: strcmp($a->codeInsee(), $b->codeInsee()),
        );

        return $this->render('admin/coefficients.html.twig', [
            'page' => PageMailles::decouper($mailles, $request->query->getInt('page', 1), self::PAR_PAGE),
        ]);
    }
}
