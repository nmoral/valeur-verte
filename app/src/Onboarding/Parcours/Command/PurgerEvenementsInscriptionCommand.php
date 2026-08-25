<?php

declare(strict_types=1);

namespace App\Onboarding\Parcours\Command;

use App\Onboarding\Parcours\PurgeEvenementsInscription;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rétention des événements du parcours d'inscription (#111) : supprime ceux qui
 * ont dépassé le délai de conservation. Idempotente — à jouer une fois par jour
 * par un cron (sur le VPS, §5 ; registre #190). Sans effet s'il n'y a rien à purger.
 */
#[AsCommand(
    name: 'app:inscription:purger-evenements',
    description: 'Supprime les événements du parcours d\'inscription au-delà du délai de rétention.',
)]
final class PurgerEvenementsInscriptionCommand extends Command
{
    public function __construct(private readonly PurgeEvenementsInscription $purge)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $supprimes = $this->purge->executer();

        (new SymfonyStyle($input, $output))->success(\sprintf('%d événement(s) d\'inscription purgé(s).', $supprimes));

        return Command::SUCCESS;
    }
}
