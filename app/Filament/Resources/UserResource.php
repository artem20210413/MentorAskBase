<?php

namespace App\Filament\Resources;

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
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $modelLabel = 'Користувач';

    protected static ?string $pluralModelLabel = 'Користувачі';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->hasRole('super_admin');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('phone')->label('Телефон')->searchable(),
                TextColumn::make('name')->label("Ім'я (з Telegram)")->searchable(),
                TextColumn::make('roles.name')->label('Роль')->badge(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'На розгляді',
                        'approved' => 'Активний',
                        'rejected' => 'Відхилено',
                        'deactivated' => 'Деактивовано',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'deactivated' => 'danger',
                        default => 'warning',
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('changeRole')
                    ->label('Змінити роль')
                    ->visible(fn (User $record): bool => $record->id !== auth()->id())
                    ->form([
                        Select::make('role')
                            ->label('Нова роль')
                            ->options(['user' => 'user', 'moderator' => 'moderator', 'admin' => 'admin'])
                            ->default(fn (User $record) => $record->roles->first()?->name ?? 'user')
                            ->required(),
                    ])
                    ->action(function (User $record, array $data): void {
                        $actor = auth()->user();
                        $oldRole = $record->roles->first()?->name;

                        $record->syncRoles([$data['role']]);
                        $record->sessions_invalidated_at = now();
                        $record->save();

                        app(AdminActionLogger::class)->log($actor, $record, 'role_changed', [
                            'old_role' => $oldRole,
                            'new_role' => $data['role'],
                        ]);
                    }),
                Tables\Actions\Action::make('toggleStatus')
                    ->label(fn (User $record): string => $record->status === 'deactivated' ? 'Активувати' : 'Деактивувати')
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
