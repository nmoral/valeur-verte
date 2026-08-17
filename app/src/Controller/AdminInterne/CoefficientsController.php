<?php

declare(strict_types=1);

namespace App\Controller\AdminInterne;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Supervision des coefficients (#45) : la liste (tri, recherche, pagination, les
 * mailles signalées en tête par défaut) est rendue par le LiveComponent
 * ListeCoefficients. Zone « admin. », ROLE_ADMIN.
 */
final class CoefficientsController extends AbstractController
{
    #[Route('/coefficients', name: 'admin_coefficients', host: 'admin.%app.domaine%', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('admin/coefficients.html.twig');
    }
}
