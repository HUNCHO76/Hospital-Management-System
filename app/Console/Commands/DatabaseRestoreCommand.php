<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Enterprise database restore verification command.
 *
 * Implements Phase 3 SEC safe restoration testing:
 * - Restores backup archives (.sql, .sql.gz, or .sql.gz.enc) into an isolated test database.
 * - Protects active production database from accidental overwrites.
 * - Credential isolation using temporary .cnf file.
 * - Integrity verification during decompression and decryption.
 */
class DatabaseRestoreCommand extends Command
{
    protected $signature = 'hms:restore-database
                            {file : Path to the backup archive file}
                            {--target-database= : Target database to restore into (default: isolated test db)}
                            {--mysql-path= : Custom path to mysql client executable}
                            {--force : Force restore even if target matches active database}';

    protected $description = 'Safely restore a database backup archive into a target or isolated verification database.';

    public function handle(): int
    {
        $filePath = $this->argument('file');
        if (! file_exists($filePath)) {
            $this->error("Backup file not found: {$filePath}");

            return 1;
        }

        $connectionName = config('database.default');
        $connectionConfig = config("database.connections.{$connectionName}");
        $activeDatabase = $connectionConfig['database'] ?? '';

        $targetDatabase = $this->option('target-database');
        if (! $targetDatabase) {
            $this->error('Target database must be explicitly specified (e.g. --target-database=hms_test_restore).');

            return 1;
        }

        // Safeguard: Never overwrite active production database without explicit confirmation and --force
        if (strtolower($targetDatabase) === strtolower($activeDatabase) && ! $this->option('force')) {
            $this->error("Target database '{$targetDatabase}' is the ACTIVE application database!");
            $this->error('Refusing to restore without --force to prevent catastrophic production data loss.');

            return 1;
        }

        $host = $connectionConfig['host'] ?? '127.0.0.1';
        $port = (string) ($connectionConfig['port'] ?? '3306');
        $username = $connectionConfig['username'] ?? 'root';
        $password = (string) ($connectionConfig['password'] ?? '');

        $mysqlPath = $this->resolveMysqlBinary();
        if (! $mysqlPath) {
            $this->error('Could not locate mysql client binary.');

            return 1;
        }

        $this->info("Restoring backup archive into target database: {$targetDatabase}");

        // Working temp paths
        $tempFiles = [];
        $currentPath = $filePath;

        try {
            // 1. Decrypt if encrypted (.enc)
            if (str_ends_with($currentPath, '.enc')) {
                $this->line('Decrypting archive with AES-256...');
                $decryptedPath = storage_path('framework/cache/restore_'.bin2hex(random_bytes(6)).'.dec');
                $tempFiles[] = $decryptedPath;
                $this->decryptFile($currentPath, $decryptedPath);
                $currentPath = $decryptedPath;
            }

            // 2. Decompress if gzipped (.gz or was .gz.enc)
            if (str_ends_with($filePath, '.gz') || str_ends_with($filePath, '.gz.enc')) {
                $this->line('Decompressing gzip archive...');
                $decompressedPath = storage_path('framework/cache/restore_'.bin2hex(random_bytes(6)).'.sql');
                $tempFiles[] = $decompressedPath;
                $this->decompressGzip($currentPath, $decompressedPath);
                $currentPath = $decompressedPath;
            }

            // 3. Ensure target database exists
            $pdo = DB::connection()->getPdo();
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$targetDatabase}`");

            // 4. Build temporary .cnf credentials file
            $tempCnfPath = storage_path('framework/cache/restore_'.bin2hex(random_bytes(6)).'.cnf');
            $tempFiles[] = $tempCnfPath;
            $cnfContent = "[client]\n";
            $cnfContent .= "host=\"{$host}\"\n";
            $cnfContent .= "port=\"{$port}\"\n";
            $cnfContent .= "user=\"{$username}\"\n";
            $cnfContent .= "password=\"{$password}\"\n";
            $cnfContent .= "default-character-set=\"utf8mb4\"\n";
            file_put_contents($tempCnfPath, $cnfContent);
            @chmod($tempCnfPath, 0600);

            // 5. Execute restore via mysql client
            $cmd = sprintf(
                '"%s" --defaults-extra-file="%s" "%s"',
                $mysqlPath,
                $tempCnfPath,
                $targetDatabase
            );

            $descriptors = [
                0 => ['file', $currentPath, 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = proc_open($cmd, $descriptors, $pipes);
            if (! is_resource($process)) {
                throw new \RuntimeException('Failed to spawn mysql client process.');
            }

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);

            if ($exitCode !== 0) {
                $sanitizedStderr = preg_replace('/password=\S+/i', 'password=***', $stderr);
                throw new \RuntimeException("mysql restore failed with exit code {$exitCode}: {$sanitizedStderr}");
            }

            $this->info("Restore completed successfully into '{$targetDatabase}'!");

            return 0;
        } catch (\Throwable $e) {
            $this->error('Restore failed: '.$e->getMessage());

            return 1;
        } finally {
            foreach ($tempFiles as $temp) {
                if (file_exists($temp)) {
                    @unlink($temp);
                }
            }
        }
    }

    protected function resolveMysqlBinary(): ?string
    {
        $custom = $this->option('mysql-path');
        if ($custom && file_exists($custom)) {
            return $custom;
        }

        $xamppPath = 'c:\\xampp\\mysql\\bin\\mysql.exe';
        if (file_exists($xamppPath)) {
            return $xamppPath;
        }

        $linuxPaths = ['/usr/bin/mysql', '/usr/local/bin/mysql'];
        foreach ($linuxPaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return 'mysql';
    }

    protected function decompressGzip(string $sourcePath, string $destPath): void
    {
        $gz = gzopen($sourcePath, 'rb');
        $dst = fopen($destPath, 'wb');
        if (! $gz || ! $dst) {
            throw new \RuntimeException('Failed opening files for gzip decompression.');
        }

        while (! gzeof($gz)) {
            fwrite($dst, gzread($gz, 65536));
        }

        gzclose($gz);
        fclose($dst);
    }

    protected function decryptFile(string $sourcePath, string $destPath): void
    {
        $cipher = 'aes-256-cbc';
        $keyRaw = config('app.key');
        if (str_starts_with($keyRaw, 'base64:')) {
            $keyRaw = base64_decode(substr($keyRaw, 7));
        }
        $key = hash('sha256', $keyRaw, true);

        $src = fopen($sourcePath, 'rb');
        $dst = fopen($destPath, 'wb');
        if (! $src || ! $dst) {
            throw new \RuntimeException('Failed opening files for decryption.');
        }

        $header = fread($src, 7);
        if ($header !== 'HMSENC1') {
            fclose($src);
            fclose($dst);
            throw new \RuntimeException('Invalid encrypted backup archive header.');
        }

        $iv = fread($src, openssl_cipher_iv_length($cipher));
        $expectedHmac = fread($src, 32);
        $encryptedData = stream_get_contents($src);

        // Verify HMAC
        $actualHmac = hash_hmac('sha256', $encryptedData, $key, true);
        if (! hash_equals($expectedHmac, $actualHmac)) {
            fclose($src);
            fclose($dst);
            throw new \RuntimeException('Backup integrity failure: HMAC signature does not match (file may be corrupted or tampered).');
        }

        $decryptedData = openssl_decrypt($encryptedData, $cipher, $key, OPENSSL_RAW_DATA, $iv);
        if ($decryptedData === false) {
            fclose($src);
            fclose($dst);
            throw new \RuntimeException('Decryption failed: '.openssl_error_string());
        }

        fwrite($dst, $decryptedData);
        fclose($src);
        fclose($dst);
    }
}
