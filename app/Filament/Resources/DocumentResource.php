<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\TranslatesStates;
use App\Filament\Resources\DocumentResource\Pages;
use App\Models\Document;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DocumentResource extends Resource
{
    use TranslatesStates;

    protected static ?string $model = Document::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getModelLabel(): string
    {
        return __('admin.documents.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.documents.plural');
    }

    public static function form(Form $form): Form
    {
        // Немає редагування вмісту документа — форма використовується лише
        // для дії "Завантажити" (ManageDocuments::getHeaderActions).
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // FR-015: назва, статус обробки, дата завантаження
            ->columns([
                TextColumn::make('original_name')
                    ->label(__('admin.documents.name'))
                    ->searchable(),
                TextColumn::make('status')
                    ->label(__('admin.documents.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::translateOrRaw("admin.documents.statuses.{$state}", $state))
                    ->color(fn (string $state): string => match ($state) {
                        'processed' => 'success',
                        'failed' => 'danger',
                        'duplicate' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('failure_reason')
                    ->label(__('admin.documents.failure_reason'))
                    ->limit(50)
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label(__('admin.documents.uploaded_at'))
                    ->dateTime('d.m.Y H:i'),
            ])
            ->actions([
                // FR-017/FR-017a: м'яке видалення (SoftDeletes на моделі Document)
                Tables\Actions\DeleteAction::make()
                    ->label(__('admin.documents.delete')),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageDocuments::route('/'),
        ];
    }
}
