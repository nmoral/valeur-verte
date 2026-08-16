<?php

declare(strict_types=1);

namespace App\BackOffice\Restitution;

use App\BackOffice\Rapport\Dpe;

/**
 * Effet de seuil C (#27, §6) : le marché rémunère surtout le **franchissement de
 * la classe C**, pas la progression continue. Depuis la classe actuelle du bien,
 * on décompose le gain vers chaque classe DPE publiée meilleure — rendant visible
 * que s'arrêter à D laisse la moitié du gain sur la table, et qu'aller au-delà de
 * C ne rapporte presque plus.
 *
 * La table n'a de sens que par le contraste : on ne la produit que si la classe C
 * est atteignable et qu'au moins deux paliers existent. Un palier unique répéterait
 * le montant déjà présenté.
 */
final readonly class EffetDeSeuil
{
    /**
     * @param non-empty-list<Palier> $paliers
     */
    private function __construct(private array $paliers)
    {
    }

    /**
     * @param array<string, float> $coefDpe effets log par étiquette DPE publiée
     */
    public static function calculer(Dpe $depuis, Dpe $cible, array $coefDpe, int $prixBaseEuros): ?self
    {
        if (!\array_key_exists($depuis->value, $coefDpe)) {
            return null;
        }

        $paliers = [];
        $franchitC = false;
        // Des cases parcourues du pire au meilleur (G→A) : on ne retient que les
        // classes meilleures que l'actuelle et publiées, d'où un ordre de gains
        // croissant qui met le franchissement de C en évidence.
        foreach (array_reverse(Dpe::cases()) as $classe) {
            if (!$classe->estMeilleurQue($depuis) || !\array_key_exists($classe->value, $coefDpe)) {
                continue;
            }

            $gain = (int) round($prixBaseEuros * (exp($coefDpe[$classe->value] - $coefDpe[$depuis->value]) - 1.0));
            $estSeuil = Dpe::C === $classe;
            $franchitC = $franchitC || $estSeuil;
            $paliers[] = new Palier($classe, $gain, $estSeuil, $cible === $classe);
        }

        if (!$franchitC || \count($paliers) < 2) {
            return null;
        }

        return new self($paliers);
    }

    /**
     * @param list<array{classe: string, gain_euros: int, est_seuil: bool, est_cible: bool}> $donnees
     */
    public static function fromArray(array $donnees): self
    {
        $paliers = array_map(
            static fn (array $palier): Palier => new Palier(
                Dpe::from($palier['classe']),
                $palier['gain_euros'],
                $palier['est_seuil'],
                $palier['est_cible'],
            ),
            $donnees,
        );
        \assert([] !== $paliers);

        return new self($paliers);
    }

    /**
     * @return non-empty-list<Palier>
     */
    public function paliers(): array
    {
        return $this->paliers;
    }

    /**
     * Message adapté à la classe visée par le pro : optimum quand il vise C, gain
     * marginal au-delà, valeur laissée sur la table en-deçà.
     */
    public function message(): string
    {
        $cible = $this->cible();

        if (Dpe::C === $cible) {
            return 'Vous visez la classe C : c\'est le palier où le marché rémunère le plus la rénovation.';
        }

        if ($cible->estMeilleurQue(Dpe::C)) {
            return 'Au-delà de la classe C, le gain supplémentaire est marginal : l\'essentiel de la valeur verte se joue au franchissement du seuil C.';
        }

        return 'En vous arrêtant avant la classe C, vous laissez la plus grande part du gain sur la table : c\'est son franchissement qui paie.';
    }

    /**
     * @return list<array{classe: string, gain_euros: int, est_seuil: bool, est_cible: bool}>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (Palier $palier): array => [
                'classe' => $palier->classeCible()->value,
                'gain_euros' => $palier->gainEuros(),
                'est_seuil' => $palier->estSeuil(),
                'est_cible' => $palier->estCible(),
            ],
            $this->paliers,
        );
    }

    private function cible(): Dpe
    {
        foreach ($this->paliers as $palier) {
            if ($palier->estCible()) {
                return $palier->classeCible();
            }
        }

        // Une table figée porte toujours sa cible ; à défaut, le seuil C fait foi.
        return Dpe::C;
    }
}
