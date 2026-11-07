<?php

namespace App\Enums;

/**
 * FR-001: чотири ролі системи. spatie/laravel-permission приймає BackedEnum
 * напряму в assignRole()/hasRole()/hasAnyRole()/syncRoles() — передавати
 * case-и, без ->value.
 */
enum UserRole: string
{
    case User = 'user';
    case Moderator = 'moderator';
    case Admin = 'admin';
    case SuperAdmin = 'super_admin';

    /**
     * Ролі, які super_admin може призначити через UserResource (FR-022) —
     * без super_admin, її надає лише artisan-команда (FR-025).
     *
     * @return array<string, string>
     */
    public static function assignableOptions(): array
    {
        return [
            self::User->value => self::User->value,
            self::Moderator->value => self::Moderator->value,
            self::Admin->value => self::Admin->value,
        ];
    }
}
