<?php

namespace App\Filament\Concerns;

use Illuminate\Support\Facades\Lang;

/**
 * Переклад значень зі статусів/ролей/дій у таблицях адмінки. Невідоме
 * значення (новий статус без перекладу) показуємо як є, а не сирим ключем.
 */
trait TranslatesStates
{
    protected static function translateOrRaw(string $key, string $raw): string
    {
        return Lang::has($key) ? __($key) : $raw;
    }
}
