<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoAudit\Command;

use App\AdminInterne\SeoAudit\AuditeurSeo;
use App\AdminInterne\SeoAudit\Gravite;
use App\AdminInterne\SeoAudit\RapportSeo;
use App\AdminInterne\SeoAudit\RapportSeoRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Audit SEO technique/on-page des pages publiques (#345), planifiable (registre
 * cron #190). Parcourt le périmètre, applique les règles, **persiste un snapshot
 * horodaté** — l'admin interne le restitue avec sa tendance. Idempotente et sans
 * effet de bord externe : elle ne fait que lire notre propre site et écrire un
 * rapport ; sûre à planifier.
 */
#[AsCommand(
    name: 'app:seo:audit',
    description: 'Audite le SEO technique des pages publiques et archive un rapport horodaté.',
)]
final class AuditSeoCommand extends Command
{
    public function __construct(
        private readonly AuditeurSeo $auditeur,
        private readonly RapportSeoRepository $rapports,
        private readonly ClockInterface $horloge,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        $audit = $this->auditeur->auditer();
        $rapport = RapportSeo::depuis($audit, $this->horloge->now());
        $this->rapports->enregistrer($rapport);

        $style->success(\sprintf(
            'Audit SEO terminé : score %d/100 sur %d URL(s) — %d ok, %d alerte(s), %d erreur(s).',
            $rapport->scoreTechnique(),
            $rapport->nombreUrls(),
            $audit->compte(Gravite::Ok),
            $audit->compte(Gravite::Alerte),
            $audit->compte(Gravite::Erreur),
        ));

        return Command::SUCCESS;
    }
}
