<?php

declare(strict_types=1);

namespace App\Controller\BackOffice;

use App\BackOffice\Aide\CategorieRevenu;
use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Bouquet\Bouquet;
use App\BackOffice\Pdf\GenerateurPdfRapport;
use App\BackOffice\Rapport\RapportRepository;
use App\BackOffice\Restitution\AjustementInvalide;
use App\BackOffice\Restitution\AjustementProfessionnel;
use App\BackOffice\Restitution\DeclarationsPro;
use App\BackOffice\Restitution\Restitution;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page de restitution dédiée (§5.3, #31) : le chiffre y est présenté avec son
 * contexte (effectif, fourchette, rayon, millésime) et son indicateur de
 * fiabilité. Le résultat vient de la session posée à la saisie ; un accès direct,
 * sans résultat, renvoie vers l'écran de saisie.
 *
 * En POST, le pro pose sa **ligne d'ajustement** (#30, §4) : une ligne séparée,
 * jamais une surcharge de la mesure. Interdit hors mode audit — cette route vit
 * dans l'espace back-office, jamais dans le widget public.
 */
final class AuditResultatController extends AbstractController
{
    public function __construct(
        private readonly RapportRepository $rapports,
    ) {
    }

    #[Route('/audit/resultat', name: 'back_office_audit_resultat', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $resultat = $request->getSession()->get(AuditController::SESSION_RESULTAT);
        if (!\is_array($resultat) || !(($resultat['saisie'] ?? null) instanceof SaisieAudit)) {
            return $this->redirectToRoute('back_office_audit');
        }

        if ($request->isMethod('POST')) {
            return $this->ajuster($request, $resultat);
        }

        return $this->rendre($resultat);
    }

    /**
     * Déclarations du pro (#26) : situation de revenus du ménage et retouches de
     * chiffrage. Écrit dans le champ dédié du rapport, jamais dans l'instantané
     * figé — le pro peut y revenir sans réémettre de rapport.
     */
    #[Route('/audit/resultat/declarations', name: 'back_office_audit_resultat_declarations', methods: ['POST'])]
    public function declarer(Request $request): Response
    {
        $resultat = $request->getSession()->get(AuditController::SESSION_RESULTAT);
        if (!\is_array($resultat) || !(($resultat['saisie'] ?? null) instanceof SaisieAudit)) {
            return $this->redirectToRoute('back_office_audit');
        }

        try {
            $declarations = DeclarationsPro::depuis(
                CategorieRevenu::parNom($request->request->getString('categorie_revenu')),
                self::retouchesSaisies($request),
            );
        } catch (\InvalidArgumentException $erreur) {
            return $this->rendre($resultat, erreurDeclarations: $erreur->getMessage());
        }

        $reference = $resultat['reference'] ?? null;
        $rapport = \is_string($reference) ? $this->rapports->parReference($reference) : null;
        if (null !== $rapport) {
            $rapport->declarerPar($declarations);
            $this->rapports->enregistrer($rapport);
        }

        $resultat['declarations'] = $declarations;
        $request->getSession()->set(AuditController::SESSION_RESULTAT, $resultat);

        return $this->redirectToRoute('back_office_audit_resultat');
    }

    /**
     * @return array<string, int>
     */
    private static function retouchesSaisies(Request $request): array
    {
        $retouches = [];
        foreach ($request->request->all('chiffrage') as $poste => $euros) {
            if (!\is_string($poste) || !\is_scalar($euros) || '' === $euros) {
                continue;
            }

            $retouches[$poste] = (int) $euros;
        }

        return $retouches;
    }

    /**
     * @param array<array-key, mixed> $resultat
     */
    private function ajuster(Request $request, array $resultat): Response
    {
        $montant = $request->request->getInt('montant');
        $motif = $request->request->getString('motif');

        if (!$this->isCsrfTokenValid('ajustement', $request->request->getString('_csrf_token'))) {
            return $this->rendre($resultat, 'Votre session a expiré, merci de renvoyer le formulaire.', $montant, $motif);
        }

        try {
            $ajustement = AjustementProfessionnel::depuis($montant, $motif);
        } catch (AjustementInvalide $erreur) {
            return $this->rendre($resultat, $erreur->getMessage(), $montant, $motif);
        }

        $reference = $resultat['reference'] ?? null;
        $rapport = \is_string($reference) ? $this->rapports->parReference($reference) : null;
        if (null !== $rapport) {
            $rapport->ajusterPar($ajustement);
            $this->rapports->enregistrer($rapport);
        }

        $resultat['ajustement'] = $ajustement;
        $request->getSession()->set(AuditController::SESSION_RESULTAT, $resultat);

        return $this->redirectToRoute('back_office_audit_resultat');
    }

    /**
     * @param array<array-key, mixed> $resultat
     */
    private function rendre(
        array $resultat,
        ?string $erreurAjustement = null,
        ?int $montantSaisi = null,
        ?string $motifSaisi = null,
        ?string $erreurDeclarations = null,
    ): Response {
        $saisie = $resultat['saisie'];
        \assert($saisie instanceof SaisieAudit);
        $restitution = $resultat['restitution'] ?? null;
        $ajustement = $resultat['ajustement'] ?? null;
        $bouquet = $resultat['bouquet'] ?? null;

        // Le rapport archivé fait foi : l'écran lit le **même** instantané figé et
        // les mêmes déclarations que le PDF (#26). Recalculer de son côté ferait
        // diverger les deux documents.
        $reference = $resultat['reference'] ?? null;
        $rapport = \is_string($reference) ? $this->rapports->parReference($reference) : null;
        $declarations = $rapport?->declarations() ?? DeclarationsPro::aucune();

        $enErreur = null !== $erreurAjustement || null !== $erreurDeclarations;
        $reponse = new Response('', $enErreur ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);

        return $this->render('back_office/audit_resultat.html.twig', [
            'page_title' => 'Restitution',
            'nav_active' => 'audit',
            'saisie' => $saisie,
            'restitution' => $restitution instanceof Restitution ? $restitution : null,
            'avertissements' => $saisie->avertissements(),
            'ajustement' => $ajustement instanceof AjustementProfessionnel ? $ajustement : null,
            'bouquet' => $bouquet instanceof Bouquet ? $bouquet : null,
            'erreur_ajustement' => $erreurAjustement,
            'montant_saisi' => $montantSaisi,
            'motif_saisi' => $motifSaisi,
            'declarations' => $declarations,
            'categories_revenu' => CategorieRevenu::tranches(),
            'erreur_declarations' => $erreurDeclarations,
            'reste_a_charge' => null === $rapport
                ? null
                : GenerateurPdfRapport::resteACharge($rapport->instantane(), $declarations),
        ], $reponse);
    }
}
