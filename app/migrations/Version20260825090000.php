<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Neutralisation d'essai (#332) : `compte.essai_neutralise_le` porte le moment où
 * l'essai d'un compte a été neutralisé. Nulle par défaut (essai normal) ;
 * renseignée sur un compte recréé après effacement (#308) dont l'adresse est déjà
 * passée par la porte anti-abus — il naît alors sans nouvel essai.
 */
final class Version20260825090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Neutralisation d\'essai : compte.essai_neutralise_le (compte recréé sans nouvel essai) — #332';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compte ADD essai_neutralise_le TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compte DROP essai_neutralise_le');
    }
}
