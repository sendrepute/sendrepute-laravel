<?php

declare(strict_types=1);

/*
 * Creates a local, unpublished package artifact from a closed allowlist.
 * Usage: php scripts/package.php dist/sendrepute-laravel.zip
 */

$root = dirname(__DIR__);
$output = $argv[1] ?? null;
if (!is_string($output) || preg_match('#^dist/[A-Za-z0-9._-]+\.zip$#', $output) !== 1) {
    fwrite(STDERR, "Usage: php scripts/package.php dist/<artifact-name>.zip\n");
    exit(2);
}
if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "The PHP zip extension is required.\n");
    exit(2);
}

$files = ['composer.json', 'LICENSE', 'README.md', 'config/sendrepute.php'];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root.'/src', FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->isLink() || $file->getExtension() !== 'php') {
        fwrite(STDERR, "Refusing unexpected source entry: {$file->getPathname()}\n");
        exit(1);
    }
    $files[] = substr($file->getPathname(), strlen($root) + 1);
}
sort($files);

$secretPattern = '/(?:-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|^SENDREPUTE_API_KEY[ \t]*=[ \t]*\S+|Bearer[ \t]+[A-Za-z0-9._~+\/=-]{16,})/im';
foreach ($files as $relative) {
    $path = $root.'/'.$relative;
    if (!is_file($path) || is_link($path)) {
        fwrite(STDERR, "Refusing missing or linked allowlisted file: {$relative}\n");
        exit(1);
    }
    $contents = file_get_contents($path);
    if ($contents === false || preg_match($secretPattern, $contents)) {
        fwrite(STDERR, "Refusing possible credential material in: {$relative}\n");
        exit(1);
    }
}

$destination = $root.'/'.$output;
$directory = dirname($destination);
if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
    fwrite(STDERR, "Unable to create output directory.\n");
    exit(1);
}
$zip = new ZipArchive();
if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Unable to create archive.\n");
    exit(1);
}
foreach ($files as $relative) {
    if ($relative === 'composer.json') {
        $metadata = json_decode(file_get_contents($root.'/'.$relative), true, 512, JSON_THROW_ON_ERROR);
        $metadata['version'] = '0.1.0';
        $added = $zip->addFromString(
            'sendrepute-laravel/'.$relative,
            json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n"
        );
    } else {
        $added = $zip->addFile($root.'/'.$relative, 'sendrepute-laravel/'.$relative);
    }
    if (!$added) {
        $zip->close();
        @unlink($destination);
        fwrite(STDERR, "Unable to add allowlisted file: {$relative}\n");
        exit(1);
    }
}
$zip->close();
fwrite(STDOUT, "Created unpublished local artifact: {$destination}\n");