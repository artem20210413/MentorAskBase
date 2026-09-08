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
