<?php

declare(strict_types=1);

namespace SendRepute\Laravel\CustomerApi;

/**
 * Filesystem intent store. SINGLE HOST ONLY: flock() is atomic across PHP
 * workers on one machine, and is released automatically if a worker dies, but
 * it is not reliable across machines or on network filesystems. Construction
 * fails unless $singleHost is acknowledged and the directory is absolute,
 * private (0700) and owned by the PHP user.
 */
final class FileIntentStore extends IntentLedger
{
    private readonly string $dir;

    public function __construct(string $directory, bool $singleHost)
    {
        if (!$singleHost) {
            throw new CustomerApiException('configuration', 'The filesystem intent store supports a single host only; acknowledge single_host to enable it.');
        }
        if ($directory === '' || $directory[0] !== '/') {
            throw new CustomerApiException('configuration', 'Intent store directory must be an absolute path.');
        }
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new CustomerApiException('configuration', 'Intent store directory cannot be created.');
        }
        clearstatcache(true, $directory);
        if (is_link($directory) || !is_dir($directory)) {
            throw new CustomerApiException('configuration', 'Intent store directory must be a real directory.');
        }
        if ((fileperms($directory) & 0077) !== 0) {
            throw new CustomerApiException('configuration', 'Intent store directory must not be group or world accessible (chmod 700).');
        }
        if (function_exists('posix_geteuid') && fileowner($directory) !== posix_geteuid()) {
            throw new CustomerApiException('configuration', 'Intent store directory must be owned by the PHP user.');
        }
        $this->dir = rtrim($directory, '/');
    }

    protected function atomic(string $key, callable $fn): mixed
    {
        $lock = fopen($this->dir.'/'.$key.'.lock', 'c');
        if ($lock === false) {
            throw new IntentConflict(503, 'INTENT_STORE_UNAVAILABLE', 'Intent store is unavailable; nothing was sent');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new IntentConflict(503, 'INTENT_STORE_BUSY', 'Intent store lock failed; nothing was sent');
            }
            $file = $this->dir.'/'.$key.'.json';
            $current = null;
            if (is_file($file)) {
                $current = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
            }
            [$next, $result] = $fn($current);
            if (is_array($next)) {
                $tmp = $this->dir.'/'.$key.'.'.bin2hex(random_bytes(6)).'.tmp';
                $h = fopen($tmp, 'x');
                if ($h === false) {
                    throw new IntentConflict(503, 'INTENT_STORE_UNAVAILABLE', 'Intent store write failed; nothing was sent');
                }
                fwrite($h, json_encode($next, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                fflush($h);
                if (function_exists('fsync')) {
                    fsync($h);
                }
                fclose($h);
                chmod($tmp, 0600);
                if (!rename($tmp, $file)) {
                    @unlink($tmp);
                    throw new IntentConflict(503, 'INTENT_STORE_UNAVAILABLE', 'Intent store write failed; nothing was sent');
                }
            }

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    protected function all(): array
    {
        $out = [];
        foreach (glob($this->dir.'/*.json') ?: [] as $file) {
            if (preg_match('#/[a-f0-9]{64}\.json\z#', $file) !== 1) {
                continue;
            }
            $row = json_decode((string) file_get_contents($file), true);
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }
}
