<?php

/**
 * Worktree bootstrap for PHPUnit.
 *
 * The worktree has no vendor/ symlink so we load the main project's autoloader
 * and prepend this worktree's src/ + tests/ so that new classes (not yet on main)
 * are found first.
 */

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

$mainRoot = dirname(__DIR__, 4); // .claude/worktrees/<tree>/ → main project root
$loader   = require $mainRoot . '/vendor/autoload.php';
$treeRoot = __DIR__ . '/..';

$loader->addPsr4('App\\',       [$treeRoot . '/src'],   true);
$loader->addPsr4('App\\Tests\\', [$treeRoot . '/tests'], true);

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv($mainRoot . '/.env');
}
