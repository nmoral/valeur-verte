<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260817130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Branding : nom de l\'entreprise (en-tête du rapport) — #110';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE branding ADD nom VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE branding DROP nom');
    }
}
