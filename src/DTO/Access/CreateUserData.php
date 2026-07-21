<?php

namespace App\DTO\Access;

use App\Entity\Users\Role;

final class CreateUserData
{
    public string $fullName = '';
    public string $username = '';
    public string $email = '';
    public ?string $phone = null;

    /**
     * @var Role[]
     */
    public array $roles = [];

    public string $plainPassword = '';
}