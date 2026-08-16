<?php

declare(strict_types=1);

namespace App\Controller\AdminInterne;

use App\AdminInterne\Aide\PublicationBaremeAides;
use App\BackOffice\Aide\BaremeAides;
use App\BackOffice\Aide\BaremeAidesRepository;
use App\BackOffice\Aide\CategorieRevenu;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Édition du barème d'aides (#26) : le formulaire porte une **date d'effet**, et
 * valider publie une nouvelle version plutôt que de modifier l'existante. Zone
 * « admin. », ROLE_ADMIN, tout changement tracé au journal d'audit.
 */
final class BaremeAidesController extends AbstractController
{
    #[Route('/baremes-aides', name: 'admin_bareme_aides', host: 'admin.%app.domaine%', methods: ['GET', 'POST'])]
    public function __invoke(
        Request $request,
        PublicationBaremeAides $publication,
        BaremeAidesRepository $baremes,
    ): Response {
        if ($request->isMethod('POST')) {
            $async = $request->isXmlHttpRequest();

            try {
                $publication->publier(
                    self::dateEffet($request),
                    [
                        CategorieRevenu::TresModeste->name => $request->request->getInt('taux_tres_modeste'),
                        CategorieRevenu::Modeste->name => $request->request->getInt('taux_modeste'),
                        CategorieRevenu::Intermediaire->name => $request->request->getInt('taux_intermediaire'),
                        CategorieRevenu::Superieur->name => $request->request->getInt('taux_superieur'),
                    ],
                    [
                        2 => $request->request->getInt('plafond_2_classes'),
                        3 => $request->request->getInt('plafond_3_classes'),
                    ],
                    $request->request->getInt('plafond_eco_ptz'),
                    $request->request->getInt('taux_tva_pour_mille'),
                    $this->auteur(),
                );
            } catch (\InvalidArgumentException $erreur) {
                if ($async) {
                    return $this->json(['ok' => false, 'erreur' => $erreur->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                return $this->render('admin/baremes_aides.html.twig', [
                    'valeurs' => self::valeurs($baremes->courant()),
                    'erreur' => $erreur->getMessage(),
                ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            if ($async) {
                return $this->json(['ok' => true]);
            }

            return $this->redirectToRoute('admin_bareme_aides');
        }

        return $this->render('admin/baremes_aides.html.twig', [
            'valeurs' => self::valeurs($baremes->courant()),
        ]);
    }

    private static function dateEffet(Request $request): \DateTimeImmutable
    {
        $saisie = $request->request->getString('date_effet');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $saisie);

        if (false === $date) {
            throw new \InvalidArgumentException('La date d\'effet doit être une date valide (AAAA-MM-JJ).');
        }

        return $date;
    }

    /**
     * Valeurs à plat, indexées par nom de champ du formulaire.
     *
     * @return array<string, int>
     */
    private static function valeurs(BaremeAides $bareme): array
    {
        return [
            'taux_tres_modeste' => $bareme->tauxPourCent(CategorieRevenu::TresModeste),
            'taux_modeste' => $bareme->tauxPourCent(CategorieRevenu::Modeste),
            'taux_intermediaire' => $bareme->tauxPourCent(CategorieRevenu::Intermediaire),
            'taux_superieur' => $bareme->tauxPourCent(CategorieRevenu::Superieur),
            'plafond_2_classes' => $bareme->plafondDepenses(2)->euros(),
            'plafond_3_classes' => $bareme->plafondDepenses(3)->euros(),
            'plafond_eco_ptz' => $bareme->plafondEcoPtz()->euros(),
            'taux_tva_pour_mille' => $bareme->tauxTvaPourMille(),
        ];
    }

    private function auteur(): string
    {
        return $this->getUser()?->getUserIdentifier() ?? 'inconnu';
    }
}
