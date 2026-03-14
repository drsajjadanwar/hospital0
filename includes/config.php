<?php
// /includes/config.php

// Autoload Composer dependencies (make sure your vendor folder is in your project root)
require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables from the .env file
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Create a PDO connection using credentials from the environment
try {
    $dsn = "mysql:host={$_ENV['DB_HOST']};port={$_ENV['DB_PORT']};dbname={$_ENV['DB_NAME']};charset=utf8mb4";
    $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // Uncomment below for testing (but remove in production)
    //echo "Database connection established successfully.";
} catch (PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    die("A problem occurred while connecting to the database.");
}
?>
