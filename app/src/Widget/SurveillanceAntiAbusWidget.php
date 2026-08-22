<?php

declare(strict_types=1);

namespace App\Widget;

use App\AdminInterne\JournalAudit\EvenementAudit;
use App\AdminInterne\JournalAudit\JournalAudit;
use App\Notification\Email;
use App\Notification\Mailer;
use App\Notification\NotificationAdmin;
use App\Security\UserRepository;
use Psr\Clock\ClockInterface;
use Twig\Environment;

/**
 * Anti-abus **réactif** d'une page publique (#268, épic #6).
 *
 * Le préventif (#43) borne chaque requête sans jamais voir la forme du trafic ;
 * ici on constate le pic (#268) et on en tire trois suites, dans cet ordre :
 *
 *  1. **durcir** — le défi Turnstile devient visible pour un temps borné : la
 *     friction ne doit pas survivre à l'incident qui l'a justifiée ;
 *  2. **couper** — au-delà du seuil critique, la page est suspendue d'office
 *     (kill switch automatique), sans attendre qu'un humain se réveille ;
 *  3. **alerter** — l'admin interne (journal d'audit, cohérent avec
 *     {@see \App\Security\LimiteurInscription}) et le pro concerné par email, car
 *     c'est sa page et son quota qui brûlent.
 *
 * Une page déjà suspendue n'a plus rien à escalader : on ne réveille personne. Et
 * un pic dure — sans fenêtre de silence, chaque requête réémettrait l'alerte et
 * noierait ses destinataires ; un pic critique, lui, parle toujours.
 */
final readonly class SurveillanceAntiAbusWidget
{
    public function __construct(
        private DetecteurPicWidget $detecteur,
        private SeuilsAntiAbusWidget $seuils,
        private NotificationAdmin $notificationAdmin,
        private Mailer $mailer,
        private UserRepository $utilisateurs,
        private Environment $twig,
        private JournalAudit $journalAudit,
        private PagePubliqueRepository $pages,
        private ClockInterface $horloge,
    ) {
    }

    public function evaluer(PagePublique $page): void
    {
        if ($page->estSuspendue()) {
            return;
        }

        $maintenant = $this->horloge->now();
        $pic = $this->detecteur->detecter($page->token(), $maintenant);
        if (null === $pic) {
            return;
        }

        $page->renforcerChallengeJusqua($maintenant->add(new \DateInterval('PT'.$this->seuils->challengeRenforceMinutes().'M')));
        if ($pic->estCritique()) {
            $page->suspendre($maintenant);
        }

        if ($this->doitAlerter($page, $pic, $maintenant)) {
            $page->marquerAlertee($maintenant);
            $this->alerter($page, $pic);
        }

        $this->pages->enregistrer($page);
    }

    /**
     * Un pic critique parle toujours ; les autres respectent la fenêtre de silence.
     */
    private function doitAlerter(PagePublique $page, PicWidget $pic, \DateTimeImmutable $maintenant): bool
    {
        if ($pic->estCritique()) {
            return true;
        }

        $derniere = $page->derniereAlerteLe();

        return null === $derniere
            || $derniere <= $maintenant->sub(new \DateInterval('PT'.$this->seuils->silenceMinutes().'M'));
    }

    private function alerter(PagePublique $page, PicWidget $pic): void
    {
        $this->notificationAdmin->usageAnormal(
            'Pic d\'activité sur une page publique',
            \sprintf(
                'Page %s (tenant %d) : %s sur %d minutes.%s',
                $page->token(),
                $page->tenantIdProprietaire(),
                $pic->resume(),
                $this->seuils->fenetreMinutes(),
                $page->estSuspendue() ? ' Page suspendue d\'office.' : ' Défi anti-bot durci.',
            ),
        );

        $this->journalAudit->enregistrer(new EvenementAudit(
            acteur: 'systeme',
            action: 'widget.pic_anti_abus',
            cible: $page->token(),
            contexte: [
                'tenant' => $page->tenantIdProprietaire(),
                'motif' => $pic->motif()->value,
                'mesure' => $pic->mesure(),
                'seuil' => $pic->seuil(),
                'fenetre_minutes' => $this->seuils->fenetreMinutes(),
                'suspendue' => $page->estSuspendue(),
            ],
            survenuLe: $this->horloge->now(),
        ));

        $this->prevenirLePro($page, $pic);
    }

    /**
     * Le pro peut manquer (page orpheline) : l'alerte admin, elle, part quand même.
     */
    private function prevenirLePro(PagePublique $page, PicWidget $pic): void
    {
        $pro = $this->utilisateurs->findOneBy(['tenantId' => $page->tenantIdProprietaire()]);
        if (null === $pro) {
            return;
        }

        $this->mailer->envoyer(new Email(
            $pro->getEmail(),
            'Activité inhabituelle sur votre page publique',
            $this->twig->render('email/widget_pic_anti_abus_pro.html.twig', [
                'motif' => $pic->motif()->libelle(),
                'mesure' => $pic->mesure(),
                'seuil' => $pic->seuil(),
                'fenetre_minutes' => $this->seuils->fenetreMinutes(),
                'suspendue' => $page->estSuspendue(),
            ]),
        ));
    }
}
