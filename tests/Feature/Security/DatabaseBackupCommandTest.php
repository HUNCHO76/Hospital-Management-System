<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DatabaseBackupCommandTest extends TestCase
{
    public function test_backup_dry_run_executes_successfully_without_writing_files(): void
    {
        // Temporarily configure mysql connection for testing
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'hmsdb',
            'database.connections.mysql.driver' => 'mysql',
        ]);

        $dest = storage_path('app/backups_test_dry_run');

        $exitCode = Artisan::call('hms:backup-database', [
            '--dry-run' => true,
            '--destination' => $dest,
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('[DRY-RUN]', $output);
        $this->assertStringContainsString('Pre-flight checks passed successfully', $output);
        $this->assertDirectoryDoesNotExist($dest);
    }

    public function test_backup_rejects_destination_inside_public_web_root(): void
    {
        $publicDest = public_path('leaked_backups');

        $exitCode = Artisan::call('hms:backup-database', [
            '--destination' => $publicDest,
        ]);

        $this->assertSame(1, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('Security violation', $output);
        $this->assertStringContainsString('public web root', $output);
    }

    public function test_restore_command_refuses_to_overwrite_active_database_without_force(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'hmsdb',
        ]);

        // Dummy backup file
        $tempFile = storage_path('framework/cache/dummy_backup.sql');
        file_put_contents($tempFile, '-- test dump');

        try {
            $exitCode = Artisan::call('hms:restore-database', [
                'file' => $tempFile,
                '--target-database' => 'hmsdb',
            ]);

            $this->assertSame(1, $exitCode);
            $output = Artisan::output();
            $this->assertStringContainsString('ACTIVE application database', $output);
            $this->assertStringContainsString('Refusing to restore without --force', $output);
        } finally {
            @unlink($tempFile);
        }
    }
}
