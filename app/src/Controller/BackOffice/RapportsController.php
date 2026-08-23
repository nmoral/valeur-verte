<?php

declare(strict_types=1);

namespace App\Controller\BackOffice;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RapportsController extends AbstractController
{
    #[Route('/rapports', name: 'back_office_rapports', host: 'app.%app.domaine%', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('back_office/rapports.html.twig', [
            'page_title' => 'Rapports & leads',
            'nav_active' => 'reports',
        ]);
    }
}
