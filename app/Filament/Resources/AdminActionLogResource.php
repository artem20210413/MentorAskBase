<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\AdminActionLogResource\Pages;
use App\Models\AdminActionLog;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * FR-026: журнал адміністративних дій, лише перегляд, доступний super_admin.
 */
class AdminActionLogResource extends Resource
{
    protected static ?string $model = AdminActionLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $modelLabel = 'Журнал дій';

    protected static ?string $pluralModelLabel = 'Журнал дій';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->hasRole(UserRole::SuperAdmin);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('actor.name')->label('Хто'),
                TextColumn::make('target.name')->label('Щодо кого')->placeholder('—'),
                TextColumn::make('action')
                    ->label('Дія')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'approval_approved' => 'Схвалено заявку',
                        'approval_rejected' => 'Відхилено заявку',
                        'role_changed' => 'Змінено роль',
                        'user_deactivated' => 'Деактивовано',
                        'user_activated' => 'Активовано',
                        default => $state,
                    }),
                TextColumn::make('created_at')->label('Коли')->dateTime('d.m.Y H:i'),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAdminActionLogs::route('/'),
        ];
    }
}
