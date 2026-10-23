<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Enterprise MySQL/MariaDB database backup command.
 *
 * Implements Phase 3 SEC production requirements:
 * - Consistent snapshots with InnoDB --single-transaction and --quick.
 * - Full schema coverage: routines, triggers, events, add-drop-table.
 * - Secure credential isolation via temporary .cnf file (no CLI password leakage).
 * - Multi-layer storage outside public web root with safe permissions (0600/0700).
 * - Optional AES-256 encryption at rest.
 * - Gzip compression with streaming verification.
 * - Disk space safety pre-checks.
 * - Dump integrity validation (header/footer markers, non-zero payload).
 * - Safe retention pruning that guarantees active/latest backups are never deleted.
 * - Comprehensive operational logging without credential exposure.
 */
class DatabaseBackupCommand extends Command
{
    protected $signature = 'hms:backup-database
                            {--destination= : Custom directory to store backup files (defaults to storage/app/backups)}
                            {--no-compress : Disable gzip compression (default is compressed .sql.gz)}
                            {--encrypt : Encrypt backup file at rest using AES-256}
                            {--retention= : Days of backups to retain before pruning (defaults to 30)}
                            {--mysqldump-path= : Custom path to mysqldump executable}
                            {--dry-run : Simulate backup creation, validation, and retention without writing or pruning files}';

    protected $description = 'Create a secure, consistent MySQL database backup with compression, optional encryption, integrity verification, and safe retention pruning.';

    public function handle(): int
    {
        $startTime = microtime(true);
        $isDryRun = (bool) $this->option('dry-run');
        $compress = ! $this->option('no-compress');
        $encrypt = (bool) $this->option('encrypt') || (bool) env('BACKUP_ENCRYPTION_ENABLED', false);
        $retentionDays = (int) ($this->option('retention') ?: env('BACKUP_RETENTION_DAYS', 30));

        $this->info($isDryRun ? '[DRY-RUN] Starting HMS Database Backup Simulation...' : 'Starting HMS Database Backup...');

        // 1. Resolve destination directory first (security boundary check)
        $destination = $this->option('destination') ?: env('BACKUP_DESTINATION', storage_path('app/backups'));
        if (! str_starts_with($destination, '/') && ! preg_match('/^[a-zA-Z]:\\\\/', $destination)) {
            $destination = base_path($destination);
        }

        // Ensure directory is outside public web root
        $publicPath = public_path();
        if (str_starts_with(realpath($destination) ?: $destination, realpath($publicPath) ?: $publicPath)) {
            $this->error('Security violation: Backup destination cannot reside inside the public web root.');

            return 1;
        }

        // 2. Resolve and validate database connection
        $connectionName = config('database.default');
        $connectionConfig = config("database.connections.{$connectionName}");

        if (! in_array($connectionConfig['driver'] ?? '', ['mysql', 'mariadb'])) {
            $this->error('Backup command currently supports MySQL and MariaDB drivers. Current driver: '.($connectionConfig['driver'] ?? 'unknown'));

            return 1;
        }

        $database = $connectionConfig['database'] ?? '';
        $host = $connectionConfig['host'] ?? '127.0.0.1';
        $port = (string) ($connectionConfig['port'] ?? '3306');
        $username = $connectionConfig['username'] ?? 'root';
        $password = (string) ($connectionConfig['password'] ?? '');
        $socket = $connectionConfig['unix_socket'] ?? '';

        if (! $isDryRun && ! is_dir($destination)) {
            if (! @mkdir($destination, 0700, true) && ! is_dir($destination)) {
                $this->error("Failed to create destination directory: {$destination}");

                return 1;
            }
            @chmod($destination, 0700);
        }

        // 3. Pre-flight Disk Space Safety Check (minimum 100MB free space required)
        $freeSpace = @disk_free_space($destination);
        $minRequiredSpace = 100 * 1024 * 1024; // 100 MB
        if ($freeSpace !== false && $freeSpace < $minRequiredSpace) {
            $freeMb = round($freeSpace / (1024 * 1024), 2);
            $this->error("Insufficient disk space on backup destination. Available: {$freeMb} MB, Minimum Required: 100 MB.");
            Log::error('HMS backup aborted due to insufficient disk space', [
                'destination' => $destination,
                'free_bytes' => $freeSpace,
            ]);

            return 1;
        }

        // 4. Resolve mysqldump binary
        $mysqldumpPath = $this->resolveMysqldumpBinary();
        if (! $mysqldumpPath) {
            $this->error('Could not locate mysqldump binary. Specify --mysqldump-path or set MYSQLDUMP_PATH in .env.');

            return 1;
        }

        $timestamp = now()->format('Ymd_His');
        $filenameBase = "backup_hms_{$database}_{$timestamp}";
        $rawSqlPath = "{$destination}/{$filenameBase}.sql";
        $finalPath = $compress ? "{$rawSqlPath}.gz" : $rawSqlPath;
        if ($encrypt) {
            $finalPath .= '.enc';
        }

        $this->line("Target Database : {$database}");
        $this->line("Destination     : {$destination}");
        $this->line("mysqldump binary: {$mysqldumpPath}");
        $this->line('Compression     : '.($compress ? 'gzip (.sql.gz)' : 'none (.sql)'));
        $this->line('Encryption      : '.($encrypt ? 'AES-256 enabled (.enc)' : 'disabled'));
        $this->line("Retention Period: {$retentionDays} days");

        if ($isDryRun) {
            $this->info('[DRY-RUN] Pre-flight checks passed successfully.');
            $this->info("[DRY-RUN] Simulated target file: {$finalPath}");
            $this->simulateRetentionPruning($destination, $retentionDays);

            return 0;
        }

        // 5. Build credentials isolation file (.cnf)
        $tempCnfPath = storage_path('framework/cache/dump_'.bin2hex(random_bytes(10)).'.cnf');
        $cnfContent = "[client]\n";
        $cnfContent .= "host=\"{$host}\"\n";
        $cnfContent .= "port=\"{$port}\"\n";
        $cnfContent .= "user=\"{$username}\"\n";
        $cnfContent .= "password=\"{$password}\"\n";
        $cnfContent .= "default-character-set=\"utf8mb4\"\n";
        if (! empty($socket)) {
            $cnfContent .= "socket=\"{$socket}\"\n";
        }

        file_put_contents($tempCnfPath, $cnfContent);
        @chmod($tempCnfPath, 0600);

        try {
            // 6. Execute mysqldump with consistent snapshot options
            $cmd = sprintf(
                '"%s" --defaults-extra-file="%s" --single-transaction --quick --routines --triggers --events --add-drop-table --default-character-set=utf8mb4 --result-file="%s" "%s"',
                $mysqldumpPath,
                $tempCnfPath,
                $rawSqlPath,
                $database
            );

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = proc_open($cmd, $descriptors, $pipes);
            if (! is_resource($process)) {
                throw new \RuntimeException('Failed to spawn mysqldump process.');
            }

            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);

            if ($exitCode !== 0) {
                // Sanitize any accidental password mentions in stderr
                $sanitizedStderr = preg_replace('/password=\S+/i', 'password=***', $stderr);
                throw new \RuntimeException("mysqldump failed with exit code {$exitCode}: {$sanitizedStderr}");
            }

            // 7. Verify dump integrity before downstream processing
            $this->verifyDumpIntegrity($rawSqlPath);

            $workingPath = $rawSqlPath;

            // 8. Compress if requested
            if ($compress) {
                $compressedPath = "{$rawSqlPath}.gz";
                $this->compressToGzip($rawSqlPath, $compressedPath);
                @unlink($rawSqlPath);
                $workingPath = $compressedPath;
                $this->verifyGzipIntegrity($workingPath);
            }

            // 9. Encrypt if requested
            if ($encrypt) {
                $encryptedPath = "{$workingPath}.enc";
                $this->encryptFile($workingPath, $encryptedPath);
                @unlink($workingPath);
                $workingPath = $encryptedPath;
            }

            // Set restrictive permissions on backup file
            @chmod($workingPath, 0600);

            $fileSize = filesize($workingPath);
            $duration = round(microtime(true) - $startTime, 2);
            $sizeFormatted = round($fileSize / (1024 * 1024), 2).' MB';

            $this->info("Backup completed successfully in {$duration}s!");
            $this->line("Artifact: {$workingPath} ({$sizeFormatted})");

            Log::info('Database backup completed successfully', [
                'database' => $database,
                'file' => basename($workingPath),
                'size_bytes' => $fileSize,
                'duration_seconds' => $duration,
                'compressed' => $compress,
                'encrypted' => $encrypt,
            ]);

            // 10. Execute safe retention pruning (only after confirmed backup success)
            $this->pruneOldBackups($destination, $retentionDays, basename($workingPath));

            return 0;
        } catch (\Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());

            Log::error('Database backup failed', [
                'database' => $database,
                'error' => $e->getMessage(),
                'destination' => $destination,
            ]);

            // Clean up any partially created files
            if (isset($rawSqlPath) && file_exists($rawSqlPath)) {
                @unlink($rawSqlPath);
            }
            if (isset($compressedPath) && file_exists($compressedPath)) {
                @unlink($compressedPath);
            }
            if (isset($encryptedPath) && file_exists($encryptedPath)) {
                @unlink($encryptedPath);
            }

            return 1;
        } finally {
            // Securely overwrite and unlink credential file
            if (file_exists($tempCnfPath)) {
                $len = filesize($tempCnfPath) ?: 512;
                file_put_contents($tempCnfPath, str_repeat("\0", $len));
                @unlink($tempCnfPath);
            }
        }
    }

    protected function resolveMysqldumpBinary(): ?string
    {
        $custom = $this->option('mysqldump-path') ?: env('MYSQLDUMP_PATH');
        if ($custom && (file_exists($custom) || $this->isBinaryCallable($custom))) {
            return $custom;
        }

        // Standard Windows XAMPP path
        $xamppPath = 'c:\\xampp\\mysql\\bin\\mysqldump.exe';
        if (file_exists($xamppPath)) {
            return $xamppPath;
        }

        // Common Linux paths
        $linuxPaths = ['/usr/bin/mysqldump', '/usr/local/bin/mysqldump', '/usr/mysql/bin/mysqldump'];
        foreach ($linuxPaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        // System PATH
        if ($this->isBinaryCallable('mysqldump')) {
            return 'mysqldump';
        }

        return null;
    }

    protected function isBinaryCallable(string $binary): bool
    {
        $testCmd = PHP_OS_FAMILY === 'Windows' ? "where {$binary} 2>nul" : "which {$binary} 2>/dev/null";
        exec($testCmd, $output, $code);

        return $code === 0 && ! empty($output);
    }

    protected function verifyDumpIntegrity(string $filePath): void
    {
        if (! file_exists($filePath)) {
            throw new \RuntimeException('Dump file was not created.');
        }

        $size = filesize($filePath);
        if ($size < 1024) {
            throw new \RuntimeException("Dump file is suspiciously small ({$size} bytes), indicating an empty or failed export.");
        }

        $handle = fopen($filePath, 'r');
        if (! $handle) {
            throw new \RuntimeException('Cannot read generated dump file for integrity check.');
        }

        $header = fread($handle, 1024);
        if (! str_contains($header, 'MySQL dump') && ! str_contains($header, 'MariaDB dump')) {
            fclose($handle);
            throw new \RuntimeException('Dump file header missing valid MySQL/MariaDB export signature.');
        }

        // Check footer for completion marker
        fseek($handle, -2048, SEEK_END);
        $footer = fread($handle, 2048);
        fclose($handle);

        if (! str_contains($footer, 'Dump completed')) {
            throw new \RuntimeException('Dump file is truncated or missing the "Dump completed" trailer.');
        }
    }

    protected function compressToGzip(string $sourcePath, string $destPath): void
    {
        $src = fopen($sourcePath, 'rb');
        if (! $src) {
            throw new \RuntimeException("Cannot open {$sourcePath} for compression.");
        }

        $dst = gzopen($destPath, 'wb9');
        if (! $dst) {
            fclose($src);
            throw new \RuntimeException("Cannot create {$destPath} for gzip output.");
        }

        while (! feof($src)) {
            gzwrite($dst, fread($src, 65536));
        }

        fclose($src);
        gzclose($dst);
    }

    protected function verifyGzipIntegrity(string $path): void
    {
        $gz = @gzopen($path, 'rb');
        if (! $gz) {
            throw new \RuntimeException("Cannot open gzipped backup {$path} for integrity verification.");
        }

        $header = gzread($gz, 512);
        if ($header === false || (! str_contains($header, 'MySQL dump') && ! str_contains($header, 'MariaDB dump'))) {
            gzclose($gz);
            throw new \RuntimeException('Gzip integrity check failed: missing database signature inside compressed stream.');
        }

        while (! gzeof($gz)) {
            if (gzread($gz, 65536) === false) {
                gzclose($gz);
                throw new \RuntimeException('Gzip stream encountered a CRC/decompression read error.');
            }
        }

        gzclose($gz);
    }

    protected function encryptFile(string $sourcePath, string $destPath): void
    {
        $cipher = 'aes-256-cbc';
        $keyRaw = config('app.key');
        if (str_starts_with($keyRaw, 'base64:')) {
            $keyRaw = base64_decode(substr($keyRaw, 7));
        }
        $key = hash('sha256', $keyRaw, true);

        $iv = random_bytes(openssl_cipher_iv_length($cipher));

        $src = fopen($sourcePath, 'rb');
        $dst = fopen($destPath, 'wb');
        if (! $src || ! $dst) {
            throw new \RuntimeException('Failed opening streams for backup encryption.');
        }

        // Header: HMSENC1 (7 bytes) + IV (16 bytes)
        fwrite($dst, 'HMSENC1'.$iv);

        // Read in blocks of 64KB, encrypt and write
        $plainData = fread($src, filesize($sourcePath));
        $encryptedData = openssl_encrypt($plainData, $cipher, $key, OPENSSL_RAW_DATA, $iv);
        if ($encryptedData === false) {
            fclose($src);
            fclose($dst);
            throw new \RuntimeException('OpenSSL encryption failed: '.openssl_error_string());
        }

        // Write HMAC for integrity
        $hmac = hash_hmac('sha256', $encryptedData, $key, true);
        fwrite($dst, $hmac);
        fwrite($dst, $encryptedData);

        fclose($src);
        fclose($dst);
    }

    protected function pruneOldBackups(string $directory, int $retentionDays, string $currentBackupFile): void
    {
        $files = glob("{$directory}/backup_hms_*");
        if (empty($files)) {
            return;
        }

        // Never prune if only 1 backup exists
        if (count($files) <= 1) {
            $this->line('Retention: Only '.count($files).' backup exists. Retaining all files.');

            return;
        }

        $cutoff = now()->subDays($retentionDays)->timestamp;
        $prunedCount = 0;

        foreach ($files as $file) {
            $filename = basename($file);
            // Never prune the backup that was just created
            if ($filename === $currentBackupFile) {
                continue;
            }

            $mtime = filemtime($file);
            if ($mtime < $cutoff) {
                @unlink($file);
                $prunedCount++;
                $this->line("Retention pruned: {$filename}");
                Log::info('Pruned aged database backup file', ['file' => $filename, 'retention_days' => $retentionDays]);
            }
        }

        if ($prunedCount > 0) {
            $this->info("Retention cleanup: pruned {$prunedCount} backups older than {$retentionDays} days.");
        } else {
            $this->line("Retention check: No backups older than {$retentionDays} days found to prune.");
        }
    }

    protected function simulateRetentionPruning(string $directory, int $retentionDays): void
    {
        $files = glob("{$directory}/backup_hms_*");
        $cutoff = now()->subDays($retentionDays)->timestamp;
        $prunable = [];

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                $prunable[] = basename($file);
            }
        }

        $this->info('[DRY-RUN] Retention simulation: '.count($prunable)." backups would be pruned (older than {$retentionDays} days).");
        foreach ($prunable as $p) {
            $this->line("  - [WOULD DELETE] {$p}");
        }
    }
}
