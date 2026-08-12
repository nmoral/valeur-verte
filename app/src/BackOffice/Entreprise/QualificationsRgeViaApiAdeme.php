<?php

declare(strict_types=1);

namespace App\BackOffice\Entreprise;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Adapter s'appuyant sur les données ouvertes ADEME (professionnels RGE). Le
 * champ « site_internet » de ce jeu de données pointe fréquemment vers le
 * certificateur : il est volontairement ignoré, seuls les domaines remontent.
 */
final class QualificationsRgeViaApiAdeme implements QualificationsRge
{
    public function __construct(
        private readonly HttpClientInterface $rgeAdeme,
    ) {
    }

    public function pourSiret(Siret $siret): QualificationRge
    {
        $lignes = $this->lignes($siret);
        if (null === $lignes) {
            return QualificationRge::indeterminee();
        }

        if ([] === $lignes) {
            return QualificationRge::nonRge();
        }

        $domaines = [];
        foreach ($lignes as $ligne) {
            if (!\is_array($ligne)) {
                continue;
            }

            $domaine = \is_string($ligne['domaine'] ?? null) ? trim($ligne['domaine']) : '';
            if ('' !== $domaine && !\in_array($domaine, $domaines, true)) {
                $domaines[] = $domaine;
            }
        }

        return QualificationRge::rge($domaines);
    }

    /**
     * @return array<mixed>|null null si la source est indisponible ou illisible
     */
    private function lignes(Siret $siret): ?array
    {
        try {
            $reponse = $this->rgeAdeme->request('GET', '/lines', [
                'query' => ['qs' => 'siret:'.$siret->valeur(), 'size' => 100],
            ]);

            if (200 !== $reponse->getStatusCode()) {
                return null;
            }

            $donnees = $reponse->toArray(false);
        } catch (ExceptionInterface) {
            return null;
        }

        $resultats = $donnees['results'] ?? null;

        return \is_array($resultats) ? $resultats : null;
    }
}
