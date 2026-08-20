<?php

declare(strict_types=1);

namespace App\Controller\Widget;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Audit\SaisieInvalide;
use App\BackOffice\Branding\Branding;
use App\BackOffice\Branding\BrandingRepository;
use App\BackOffice\Estimation\ClasseNonPubliee;
use App\BackOffice\Estimation\EstimationIndisponible;
use App\BackOffice\Pdf\GenerateurPdfRapport;
use App\BackOffice\Rapport\Contact;
use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Rapport\RapportRepository;
use App\BackOffice\Rapport\Source;
use App\Widget\CaptureLead;
use App\Widget\ConfirmationLead;
use App\Widget\JetonChargementWidget;
use App\Widget\PagePublique;
use App\Widget\PagePubliqueRepository;
use App\Widget\PiegeAntiBotWidget;
use App\Widget\PlafondWidget;
use App\Widget\VerificateurTurnstile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Capture et confirmation du lead sur la page publique hébergée (#42, décision D9).
 *
 * Le mur de contact (#41) poste ici. Rien n'est révélé au pro avant confirmation :
 * `capturer` n'émet qu'une demande à confirmer + un email d'opt-in ; `confirmer`
 * matérialise le lead (Rapport Source::Widget) et notifie le pro ; `pdf` sert le
 * document figé au visiteur. Contrôleur fin : la logique vit dans les services.
 */
final class ContactController extends AbstractController
{
    #[Route(
        '/{token}/contact',
        name: 'widget_contact',
        host: 'widget.%app.domaine%',
        requirements: ['token' => '[A-Za-z0-9]+'],
        methods: ['POST'],
    )]
    public function capturer(
        string $token,
        Request $request,
        PagePubliqueRepository $pages,
        BrandingRepository $brandings,
        CaptureLead $capture,
        PlafondWidget $plafond,
        PiegeAntiBotWidget $piege,
        JetonChargementWidget $jetons,
        VerificateurTurnstile $turnstile,
    ): Response {
        $plafond->verifier($request->getClientIp(), $token);
        $piege->verifier($request);
        if (!$jetons->estRecevable($request->request->getString('jeton_chargement'))) {
            throw new UnprocessableEntityHttpException();
        }
        $turnstile->verifier($request->request->getString('cf-turnstile-response'), $request->getClientIp());

        $page = $this->pageActive($token, $pages);
        $branding = $brandings->pourTenant($page->tenantIdProprietaire());

        // Consentement explicite, non pré-coché (§9.5) : sa case décochée bloque.
        if ('on' !== $request->request->getString('consentement')) {
            return $this->erreur($branding, 'Merci d\'accepter d\'être recontacté pour recevoir votre estimation.');
        }

        $dpeAvant = Dpe::tryFrom($request->request->getString('dpe_avant'));
        $dpeApres = Dpe::tryFrom($request->request->getString('dpe_apres'));
        if (null === $dpeAvant || null === $dpeApres) {
            return $this->erreur($branding, 'Estimation incomplète, merci de recommencer.');
        }

        try {
            $saisie = SaisieAudit::depuis(
                $request->request->getString('adresse'),
                (int) $request->request->getString('surface_batie'),
                (int) $request->request->getString('surface_terrain'),
                $dpeAvant,
                $dpeApres,
            );
            $contact = Contact::creer($request->request->getString('nom'), $request->request->getString('email'), null);
        } catch (SaisieInvalide|\InvalidArgumentException) {
            return $this->erreur($branding, 'Coordonnées ou saisie invalides.');
        }

        try {
            $capture->capturer($page->tenantIdProprietaire(), $token, $branding, $contact, $request->request->getString('code_insee'), $saisie);
        } catch (EstimationIndisponible|ClasseNonPubliee) {
            return $this->erreur($branding, 'Estimation indisponible pour cette adresse.');
        }

        return $this->render('widget/contact_opt_in.html.twig', [
            'branding' => $branding,
            'email' => $contact->email(),
        ]);
    }

    #[Route(
        '/{token}/contact/confirmer/{jeton}',
        name: 'widget_contact_confirmer',
        host: 'widget.%app.domaine%',
        requirements: ['token' => '[A-Za-z0-9]+', 'jeton' => '[a-f0-9]+'],
        methods: ['GET'],
    )]
    public function confirmer(
        string $token,
        string $jeton,
        PagePubliqueRepository $pages,
        BrandingRepository $brandings,
        ConfirmationLead $confirmation,
    ): Response {
        $page = $this->pageActive($token, $pages);
        $branding = $brandings->pourTenant($page->tenantIdProprietaire());

        $rapport = $confirmation->confirmer($jeton);
        if (null === $rapport) {
            throw $this->createNotFoundException('Lien de confirmation invalide, expiré ou déjà utilisé.');
        }

        return $this->render('widget/contact_confirme.html.twig', [
            'branding' => $branding,
            'token' => $token,
            'reference' => $rapport->reference(),
        ]);
    }

    #[Route(
        '/{token}/lead/{reference}/pdf',
        name: 'widget_lead_pdf',
        host: 'widget.%app.domaine%',
        requirements: ['token' => '[A-Za-z0-9]+'],
        methods: ['GET'],
    )]
    public function pdf(
        string $token,
        string $reference,
        PagePubliqueRepository $pages,
        RapportRepository $rapports,
        GenerateurPdfRapport $generateur,
    ): Response {
        $this->pageActive($token, $pages);

        $rapport = $rapports->parReference($reference);
        // On ne sert que les leads du widget : jamais un rapport d'audit du pro.
        if (null === $rapport || Source::Widget !== $rapport->source()) {
            throw $this->createNotFoundException('Estimation introuvable.');
        }

        return new Response(
            $generateur->depuisInstantane($rapport->instantane()),
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="estimation-'.$reference.'.pdf"',
            ],
        );
    }

    private function pageActive(string $token, PagePubliqueRepository $pages): PagePublique
    {
        $page = $pages->trouverActiveParJeton($token);
        if (null === $page) {
            throw $this->createNotFoundException('Page publique inconnue ou désactivée.');
        }

        return $page;
    }

    private function erreur(Branding $branding, string $message): Response
    {
        return $this->render('widget/contact_erreur.html.twig', [
            'branding' => $branding,
            'message' => $message,
        ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
