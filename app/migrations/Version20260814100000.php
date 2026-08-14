<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260814100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ligne d\'ajustement du professionnel : colonne JSONB nullable rapport.ajustement — #30';
    }

    public function up(Schema $schema): void
    {
        // Champ distinct de l'instantané figé : nullable, posé après coup par le
        // pro. La mesure statistique n'est jamais touchée (#30, §4).
        $this->addSql('ALTER TABLE rapport ADD ajustement JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rapport DROP ajustement');
    }
}
