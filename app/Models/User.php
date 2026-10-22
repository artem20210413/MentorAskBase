<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'name', 'email', 'password', 'phone', 'status', 'telegram_chat_id',
    'telegram_username', 'language', 'rejection_count', 'last_rejected_at',
    'sessions_invalidated_at', 'conversation_session_id',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_rejected_at' => 'datetime',
            'sessions_invalidated_at' => 'datetime',
            'rejection_count' => 'integer',
        ];
    }

    /**
     * contracts/web-auth.md: доступ до Filament-панелі лише для approved admin/super_admin.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status === 'approved' && $this->hasAnyRole(['admin', 'super_admin']);
    }

    public function approvalRequests(): HasMany
    {
        return $this->hasMany(UserApprovalRequest::class);
    }

    public function decidedApprovalRequests(): HasMany
    {
        return $this->hasMany(UserApprovalRequest::class, 'decided_by_user_id');
    }

    public function oneTimeCodes(): HasMany
    {
        return $this->hasMany(OneTimeCode::class);
    }

    public function adminActionsPerformed(): HasMany
    {
        return $this->hasMany(AdminActionLog::class, 'actor_user_id');
    }

    public function adminActionsReceived(): HasMany
    {
        return $this->hasMany(AdminActionLog::class, 'target_user_id');
    }
}
