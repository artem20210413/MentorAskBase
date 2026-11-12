<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Concerns\TranslatesStates;
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
    use TranslatesStates;

    protected static ?string $model = AdminActionLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    public static function getModelLabel(): string
    {
        return __('admin.action_log.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.action_log.plural');
    }

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
                TextColumn::make('actor.name')->label(__('admin.action_log.actor')),
                TextColumn::make('target.name')->label(__('admin.action_log.target'))->placeholder('—'),
                TextColumn::make('action')
                    ->label(__('admin.action_log.action'))
                    ->formatStateUsing(fn (string $state): string => self::translateOrRaw("admin.action_log.actions.{$state}", $state)),
                TextColumn::make('created_at')->label(__('admin.action_log.created_at'))->dateTime('d.m.Y H:i'),
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
