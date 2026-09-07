<?php

namespace ProGamesZet\GDriveBackup\Providers;

use App\Extensions\BackupAdapter\BackupAdapterService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use ProGamesZet\GDriveBackup\Extensions\GoogleDriveBackupSchema;
use ProGamesZet\GDriveBackup\Services\GDriveBackupService;

class GDriveBackupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/gdrive-backup.php', 'gdrive-backup');

        $this->app->singleton(GDriveBackupService::class, function ($app) {
            return new GDriveBackupService();
        });
    }

    public function boot(): void
    {
        // Apply saved custom settings if exist
        $settingsPath = storage_path('app/gdrive_backup_settings.json');
        if (File::exists($settingsPath)) {
            $saved = json_decode(File::get($settingsPath), true) ?: [];
            foreach ($saved as $key => $val) {
                config(["gdrive-backup.{$key}" => $val]);
            }
        }

        // Register Google Drive Backup Adapter into Pelican Backup Hosts
        if ($this->app->bound(BackupAdapterService::class)) {
            $backupAdapterService = $this->app->make(BackupAdapterService::class);
            $backupAdapterService->register(
                new GoogleDriveBackupSchema($this->app->make(GDriveBackupService::class))
            );
        }
    }
}
