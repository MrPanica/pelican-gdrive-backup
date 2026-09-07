<?php

namespace ProGamesZet\GDriveBackup\Models;

use Illuminate\Database\Eloquent\Model;
use ProGamesZet\GDriveBackup\Services\GDriveBackupService;
use Sushi\Sushi;

class SystemBackup extends Model
{
    use Sushi;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $schema = [
        'id' => 'string',
        'name' => 'string',
        'path' => 'string',
        'size' => 'integer',
        'size_formatted' => 'string',
        'date' => 'string',
        'status' => 'string',
        'gdrive_id' => 'string',
    ];

    public function getRows(): array
    {
        $service = app(GDriveBackupService::class);
        $backups = $service->listSystemBackups();

        $rows = [];
        foreach ($backups as $b) {
            $rows[] = [
                'id' => $b['name'],
                'name' => $b['name'],
                'path' => $b['path'],
                'size' => (int) $b['size'],
                'size_formatted' => $b['size_formatted'],
                'date' => $b['date'],
                'status' => $b['status'] ?? 'Completed',
                'gdrive_id' => $b['id'] ?? '',
            ];
        }

        return $rows;
    }
}
