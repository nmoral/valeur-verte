<?php

declare(strict_types=1);

namespace App\BackOffice\Rapport\Command;

use App\BackOffice\Rapport\RetentionRapports;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rétention des rapports (décision D10, #68) : anonymise ceux qui ont dépassé la
 * première échéance — contact effacé, adresse bornée à la commune — et supprime
 * ceux qui ont dépassé la seconde. Idempotente — à jouer une fois par jour par un
 * cron (sur le VPS, §5 ; registre #190). Sans effet s'il n'y a rien à traiter.
 */
#[AsCommand(
    name: 'app:rapport:appliquer-retention',
    description: 'Anonymise puis supprime les rapports au-delà des échéances de rétention.',
)]
final class AppliquerRetentionRapportsCommand extends Command
{
    public function __construct(private readonly RetentionRapports $retention)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $bilan = $this->retention->executer();

        (new SymfonyStyle($input, $output))->success(\sprintf(
            '%d rapport(s) anonymisé(s), %d rapport(s) supprimé(s).',
            $bilan->anonymises(),
            $bilan->supprimes(),
        ));

        return Command::SUCCESS;
    }
}
