<?php

declare(strict_types=1);

namespace App\Security\DeuxFacteurs;

use App\AdminInterne\JournalAudit\EvenementAudit;
use App\AdminInterne\JournalAudit\JournalAudit;
use App\Security\MotDePasseOublie\GenerateurJeton;
use App\Security\User;
use Psr\Clock\ClockInterface;

/**
 * Enrôlement du second facteur (#312), ouvert **depuis le serveur**.
 *
 * Le choix qui structure tout : l'enrôlement ne s'ouvre pas de lui-même au
 * premier accès. Celui qui se connecterait le premier enrôlerait son téléphone —
 * et si le mot de passe est déjà volé, ce serait le voleur, qui obtiendrait alors
 * les deux facteurs et évincerait l'admin de son propre produit. Un lien à usage
 * unique, émis en ligne de commande, ferme cette fenêtre : tant qu'il n'a pas été
 * demandé, personne n'enrôle rien, fût-ce avec le bon mot de passe.
 *
 * Le même mécanisme sert trois fois — premier enrôlement, changement de
 * téléphone, déblocage d'un admin qui a perdu téléphone *et* codes de secours.
 */
final readonly class EnrolementSecondFacteur
{
    public function __construct(
        private SecondFacteurRepository $facteurs,
        private JetonEnrolementRepository $jetons,
        private GenerateurSecretTotp $secrets,
        private GenerateurJeton $generateur,
        private CodesDeSecours $codesDeSecours,
        private VerificateurSecondFacteur $verificateur,
        private ClockInterface $horloge,
        private JournalAudit $journal,
        private int $dureeValiditeMinutes,
    ) {
    }

    /**
     * Émet le lien. Rend le jeton brut, que la commande imprime dans l'URL.
     */
    public function ouvrirPour(User $user): string
    {
        $userId = $user->getId();
        \assert(null !== $userId);

        $maintenant = $this->horloge->now();
        $this->jetons->invaliderPourUtilisateur($userId, $maintenant);

        $jetonBrut = $this->generateur->jetonBrut();
        $this->jetons->enregistrer(new JetonEnrolement(
            $userId,
            $this->generateur->empreinte($jetonBrut),
            $maintenant->add(new \DateInterval('PT'.$this->dureeValiditeMinutes.'M')),
        ));

        $this->journal->enregistrer(new EvenementAudit(
            acteur: $user->getEmail(),
            action: 'second_facteur.enrolement_ouvert',
            cible: 'utilisateur#'.$userId,
            contexte: [],
            survenuLe: $maintenant,
        ));

        return $jetonBrut;
    }

    /**
     * Le secret à présenter (QR et saisie manuelle). Idempotent : rafraîchir la
     * page ne tire pas un nouveau secret, sinon le QR déjà scanné deviendrait
     * caduc et l'admin taperait un code calculé sur l'ancien.
     *
     * @throws EnrolementInvalide
     */
    public function secretAPresenter(string $jetonBrut): SecondFacteur
    {
        $userId = $this->utilisateurDuJeton($jetonBrut);

        $enAttente = $this->facteurs->enAttentePourUtilisateur($userId);
        if (null === $enAttente) {
            $enAttente = new SecondFacteur($userId, $this->secrets->nouveau());
            $this->facteurs->enregistrer($enAttente);
        }

        return $enAttente;
    }

    /**
     * Confirme l'enrôlement : c'est ici, et seulement ici, que le facteur devient
     * actif, que l'ancien téléphone cesse d'ouvrir et que le lien est consommé.
     *
     * @return list<string> les codes de secours, affichés une seule fois
     *
     * @throws EnrolementInvalide
     * @throws CodeIncorrect
     */
    public function confirmer(string $jetonBrut, string $codeSaisi): array
    {
        $userId = $this->utilisateurDuJeton($jetonBrut);
        $maintenant = $this->horloge->now();

        $enAttente = $this->secretAPresenter($jetonBrut);
        // Le vérificateur exige un facteur actif — c'est ce qui empêche un
        // enrôlement abandonné d'ouvrir une session. On l'active donc en mémoire
        // pour éprouver le code, et on ne persiste qu'en cas de succès.
        $enAttente->activer($maintenant);
        if (!$this->verificateur->verifier($enAttente, $codeSaisi, $maintenant)) {
            // Le lien survit à la faute de frappe : sans cela, il faudrait
            // retourner en SSH pour en émettre un autre.
            throw new CodeIncorrect();
        }

        $this->facteurs->promouvoir($enAttente, $maintenant);

        $jeton = $this->jetons->trouverParEmpreinte($this->generateur->empreinte($jetonBrut));
        \assert(null !== $jeton);
        $jeton->marquerUtilise($maintenant);
        $this->jetons->enregistrer($jeton);

        $this->journal->enregistrer(new EvenementAudit(
            acteur: 'utilisateur#'.$userId,
            action: 'second_facteur.active',
            cible: 'utilisateur#'.$userId,
            contexte: [],
            survenuLe: $maintenant,
        ));

        return $this->codesDeSecours->regenerer($userId);
    }

    /**
     * @throws EnrolementInvalide
     */
    private function utilisateurDuJeton(string $jetonBrut): int
    {
        $jeton = $this->jetons->trouverParEmpreinte($this->generateur->empreinte($jetonBrut));
        if (null === $jeton || !$jeton->estUtilisable($this->horloge->now())) {
            throw new EnrolementInvalide();
        }

        return $jeton->userId();
    }
}
