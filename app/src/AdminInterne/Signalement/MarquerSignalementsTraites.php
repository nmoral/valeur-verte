<?php

declare(strict_types=1);

namespace App\AdminInterne\Signalement;

use App\Liste\ActionGroupee\ActionGroupee;
use App\Liste\ActionGroupee\AsActionGroupee;
use Psr\Clock\ClockInterface;

/**
 * Action de groupe (#49) : marque comme traités les signalements sélectionnés,
 * pour écarter en masse les retours déjà instruits de la file de calibration.
 */
#[AsActionGroupee('signalements.traiter')]
final readonly class MarquerSignalementsTraites implements ActionGroupee
{
    public function __construct(
        private SignalementRepository $repository,
        private ClockInterface $clock,
    ) {
    }

    public function executer(array $identifiants): string
    {
        $ids = array_values(array_filter(
            array_map(static fn (string $id): int => (int) $id, $identifiants),
            static fn (int $id): bool => $id > 0,
        ));

        $nombre = $this->repository->marquerTraites($ids, $this->clock->now());

        return \sprintf('%d signalement(s) marqué(s) comme traité(s).', $nombre);
    }
}
