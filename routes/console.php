<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// FR-017a: остаточне видалення м'яко видалених документів раз на добу
Schedule::command('rag:purge-deleted-documents')->daily();

// Очищення тимчасових файлів Livewire (форма завантаження в адмінці)
Schedule::command('rag:purge-livewire-tmp')->daily();

// FR-007: щоденне нагадування про необроблені заявки на апрув
Schedule::command('telegram:send-approval-reminders')->dailyAt(config('services.telegram.reminder_time'));

// Діагностичні логи webhook зберігаємо тиждень, далі видаляємо раз на добу
Schedule::command('telegram:purge-webhook-logs')->daily();
