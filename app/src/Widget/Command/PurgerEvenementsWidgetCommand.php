<?php

declare(strict_types=1);

namespace App\Widget\Command;

use App\Widget\PurgeEvenementsWidget;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rétention des événements de parcours du widget (#90) : supprime ceux qui ont
 * dépassé le délai de conservation. Idempotente — à jouer une fois par jour par
 * un cron (sur le VPS, §5 ; registre #190). Sans effet s'il n'y a rien à purger.
 */
#[AsCommand(
    name: 'app:widget:purger-evenements',
    description: 'Supprime les événements de parcours du widget au-delà du délai de rétention.',
)]
final class PurgerEvenementsWidgetCommand extends Command
{
    public function __construct(private readonly PurgeEvenementsWidget $purge)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $supprimes = $this->purge->executer();

        (new SymfonyStyle($input, $output))->success(\sprintf('%d événement(s) de parcours purgé(s).', $supprimes));

        return Command::SUCCESS;
    }
}
