<?php

declare(strict_types=1);

namespace App\Controller\Simulateur;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Audit\SaisieInvalide;
use App\BackOffice\Estimation\ClasseNonPubliee;
use App\BackOffice\Estimation\EstimateurValeurVerte;
use App\BackOffice\Estimation\EstimationIndisponible;
use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Restitution\Restitution;
use App\Security\AdresseEmail;
use App\Simulateur\EnvoiRapportSimulateur;
use App\Simulateur\PlafondSimulateur;
use App\Simulateur\SimulateurLead;
use App\Simulateur\SimulateurLeadRepository;
use App\Simulateur\Utilisation;
use App\Simulateur\UtilisationRepository;
use App\Widget\AdresseSuggeree;
use App\Widget\PiegeAntiBotWidget;
use App\Widget\RechercheAdresse;
use App\Widget\VerificateurTurnstile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Simulateur public (#385) : la copie publique du widget, sur le domaine
 * principal et sans tenant. Un seul formulaire (adresse → surfaces → DPE → email
 * obligatoire + case de conservation) ; le rapport n'est délivré que par email.
 *
 * À la soumission : on calcule via le moteur d'estimation (le même que le mode
 * audit, pur), on incrémente un **compteur d'usage anonyme**, on envoie le rapport
 * par mail, et on ne conserve l'email **que si** le visiteur l'a explicitement
 * demandé (case décochée par défaut). Journalisation volontairement minimale et
 * anonyme ; les fonctions de recontact viendront enrichir la base des leads.
 */
final class SimulateurController extends AbstractController
{
    private const int LONGUEUR_MINIMALE_ADRESSE = 3;

    #[Route('/simulateur', name: 'simulateur', host: '%app.domaine%', methods: ['GET'], options: ['sitemap' => true])]
    public function formulaire(): Response
    {
        return $this->rendreFormulaire([], []);
    }

    #[Route('/simulateur', name: 'simulateur_soumettre', host: '%app.domaine%', methods: ['POST'])]
    public function soumettre(
        Request $request,
        PlafondSimulateur $plafond,
        PiegeAntiBotWidget $piege,
        VerificateurTurnstile $turnstile,
        EstimateurValeurVerte $estimateur,
        EnvoiRapportSimulateur $envoi,
        UtilisationRepository $utilisations,
        SimulateurLeadRepository $leads,
    ): Response {
        // Anti-abus, du moins cher au plus cher (comme le widget #43) : plafond IP,
        // honeypot, puis le défi Turnstile (appel réseau, inerte sans secret).
        $plafond->verifierEmission($request->getClientIp());
        $piege->verifier($request);
        $turnstile->verifier($request->request->getString('cf-turnstile-response'), $request->getClientIp());

        $valeurs = [
            'adresse' => $request->request->getString('adresse'),
            'code_insee' => $request->request->getString('code_insee'),
            'surface_batie' => $request->request->getString('surface_batie'),
            'surface_terrain' => $request->request->getString('surface_terrain'),
            'dpe_avant' => $request->request->getString('dpe_avant'),
            'dpe_apres' => $request->request->getString('dpe_apres'),
            'email' => trim($request->request->getString('email')),
        ];
        $consentement = $request->request->getBoolean('consentement');

        $dpeAvant = Dpe::tryFrom($valeurs['dpe_avant']);
        $dpeApres = Dpe::tryFrom($valeurs['dpe_apres']);

        $erreurs = [];
        if ('' === $valeurs['code_insee']) {
            $erreurs['adresse'] = 'Choisissez votre adresse dans la liste des suggestions.';
        }
        if (!AdresseEmail::estValide($valeurs['email'])) {
            $erreurs['email'] = 'Une adresse email valide est nécessaire pour recevoir le rapport.';
        }
        if (null === $dpeAvant || null === $dpeApres) {
            $erreurs['dpe'] = 'Le DPE actuel et le DPE visé sont obligatoires.';
        }
        if ([] !== $erreurs) {
            return $this->rendreFormulaire($valeurs, $erreurs, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        \assert($dpeAvant instanceof Dpe && $dpeApres instanceof Dpe);

        try {
            $saisie = SaisieAudit::depuis(
                $valeurs['adresse'],
                (int) $valeurs['surface_batie'],
                (int) $valeurs['surface_terrain'],
                $dpeAvant,
                $dpeApres,
            );
        } catch (SaisieInvalide $erreur) {
            return $this->rendreFormulaire($valeurs, $erreur->erreurs(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $restitution = $this->estimer($estimateur, $valeurs['code_insee'], $saisie);

        // Compteur d'usage anonyme : une ligne par estimation demandée, sans email.
        $utilisations->enregistrer(Utilisation::estimation(
            disponible: null !== $restitution,
            codeDepartement: substr($valeurs['code_insee'], 0, 2),
        ));

        if (null === $restitution) {
            return $this->render('simulateur/hors_couverture.html.twig', ['valeurs' => $valeurs]);
        }

        // Le rapport part par email vers l'adresse saisie.
        $envoi->envoyer($valeurs['email'], $saisie, $restitution);

        // On ne conserve l'email QUE si le visiteur l'a explicitement demandé.
        if ($consentement) {
            $leads->enregistrer(SimulateurLead::creer(
                $valeurs['email'],
                $valeurs['code_insee'],
                null,
                $saisie->surfaceBatie(),
                $saisie->surfaceTerrain(),
                $dpeAvant,
                $dpeApres,
                $restitution->montantEuros(),
            ));
        }

        return $this->render('simulateur/confirmation.html.twig', [
            'email' => $valeurs['email'],
            'conserve' => $consentement,
            'restitution' => $restitution,
            'valeurs' => $valeurs,
        ]);
    }

    #[Route('/simulateur/adresses', name: 'simulateur_adresses', host: '%app.domaine%', methods: ['GET'])]
    public function adresses(
        Request $request,
        PlafondSimulateur $plafond,
        RechercheAdresse $recherche,
    ): JsonResponse {
        $plafond->verifierAdresse($request->getClientIp());

        $requete = trim($request->query->getString('q'));
        if (mb_strlen($requete) < self::LONGUEUR_MINIMALE_ADRESSE) {
            return new JsonResponse([]);
        }

        return new JsonResponse(array_map(
            static fn (AdresseSuggeree $adresse): array => [
                'libelle' => $adresse->libelle(),
                'code_insee' => $adresse->codeInsee(),
            ],
            $recherche->suggerer($requete),
        ));
    }

    /**
     * @param array<string, string> $valeurs
     * @param array<string, string> $erreurs
     */
    private function rendreFormulaire(array $valeurs, array $erreurs, int $statut = Response::HTTP_OK): Response
    {
        return $this->render('simulateur/formulaire.html.twig', [
            'valeurs' => $valeurs,
            'erreurs' => $erreurs,
            'dpe_options' => Dpe::cases(),
        ], new Response('', $statut));
    }

    private function estimer(EstimateurValeurVerte $estimateur, string $codeInsee, SaisieAudit $saisie): ?Restitution
    {
        try {
            return $estimateur->estimer($codeInsee, $saisie)->restitution();
        } catch (EstimationIndisponible|ClasseNonPubliee) {
            return null;
        }
    }
}
