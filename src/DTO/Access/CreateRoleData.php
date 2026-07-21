<?php

namespace App\DTO\Access;

use App\Entity\Users\Permission;

final class CreateRoleData
{
    public string $code = '';
    public string $name = '';
    public ?string $description = null;

    /** @var Permission[] */
    public array $permissions = [];
}