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
        $this->loadTranslationsFrom(__DIR__ . '/../../lang', 'gdrive-backup');

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

        // Register route for real-time diagnostic testing stream
        \Illuminate\Support\Facades\Route::middleware(['web', 'auth.session'])->group(function () {
            \Illuminate\Support\Facades\Route::get('/admin/gdrive-backup/stream-test', function () {
                if (!auth()->check()) {
                    return response()->json(['error' => 'Unauthorized'], 401);
                }
                $service = app(\ProGamesZet\GDriveBackup\Services\GDriveBackupService::class);
                return $service->streamDiagnosticResponse();
            })->name('admin.gdrive-backup.stream-test');
        });
    }
}
