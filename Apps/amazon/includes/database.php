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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mail_execution_runs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_id INT NOT NULL,
            email_address VARCHAR(255) NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'Idle',
            emails_processed INT NOT NULL DEFAULT 0,
            last_email_at DATETIME DEFAULT NULL,
            current_operation VARCHAR(255) DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            stop_requested TINYINT(1) NOT NULL DEFAULT 0,
            worker_pid INT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX account_status_idx (account_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mail_execution_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            run_id INT NOT NULL,
            account_id INT NOT NULL,
            email_address VARCHAR(255) NOT NULL,
            message_id VARCHAR(255) NOT NULL UNIQUE,
            sender VARCHAR(255) DEFAULT NULL,
            recipient VARCHAR(255) DEFAULT NULL,
            subject VARCHAR(500) DEFAULT NULL,
            verification_url TEXT DEFAULT NULL,
            email_text MEDIUMTEXT DEFAULT NULL,
            email_json MEDIUMTEXT DEFAULT NULL,
            email_received TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(40) NOT NULL DEFAULT 'Idle',
            error_message TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX run_id_idx (run_id),
            INDEX account_id_idx (account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    ensure_column($pdo, 'mail_execution_events', 'sender', "VARCHAR(255) DEFAULT NULL");
    ensure_column($pdo, 'mail_execution_events', 'recipient', "VARCHAR(255) DEFAULT NULL");
    ensure_column($pdo, 'mail_execution_events', 'subject', "VARCHAR(500) DEFAULT NULL");
    ensure_column($pdo, 'mail_execution_events', 'verification_url', "TEXT DEFAULT NULL");
    ensure_column($pdo, 'mail_execution_events', 'email_text', "MEDIUMTEXT DEFAULT NULL");
    ensure_column($pdo, 'mail_execution_events', 'email_json', "MEDIUMTEXT DEFAULT NULL");
}

function ensure_column($pdo, $table, $column, $definition)
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);

    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
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
