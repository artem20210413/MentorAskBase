<?php

namespace App\Services\Auth;

use App\Models\OneTimeCode;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * contracts/web-auth.md: вхід на Filament-панель за телефоном + кодом.
 */
class OneTimeCodeService
{
    public function __construct(
        private TelegramClient $telegram,
        private RateLimiter $rateLimiter,
    ) {}

    /**
     * @return array{outcome: string, user?: User}
     */
    public function requestCode(string $rawPhone): array
    {
        $normalized = $this->normalize($rawPhone);

        if ($normalized === null) {
            return ['outcome' => 'denied'];
        }

        // FR-019b: не більше 3 запитів на хвилину на один номер
        $rateLimitKey = 'telegram-otp:'.$normalized;
        if ($this->rateLimiter->tooManyAttempts($rateLimitKey, 3)) {
            return ['outcome' => 'rate_limited'];
        }
        $this->rateLimiter->hit($rateLimitKey, 60);

        $user = User::where('phone', $normalized)->first();

        if (! $user || $user->status !== 'approved' || ! $user->hasAnyRole(['admin', 'super_admin'])) {
            return ['outcome' => 'denied'];
        }

        // FR-019a: анулювати попередні незавершені коди
        OneTimeCode::where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->update(['invalidated_at' => now()]);

        $code = (string) random_int(100000, 999999);

        OneTimeCode::create([
            'user_id' => $user->id,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes((int) config('services.telegram.otp_ttl_minutes')),
        ]);

        $this->telegram->sendMessage(
            (int) $user->telegram_chat_id,
            __('telegram.otp_code_message', ['code' => $code], $user->language),
        );

        return ['outcome' => 'sent', 'user' => $user];
    }

    /**
     * @return array{outcome: string, user?: User}
     */
    public function verifyCode(string $rawPhone, string $code): array
    {
        $normalized = $this->normalize($rawPhone);
        $user = $normalized ? User::where('phone', $normalized)->first() : null;

        if (! $user) {
            return ['outcome' => 'invalid'];
        }

        $maxAttempts = (int) config('services.telegram.otp_max_attempts');

        $otp = OneTimeCode::where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->latest('id')
            ->first();

        if (! $otp || $otp->expires_at->isPast() || $otp->attempts >= $maxAttempts) {
            return ['outcome' => 'invalid'];
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            if ($otp->attempts >= $maxAttempts) {
                $otp->update(['invalidated_at' => now()]);
            }

            return ['outcome' => 'invalid'];
        }

        $otp->update(['consumed_at' => now()]);

        return ['outcome' => 'success', 'user' => $user];
    }

    private function normalize(string $raw): ?string
    {
        try {
            return phone(str_starts_with($raw, '+') ? $raw : '+'.$raw)->formatE164();
        } catch (Throwable) {
            return null;
        }
    }
}
