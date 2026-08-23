<?php

declare(strict_types=1);

namespace App\Controller\BackOffice;

use App\BackOffice\GrilleCout\GrilleCoutRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CoutsController extends AbstractController
{
    #[Route('/couts', name: 'back_office_couts', host: 'app.%app.domaine%', methods: ['GET'])]
    public function __invoke(GrilleCoutRepository $grilles): Response
    {
        return $this->render('back_office/couts.html.twig', [
            'page_title' => 'Vos coûts de travaux',
            'nav_active' => 'costs',
            'grille' => $grilles->courant(),
        ]);
    }
}
