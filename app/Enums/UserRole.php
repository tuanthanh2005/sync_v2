<?php

namespace App\Enums;

enum UserRole: string
{
    case User = 'user';
    case Moderator = 'moderator';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::User => __('Member'),
            self::Moderator => __('Moderator'),
            self::Admin => __('Administrator'),
        };
    }

    public function isStaff(): bool
    {
        return $this === self::Moderator || $this === self::Admin;
    }

    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $role): string => $role->value,
            self::cases(),
        );
    }
}
