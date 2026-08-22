<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260822010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega unicidad y confirmación de correo para contactos de clientes.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración solo puede ejecutarse sobre MySQL o MariaDB.');
        $this->addSql('ALTER TABLE client_contacts ADD email_verified_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD email_verification_token_hash VARCHAR(64) DEFAULT NULL, ADD email_verification_expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD email_verification_sent_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE client_contacts SET email_verified_at = UTC_TIMESTAMP() WHERE business_email IS NOT NULL AND TRIM(business_email) <> \'\'');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CLIENT_CONTACT_BUSINESS_EMAIL ON client_contacts (business_email)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CLIENT_CONTACT_EMAIL_TOKEN ON client_contacts (email_verification_token_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_CLIENT_CONTACT_BUSINESS_EMAIL ON client_contacts');
        $this->addSql('DROP INDEX UNIQ_CLIENT_CONTACT_EMAIL_TOKEN ON client_contacts');
        $this->addSql('ALTER TABLE client_contacts DROP email_verified_at, DROP email_verification_token_hash, DROP email_verification_expires_at, DROP email_verification_sent_at');
    }
}
