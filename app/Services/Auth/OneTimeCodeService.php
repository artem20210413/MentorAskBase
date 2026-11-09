<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
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
    public function requestCode(string $rawPhone, ?string $ip = null): array
    {
        // Ліміт за IP — до будь-якої перевірки номера: не дає одному клієнту
        // перебирати номери (з'ясовувати, які належать адмінам) і засипати
        // адмінів кодами, анулюючи їхні справжні коди.
        if ($ip !== null && ! $this->attempt('telegram-otp-ip:'.$ip, (int) config('services.telegram.otp_requests_per_ip_per_minute'))) {
            return ['outcome' => 'rate_limited'];
        }

        $normalized = $this->normalize($rawPhone);

        if ($normalized === null) {
            return ['outcome' => 'denied'];
        }

        // FR-019b: ліміт запитів коду на один номер за хвилину
        if (! $this->attempt('telegram-otp:'.$normalized, (int) config('services.telegram.otp_requests_per_minute'))) {
            return ['outcome' => 'rate_limited'];
        }

        $user = User::where('phone', $normalized)->first();

        if (! $user || $user->status !== 'approved' || ! $user->hasAnyRole([UserRole::Admin, UserRole::SuperAdmin])) {
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
    public function verifyCode(string $rawPhone, string $code, ?string $ip = null): array
    {
        // Ліміт спроб перевірки з одного IP: інакше, запитуючи нові коди,
        // можна безкінечно перебирати 6-значний код (по otp_max_attempts на код).
        if ($ip !== null && ! $this->attempt('telegram-otp-verify-ip:'.$ip, (int) config('services.telegram.otp_verify_per_ip_per_minute'))) {
            return ['outcome' => 'rate_limited'];
        }

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

    /**
     * Рахує спробу в межах хвилинного вікна. false — ліміт уже вичерпано.
     */
    private function attempt(string $key, int $maxPerMinute): bool
    {
        if ($this->rateLimiter->tooManyAttempts($key, $maxPerMinute)) {
            return false;
        }

        $this->rateLimiter->hit($key, 60);

        return true;
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
