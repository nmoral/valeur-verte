<?php

declare(strict_types=1);

namespace App\Controller;

use App\Publication\LectureModelePublie;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'home', host: '%app.domaine%', methods: ['GET'], options: ['sitemap' => true])]
    public function __invoke(LectureModelePublie $modelePublie): Response
    {
        return $this->render('home/index.html.twig', [
            // La vitrine ne renvoie vers l'étude (#224) que si un millésime est
            // publié : sans cela, le lien mènerait à une page 404.
            'etude_publiee' => $modelePublie->publication()?->millesime,
        ]);
    }
}
