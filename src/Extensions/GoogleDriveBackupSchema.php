<?php

namespace ProGamesZet\GDriveBackup\Extensions;

use App\Extensions\BackupAdapter\Schemas\BackupAdapterSchema;
use App\Models\Backup;
use App\Models\User;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use ProGamesZet\GDriveBackup\Services\GDriveBackupService;

class GoogleDriveBackupSchema extends BackupAdapterSchema
{
    public function __construct(private readonly GDriveBackupService $service)
    {
    }

    public function getId(): string
    {
        return 'gdrive';
    }

    public function getName(): string
    {
        return 'Google Drive';
    }

    public function createBackup(Backup $backup): void
    {
        $this->service->triggerServerBackup($backup->server, true);
    }

    public function deleteBackup(Backup $backup): void
    {
        $this->service->deleteServerBackup($backup);
    }

    public function getDownloadLink(Backup $backup, User $user): string
    {
        // Google Drive web view URL
        return 'https://drive.google.com';
    }

    /**
     * @return Component[]
     */
    public function getConfigurationForm(): array
    {
        return [
            TextInput::make('remote')
                ->label('Rclone Remote Name')
                ->default('gdrive')
                ->required()
                ->helperText('Name of the configured rclone remote (e.g. gdrive)'),
            TextInput::make('folder')
                ->label('Google Drive Folder')
                ->default('TF2_Backups')
                ->required()
                ->helperText('Root folder in Google Drive where backups are organized'),
            TextInput::make('retention_days')
                ->label('Retention Period (Days)')
                ->numeric()
                ->default(14)
                ->helperText('Days to keep backups before auto-pruning (e.g. 14)'),
        ];
    }
}
