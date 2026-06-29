<?php

namespace App\Modules\Documents\Filament\RelationManagers;

use App\Modules\Documents\Models\Document;
use App\Support\Tenancy\ActiveTenant;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documents';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('Documents');
    }

    private const ACCEPTED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    private const TYPE_OPTIONS = [
        'invoice' => 'Invoice',
        'contract' => 'Contract',
        'delivery_note' => 'Delivery note',
        'certificate' => 'Certificate',
        'specification' => 'Specification',
        'other' => 'Other',
    ];

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->label(__('Type'))
                    ->options($this->translatedTypeOptions())
                    ->default('other')
                    ->required(),
                TextInput::make('name')
                    ->label(__('Display name'))
                    ->maxLength(255),
                FileUpload::make('file_path')
                    ->label(__('File'))
                    ->required()
                    ->disk('local')
                    ->visibility('private')
                    ->directory(fn (): string => 'documents/'.($this->getOwnerRecord()->tenant_id ?? app(ActiveTenant::class)->id() ?? 'shared'))
                    ->storeFileNamesIn('original_name')
                    ->acceptedFileTypes(self::ACCEPTED_MIME_TYPES)
                    ->maxSize(20480),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('original_name')
            ->columns([
                TextColumn::make('type')
                    ->label(__('Type'))
                    ->formatStateUsing(fn (string $state): string => $this->translatedTypeOptions()[$state] ?? $state)
                    ->badge()
                    ->sortable(),
                TextColumn::make('original_name')
                    ->label(__('File'))
                    ->searchable(),
                TextColumn::make('size')
                    ->label(__('Size'))
                    ->formatStateUsing(fn (?int $state): string => $this->formatBytes($state))
                    ->sortable(),
                TextColumn::make('uploader.name')
                    ->label(__('Uploaded by'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->label(__('Type'))->options($this->translatedTypeOptions()),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $disk = Storage::disk('local');
                        // Bind the document to the parent record's tenant. Falling back to
                        // the active tenant would store null for globally-scoped users
                        // (no session tenant), which the NOT NULL column rejects.
                        $data['tenant_id'] = $this->getOwnerRecord()->tenant_id ?? app(ActiveTenant::class)->id();
                        $data['uploaded_by'] = auth()->id();
                        $data['mime_type'] = $disk->mimeType($data['file_path']) ?: null;
                        $data['size'] = $disk->size($data['file_path']);

                        return $data;
                    }),
            ])
            ->recordActions([
                Action::make('download')
                    ->label(__('Download'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->action(fn (Document $record) => Storage::disk('local')->download(
                        $record->file_path,
                        $record->original_name ?? basename($record->file_path),
                    )),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private function translatedTypeOptions(): array
    {
        return array_map(fn (string $label): string => __($label), self::TYPE_OPTIONS);
    }

    private function formatBytes(?int $bytes): string
    {
        if ($bytes === null || $bytes <= 0) {
            return '—';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $power = min(count($units) - 1, (int) floor(log($bytes, 1024)));

        return number_format($bytes / (1024 ** $power), $power > 0 ? 1 : 0).' '.$units[$power];
    }
}
