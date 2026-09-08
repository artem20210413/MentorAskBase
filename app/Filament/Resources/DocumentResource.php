<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DocumentResource\Pages;
use App\Models\Document;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $modelLabel = 'Документ';

    protected static ?string $pluralModelLabel = 'Документи';

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
                    ->label('Назва')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'Очікує обробки',
                        'processing' => 'Обробляється',
                        'processed' => 'Оброблено',
                        'failed' => 'Помилка обробки',
                        'duplicate' => 'Дублікат',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'processed' => 'success',
                        'failed' => 'danger',
                        'duplicate' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('failure_reason')
                    ->label('Причина помилки')
                    ->limit(50)
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Дата завантаження')
                    ->dateTime('d.m.Y H:i'),
            ])
            ->actions([
                // FR-017/FR-017a: м'яке видалення (SoftDeletes на моделі Document)
                Tables\Actions\DeleteAction::make()
                    ->label('Видалити'),
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
