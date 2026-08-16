<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260814170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Désabonnement généralisé aux emails non transactionnels : compte.relance_essai_desabonnee_le renommée en emails_non_transactionnels_desabonne_le — #108/#109';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compte RENAME COLUMN relance_essai_desabonnee_le TO emails_non_transactionnels_desabonne_le');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compte RENAME COLUMN emails_non_transactionnels_desabonne_le TO relance_essai_desabonnee_le');
    }
}
