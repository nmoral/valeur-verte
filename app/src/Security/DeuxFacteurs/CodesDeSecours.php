<?php

declare(strict_types=1);

namespace App\Security\DeuxFacteurs;

use Psr\Clock\ClockInterface;

/**
 * Jeu de codes de secours d'un compte (#312).
 *
 * Ils seront lus sur une feuille imprimée et retapés des mois plus tard, dans un
 * moment de panne : l'alphabet écarte les caractères que le papier confond (0/O,
 * 1/I), les codes sont groupés par quatre, et la saisie pardonne la casse, les
 * tirets manquants et les espaces. Refuser un code pour sa mise en forme, au
 * moment précis où l'admin n'a plus que ce papier, serait le comble.
 *
 * Chaque code porte 60 bits d'aléa (12 caractères sur un alphabet de 32) : assez
 * pour que le deviner soit hors de portée, sans être impossible à recopier.
 */
final readonly class CodesDeSecours
{
    public const int PAR_JEU = 10;
    private const int GROUPES = 3;
    private const int TAILLE_GROUPE = 4;
    // Base32 amputé de 0/O et 1/I, indistinguables sur une feuille imprimée.
    private const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private CodeDeSecoursRepository $codes,
        private ClockInterface $horloge,
    ) {
    }

    /**
     * Génère un jeu neuf et **révoque le précédent**. Les codes rendus en clair
     * ne le seront qu'ici : seule leur empreinte est conservée.
     *
     * @return list<string>
     */
    public function regenerer(int $userId): array
    {
        $enClair = [];
        $entites = [];
        while (\count($enClair) < self::PAR_JEU) {
            $code = $this->tirer();
            if (\in_array($code, $enClair, true)) {
                continue;
            }
            $enClair[] = $code;
            $entites[] = new CodeDeSecours($userId, $this->empreinte($code));
        }

        $this->codes->remplacerLeJeu($userId, $entites);

        return $enClair;
    }

    public function consommer(int $userId, string $codeSaisi): bool
    {
        $code = $this->codes->utilisableParEmpreinte($userId, $this->empreinte($codeSaisi));
        if (null === $code) {
            return false;
        }

        $code->marquerUtilise($this->horloge->now());
        $this->codes->enregistrer($code);

        return true;
    }

    public function restants(int $userId): int
    {
        return $this->codes->compterUtilisables($userId);
    }

    /**
     * L'empreinte porte sur la forme **normalisée**, sans quoi le même code
     * recopié avec ou sans tirets donnerait deux empreintes différentes.
     *
     * SHA-256 sans étirement : un code de 60 bits n'est pas un mot de passe
     * humain, il n'y a pas de dictionnaire à lui opposer. Et qui lit cette table
     * lit aussi le secret TOTP, qui est en clair par nécessité.
     */
    private function empreinte(string $code): string
    {
        $normalise = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');

        return hash('sha256', $normalise);
    }

    private function tirer(): string
    {
        $groupes = [];
        for ($g = 0; $g < self::GROUPES; ++$g) {
            $groupe = '';
            for ($c = 0; $c < self::TAILLE_GROUPE; ++$c) {
                $groupe .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
            }
            $groupes[] = $groupe;
        }

        return implode('-', $groupes);
    }
}
