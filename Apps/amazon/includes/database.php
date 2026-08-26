<?php
require_once __DIR__ . '/../db.php';

function ensure_amazon_tables($pdo)
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            aws_account_id VARCHAR(20) NOT NULL UNIQUE,
            aws_key VARCHAR(255) NOT NULL,
            aws_secret TEXT NOT NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS number_update_jobs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_id INT NOT NULL,
            email VARCHAR(255) NOT NULL,
            target_aws_account_id VARCHAR(20) NOT NULL,
            numbers MEDIUMTEXT NOT NULL,
            current_index INT NOT NULL DEFAULT 0,
            total_numbers INT NOT NULL DEFAULT 0,
            current_phone VARCHAR(50) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Idle',
            message TEXT DEFAULT NULL,
            stop_requested TINYINT(1) NOT NULL DEFAULT 0,
            next_run_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX account_status_idx (account_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function mask_value($value)
{
    $value = (string) $value;

    if (strlen($value) <= 8) {
        return str_repeat('*', strlen($value));
    }

    return substr($value, 0, 4) . str_repeat('*', 8) . substr($value, -4);
}
?>
