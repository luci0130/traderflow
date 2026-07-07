<?php

namespace App\Modules\NumberSequences\Filament\Resources\NumberSequences;

use App\Filament\Concerns\ScopesToActiveTenant;
use App\Modules\NumberSequences\Filament\Resources\NumberSequences\Pages\EditNumberSequence;
use App\Modules\NumberSequences\Filament\Resources\NumberSequences\Pages\ListNumberSequences;
use App\Modules\NumberSequences\Models\NumberSequence;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class NumberSequenceResource extends Resource
{
    use ScopesToActiveTenant;

    protected static ?string $model = NumberSequence::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHashtag;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 60;

    public static function getModelLabel(): string
    {
        return __('Number sequence');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Number sequences');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Administration');
    }

    public static function canCreate(): bool
    {
        // The set of sequences is fixed per tenant and seeded automatically.
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Placeholder::make('type')
                            ->label(__('Document type'))
                            ->content(fn (NumberSequence $record): string => $record->typeLabel()),
                        TextInput::make('prefix')
                            ->label(__('Prefix'))
                            ->maxLength(20),
                        TextInput::make('suffix')
                            ->label(__('Suffix'))
                            ->maxLength(20),
                        TextInput::make('padding')
                            ->label(__('Padding (digits)'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(20)
                            ->required(),
                        TextInput::make('next_number')
                            ->label(__('Next number'))
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        TextInput::make('step')
                            ->label(__('Step'))
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
                        Placeholder::make('preview')
                            ->label(__('Next value preview'))
                            ->content(fn (NumberSequence $record): string => $record->preview()),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tenant.name')->label(__('Tenant'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('key')
                    ->label(__('Document type'))
                    ->formatStateUsing(fn (NumberSequence $record): string => $record->typeLabel())
                    ->sortable(),
                TextColumn::make('prefix')->label(__('Prefix'))->placeholder('—'),
                TextColumn::make('suffix')->label(__('Suffix'))->placeholder('—')->toggleable(),
                TextColumn::make('padding')->label(__('Padding'))->numeric(),
                TextColumn::make('next_number')->label(__('Next number'))->numeric()->sortable(),
                TextColumn::make('preview')
                    ->label(__('Next value'))
                    ->state(fn (NumberSequence $record): string => $record->preview())
                    ->badge()
                    ->color('success'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('key');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNumberSequences::route('/'),
            'edit' => EditNumberSequence::route('/{record}/edit'),
        ];
    }
}
