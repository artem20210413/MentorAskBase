<?php

namespace App\Filament\Resources\AdminActionLogResource\Pages;

use App\Filament\Resources\AdminActionLogResource;
use Filament\Resources\Pages\ListRecords;

class ListAdminActionLogs extends ListRecords
{
    protected static string $resource = AdminActionLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
