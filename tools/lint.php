<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$arguments = array_slice($argv, 1);
if ($arguments !== [] && $arguments !== ['--include-untracked']) {
    fwrite(STDERR, 'Usage: php tools/lint.php [--include-untracked]' . PHP_EOL);
    exit(2);
}

$filesCommand = ['git', '-C', $root, 'ls-files', '-z', '--cached'];
if ($arguments !== []) {
    array_push($filesCommand, '--others', '--exclude-standard');
}
array_push($filesCommand, '--', '*.php');
$git = proc_open($filesCommand, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
if (!is_resource($git)) {
    fwrite(STDERR, 'Could not start Git.' . PHP_EOL);
    exit(2);
}
fclose($pipes[0]);
$listing = stream_get_contents($pipes[1]);
fclose($pipes[1]);
if (proc_close($git) !== 0 || $listing === false || $listing === '') {
    fwrite(STDERR, 'Could not list PHP files from Git.' . PHP_EOL);
    exit(2);
}

$files = array_unique(explode("\0", rtrim($listing, "\0")));
$failed = 0;
foreach ($files as $file) {
    $path = $root . DIRECTORY_SEPARATOR . $file;
    $lint = proc_open(
        [PHP_BINARY, '-n', '-l', $path],
        [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR],
        $pipes,
        options: ['bypass_shell' => true],
    );
    if (!is_resource($lint)) {
        ++$failed;
        continue;
    }
    fclose($pipes[0]);
    $failed += (int) (proc_close($lint) !== 0);
}

$total = count($files);
$passed = $total - $failed;
echo "$passed/$total PHP files passed lint.", PHP_EOL;
exit((int) ($failed > 0));
