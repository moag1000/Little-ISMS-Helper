<?php
// PHPStan bootstrap: register this worktree's tests/ directory so
// App\Tests\PHPStan\Rule\NoDirectSetStatusRule can be loaded when
// vendor/ is symlinked from a different worktree whose autoload.php
// does not know this directory. NOT needed in CI (vendor is built
// from this repo directly and maps App\Tests\ correctly).
$baseDir = __DIR__;

spl_autoload_register(static function (string $class) use ($baseDir): void {
    $prefix = 'App\\Tests\\';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative = substr($class, $len);
    $file = $baseDir . '/tests/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
