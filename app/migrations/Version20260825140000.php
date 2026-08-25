<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mur de vérification d'email (#333) : `compte.blocage_verification_signale_le`
 * porte la date à laquelle le blocage du 2ᵉ rapport (email non vérifié) a été
 * mesuré pour ce compte. Nulle par défaut ; renseignée une seule fois, elle borne
 * la mesure à un événement par pro (taux de pros bloqués en acteurs distincts).
 */
final class Version20260825140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mur de vérification : compte.blocage_verification_signale_le (mesure du taux de pros bloqués) — #333';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compte ADD blocage_verification_signale_le TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compte DROP blocage_verification_signale_le');
    }
}
