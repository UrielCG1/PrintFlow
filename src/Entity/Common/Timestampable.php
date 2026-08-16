<?php
declare(strict_types=1);
namespace App\Entity\Common;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
trait Timestampable
{
    /** Instante UTC en que se creó la entidad. */
    #[ORM\Column(name:'created_at',type:Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $createdAt;
    /** Instante UTC de la modificación persistida más reciente. */
    #[ORM\Column(name:'updated_at',type:Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $updatedAt;
    private function initializeTimestamps(): void { $this->createdAt=$this->updatedAt=new \DateTimeImmutable('now',new \DateTimeZone('UTC')); }
    #[ORM\PreUpdate] public function touch(): void { $this->updatedAt=new \DateTimeImmutable('now',new \DateTimeZone('UTC')); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
