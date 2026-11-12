<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Concerns\TranslatesStates;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Services\Admin\AdminActionLogger;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * FR-021-FR-026: сторінка керування користувачами, доступна лише super_admin.
 */
class UserResource extends Resource
{
    use TranslatesStates;

    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    public static function getModelLabel(): string
    {
        return __('admin.users.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.users.plural');
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
                TextColumn::make('phone')->label(__('admin.users.phone'))->searchable(),
                TextColumn::make('name')->label(__('admin.users.name'))->searchable(),
                TextColumn::make('roles.name')
                    ->label(__('admin.users.role'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::translateOrRaw("admin.roles.{$state}", $state)),
                TextColumn::make('status')
                    ->label(__('admin.users.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::translateOrRaw("admin.users.statuses.{$state}", $state))
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'deactivated' => 'danger',
                        default => 'warning',
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('changeRole')
                    ->label(__('admin.users.change_role'))
                    ->visible(fn (User $record): bool => $record->id !== auth()->id())
                    ->form([
                        Select::make('role')
                            ->label(__('admin.users.new_role'))
                            ->options(UserRole::assignableOptions())
                            ->default(fn (User $record) => $record->roles->first()?->name ?? UserRole::User->value)
                            ->required(),
                    ])
                    ->action(function (User $record, array $data): void {
                        $actor = auth()->user();
                        $oldRole = $record->roles->first()?->name;

                        $record->syncRoles([UserRole::from($data['role'])]);
                        $record->sessions_invalidated_at = now();
                        $record->save();

                        app(AdminActionLogger::class)->log($actor, $record, 'role_changed', [
                            'old_role' => $oldRole,
                            'new_role' => $data['role'],
                        ]);
                    }),
                Tables\Actions\Action::make('toggleStatus')
                    ->label(fn (User $record): string => $record->status === 'deactivated' ? __('admin.users.activate') : __('admin.users.deactivate'))
                    ->color(fn (User $record): string => $record->status === 'deactivated' ? 'success' : 'danger')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => $record->id !== auth()->id() && in_array($record->status, ['approved', 'deactivated'], true))
                    ->action(function (User $record): void {
                        $actor = auth()->user();
                        $newStatus = $record->status === 'deactivated' ? 'approved' : 'deactivated';

                        $record->status = $newStatus;
                        $record->sessions_invalidated_at = now();
                        $record->save();

                        app(AdminActionLogger::class)->log(
                            $actor,
                            $record,
                            $newStatus === 'deactivated' ? 'user_deactivated' : 'user_activated',
                        );
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageUsers::route('/'),
        ];
    }
}
