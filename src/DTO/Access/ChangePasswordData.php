<?php

namespace App\DTO\Access;

final class ChangePasswordData
{
    public string $currentPassword = '';
    public string $newPassword = '';
}