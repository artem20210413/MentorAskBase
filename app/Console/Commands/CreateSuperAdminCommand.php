<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * FR-025: перший super admin створюється вручну, поза загальним флоу апрову.
 */
class CreateSuperAdminCommand extends Command
{
    protected $signature = 'telegram:create-super-admin {--phone=} {--name=Super Admin}';

    protected $description = 'Створити першого super admin (FR-025)';

    public function handle(): int
    {
        $raw = (string) $this->option('phone');

        if ($raw === '') {
            $this->error('Потрібно вказати --phone');

            return self::FAILURE;
        }

        try {
            $normalized = phone(str_starts_with($raw, '+') ? $raw : '+'.$raw)->formatE164();
        } catch (InvalidArgumentException) {
            $this->error('Невірний формат номера телефону.');

            return self::FAILURE;
        }

        $user = User::firstOrNew(['phone' => $normalized]);
        $user->name = (string) $this->option('name');
        $user->email ??= $normalized.'@telegram.local';
        $user->password ??= bcrypt(bin2hex(random_bytes(16)));
        $user->status = 'approved';
        $user->language ??= 'uk';
        $user->save();
        $user->syncRoles([UserRole::SuperAdmin]);

        $this->info("Super admin створено: {$normalized} (user_id={$user->id})");

        return self::SUCCESS;
    }
}
