<?php
/**
 * TEMPORARY DEBUG SCRIPT - DELETE AFTER TESTING
 * Test if PHP sessions work at all
 */

session_start();

// Set a test value
if (!isset($_SESSION['test_count'])) {
    $_SESSION['test_count'] = 1;
} else {
    $_SESSION['test_count']++;
}

header('Content-Type: text/plain');
echo "PHP Session Test\n";
echo "================\n\n";
echo "Session ID: " . session_id() . "\n";
echo "Visit count: " . $_SESSION['test_count'] . "\n\n";
echo "Cookie settings:\n";
echo "- session.cookie_secure: " . ini_get('session.cookie_secure') . "\n";
echo "- session.cookie_httponly: " . ini_get('session.cookie_httponly') . "\n";
echo "- session.cookie_samesite: " . ini_get('session.cookie_samesite') . "\n";
echo "\nCookie sent:\n";
echo "- Name: " . session_name() . "\n";
echo "- Value: " . session_id() . "\n";
echo "\nIf visit count increases on refresh, sessions work!\n";
echo "If it stays at 1, sessions are broken.\n";
