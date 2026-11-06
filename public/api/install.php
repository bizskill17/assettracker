<?php
/**
 * Creates the tables on first run and loads the default masters
 * (categories, movement reasons, item types). Safe to run repeatedly.
 */
declare(strict_types=1);

const SCHEMA_VERSION = 1;

function ensureSchema(PDO $db): void
{
    try {
        $v = (int) $db->query("SELECT v FROM app_meta WHERE k = 'schema'")->fetchColumn();
        if ($v >= SCHEMA_VERSION) {
            return;
        }
    } catch (PDOException $e) {
        // app_meta missing → fresh database
    }

    $tables = [
        "CREATE TABLE IF NOT EXISTS app_meta (
            k VARCHAR(40) PRIMARY KEY, v VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS blocks (
            id VARCHAR(40) PRIMARY KEY,
            name VARCHAR(120) NOT NULL DEFAULT '', code VARCHAR(8) NOT NULL DEFAULT '',
            kind VARCHAR(30) NOT NULL DEFAULT 'Residential', note VARCHAR(255) NOT NULL DEFAULT '',
            sort_order INT NULL, sample TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS rooms (
            id VARCHAR(40) PRIMARY KEY,
            block_id VARCHAR(40) NOT NULL DEFAULT '', number VARCHAR(60) NOT NULL DEFAULT '',
            floor VARCHAR(30) NOT NULL DEFAULT '', type VARCHAR(40) NOT NULL DEFAULT '',
            capacity INT NULL, sort_order INT NULL, sample TINYINT(1) NOT NULL DEFAULT 0,
            KEY idx_rooms_block (block_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS users (
            id VARCHAR(40) PRIMARY KEY,
            name VARCHAR(120) NOT NULL DEFAULT '', role VARCHAR(40) NOT NULL DEFAULT '',
            block_id VARCHAR(40) NOT NULL DEFAULT '', phone VARCHAR(40) NOT NULL DEFAULT '',
            email VARCHAR(190) NULL, active VARCHAR(3) NOT NULL DEFAULT 'Yes',
            password_hash VARCHAR(255) NULL, sort_order INT NULL, sample TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY uq_users_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS item_types (
            id VARCHAR(40) PRIMARY KEY,
            name VARCHAR(120) NOT NULL DEFAULT '', category VARCHAR(80) NOT NULL DEFAULT '',
            prefix VARCHAR(10) NOT NULL DEFAULT '', sort_order INT NULL, sample TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS vendors (
            id VARCHAR(40) PRIMARY KEY,
            name VARCHAR(160) NOT NULL DEFAULT '', kind VARCHAR(40) NOT NULL DEFAULT '',
            phone VARCHAR(40) NOT NULL DEFAULT '', contact VARCHAR(120) NOT NULL DEFAULT '',
            notes TEXT NULL, sort_order INT NULL, sample TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS categories (
            id VARCHAR(40) PRIMARY KEY,
            name VARCHAR(80) NOT NULL DEFAULT '', sort_order INT NULL, sample TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS reasons (
            id VARCHAR(40) PRIMARY KEY,
            name VARCHAR(80) NOT NULL DEFAULT '', status_after VARCHAR(30) NOT NULL DEFAULT '',
            dest VARCHAR(20) NOT NULL DEFAULT '', sort_order INT NULL, sample TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS assets (
            id VARCHAR(40) PRIMARY KEY,
            tag VARCHAR(40) NOT NULL, name VARCHAR(200) NOT NULL DEFAULT '',
            item_type_id VARCHAR(40) NOT NULL DEFAULT '', category VARCHAR(80) NOT NULL DEFAULT '',
            block_id VARCHAR(40) NOT NULL DEFAULT '', room VARCHAR(60) NOT NULL DEFAULT '',
            status VARCHAR(30) NOT NULL DEFAULT 'In use', cond VARCHAR(20) NOT NULL DEFAULT 'Good',
            supplier_id VARCHAR(40) NOT NULL DEFAULT '', notes TEXT NULL,
            created_at BIGINT NULL, updated_at BIGINT NULL, last_moved_at BIGINT NULL,
            sample TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY uq_assets_tag (tag), KEY idx_assets_block (block_id), KEY idx_assets_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS movements (
            id VARCHAR(40) PRIMARY KEY,
            asset_id VARCHAR(40) NOT NULL DEFAULT '', asset_tag VARCHAR(40) NOT NULL DEFAULT '',
            asset_name VARCHAR(200) NOT NULL DEFAULT '',
            from_block_id VARCHAR(40) NOT NULL DEFAULT '', from_room VARCHAR(60) NOT NULL DEFAULT '',
            to_block_id VARCHAR(40) NOT NULL DEFAULT '', to_room VARCHAR(60) NOT NULL DEFAULT '',
            reason VARCHAR(80) NOT NULL DEFAULT '', status_after VARCHAR(30) NOT NULL DEFAULT '',
            handled_by VARCHAR(120) NOT NULL DEFAULT '', vendor VARCHAR(160) NOT NULL DEFAULT '',
            notes TEXT NULL, at BIGINT NULL, logged_at BIGINT NULL, logged_by VARCHAR(40) NOT NULL DEFAULT '',
            sample TINYINT(1) NOT NULL DEFAULT 0,
            KEY idx_mv_at (at), KEY idx_mv_asset (asset_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($tables as $sql) {
        $db->exec($sql);
    }

    // Default masters — only when the table is empty, so edits are never overwritten.
    $seed = [
        'categories' => [
            ['c1', 'Furniture', 1], ['c2', 'Bedding', 2], ['c3', 'Electrical', 3], ['c4', 'Electronics', 4],
            ['c5', 'Kitchen & Mess', 5], ['c6', 'Sports', 6], ['c7', 'Cleaning', 7], ['c8', 'Other', 8],
        ],
        'reasons' => [
            ['r01', 'Room allotment', 'In use', '', 1], ['r02', 'Shifted between blocks', 'In use', '', 2],
            ['r03', 'Sent for repair', 'Under repair', 'Repair', 3], ['r04', 'Returned from repair', 'In use', '', 4],
            ['r05', 'Moved to store', 'In store', 'Store', 5], ['r06', 'Issued from store', 'In use', '', 6],
            ['r07', 'Replacement', 'In use', '', 7], ['r08', 'Reported missing', 'Missing', 'Same', 8],
            ['r09', 'Disposal', 'Disposed', 'Same', 9], ['r10', 'Other', '', '', 10],
        ],
        'item_types' => [
            ['tBED', 'Single bed, steel frame', 'Furniture', 'BED'], ['tMAT', 'Mattress, 4in foam', 'Bedding', 'MAT'],
            ['tFAN', 'Ceiling fan 48in', 'Electrical', 'FAN'], ['tTBL', 'Study table', 'Furniture', 'TBL'],
            ['tCHR', 'Plastic chair', 'Furniture', 'CHR'], ['tCOT', 'Cot with storage', 'Furniture', 'COT'],
            ['tGEY', 'Water geyser 25L', 'Electrical', 'GEY'], ['tAC', 'Split AC 1.5 ton', 'Electrical', 'AC'],
            ['tFRG', 'Refrigerator', 'Kitchen & Mess', 'FRG'], ['tTV', 'LED TV', 'Electronics', 'TV'],
            ['tWP', 'Water purifier RO', 'Kitchen & Mess', 'WP'], ['tTT', 'Table tennis table', 'Sports', 'TT'],
        ],
    ];
    $sqls = [
        'categories' => "INSERT INTO categories (id, name, sort_order) VALUES (?, ?, ?)",
        'reasons' => "INSERT INTO reasons (id, name, status_after, dest, sort_order) VALUES (?, ?, ?, ?, ?)",
        'item_types' => "INSERT INTO item_types (id, name, category, prefix) VALUES (?, ?, ?, ?)",
    ];
    foreach ($seed as $table => $rows) {
        if ((int) $db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() > 0) {
            continue;
        }
        $st = $db->prepare($sqls[$table]);
        foreach ($rows as $r) {
            $st->execute($r);
        }
    }

    $db->prepare("REPLACE INTO app_meta (k, v) VALUES ('schema', ?)")->execute([(string) SCHEMA_VERSION]);
}
