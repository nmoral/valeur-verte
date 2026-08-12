<?php

declare(strict_types=1);

namespace App\Controller\BackOffice;

use App\BackOffice\GrilleCout\GrilleCout;
use App\BackOffice\GrilleCout\PeriodeValidite;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CoutsController extends AbstractController
{
    #[Route('/back-office/couts', name: 'back_office_couts', methods: ['GET'])]
    public function __invoke(): Response
    {
        $grille = GrilleCout::parDefautNationale(PeriodeValidite::depuis(new \DateTimeImmutable()));

        return $this->render('back_office/couts.html.twig', [
            'page_title' => 'Vos coûts de travaux',
            'nav_active' => 'costs',
            'grille' => $grille,
        ]);
    }
}
