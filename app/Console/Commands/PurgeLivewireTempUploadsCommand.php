<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PurgeLivewireTempUploadsCommand extends Command
{
    protected $signature = 'rag:purge-livewire-tmp';

    protected $description = 'Видаляє тимчасові файли Livewire (форма завантаження в Filament-адмінці), старші за 24 години';

    public function handle(): int
    {
        // Livewire сам чистить свої тимчасові файли лише "по нагоді" — коли
        // хтось завантажує ЩЕ один файл. Якщо після цього ніхто нічого не
        // завантажує, сміття в livewire-tmp накопичується безстроково. Ця
        // команда прибирає його незалежно.
        $storage = Storage::disk('local');
        $cutoff = now()->subDay()->timestamp;
        $deleted = 0;

        foreach ($storage->allFiles('livewire-tmp') as $path) {
            if ($storage->lastModified($path) < $cutoff) {
                $storage->delete($path);
                $deleted++;
            }
        }

        if ($deleted > 0) {
            Log::channel('rag')->info('Очищено тимчасових файлів Livewire', ['count' => $deleted]);
        }

        $this->info("Очищено тимчасових файлів Livewire: {$deleted}");

        return self::SUCCESS;
    }
}
