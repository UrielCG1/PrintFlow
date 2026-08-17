<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform; use Doctrine\DBAL\Schema\Schema; use Doctrine\Migrations\AbstractMigration;
final class Version20260816021000 extends AbstractMigration
{
    public function getDescription(): string { return 'Agrega un número público aleatorio y no enumerable a los contactos de cliente.'; }
    public function isTransactional(): bool { return false; }
    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Solo MySQL o MariaDB.');
        $this->addSql('ALTER TABLE client_contacts ADD public_number VARCHAR(27) DEFAULT NULL');
        $this->addSql("UPDATE client_contacts SET public_number = CONCAT('CL-', UPPER(SUBSTRING(SHA2(CONCAT(UUID(), RAND(), id), 256), 1, 24))) WHERE public_number IS NULL");
        $this->addSql('ALTER TABLE client_contacts MODIFY public_number VARCHAR(27) NOT NULL, ADD UNIQUE INDEX UNIQ_CLIENT_CONTACT_PUBLIC_NUMBER (public_number)');
    }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE client_contacts DROP INDEX UNIQ_CLIENT_CONTACT_PUBLIC_NUMBER, DROP public_number'); }
}
