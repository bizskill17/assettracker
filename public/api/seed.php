<?php
/**
 * Loads sample data across Block A–D only: rooms, users, vendors, assets and movements.
 * Every row is marked sample = 1, so "Remove sample data" in the app deletes it again.
 * Safe to run more than once: rows that already exist (same id, or same asset tag) are skipped.
 */
declare(strict_types=1);

function seedSample(PDO $db): array
{
    $now = (int) round(microtime(true) * 1000);
    $day = 86400000;
    $added = ['blocks' => 0, 'rooms' => 0, 'users' => 0, 'vendors' => 0, 'assets' => 0, 'movements' => 0];

    $exists = function (string $table, string $id) use ($db): bool {
        $st = $db->prepare("SELECT 1 FROM `$table` WHERE id = ?");
        $st->execute([$id]);
        return (bool) $st->fetchColumn();
    };
    $insert = function (string $table, array $row) use ($db, &$added, $exists): void {
        if ($exists($table, $row['id'])) {
            return;
        }
        $cols = array_keys($row);
        $sql = "INSERT INTO `$table` (" . implode(',', array_map(fn($c) => "`$c`", $cols)) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
        $db->prepare($sql)->execute(array_values($row));
        $key = $table === 'users' ? 'users' : $table;
        if (isset($added[$key])) {
            $added[$key]++;
        }
    };

    // Blocks A–D: reuse the user's own block when one with that name exists.
    $blocks = [];
    foreach (['A', 'B', 'C', 'D'] as $i => $code) {
        $st = $db->prepare("SELECT id FROM blocks WHERE LOWER(TRIM(name)) = ? LIMIT 1");
        $st->execute(['block ' . strtolower($code)]);
        $id = $st->fetchColumn();
        if (!$id) {
            $id = 'smp-b' . $code;
            $insert('blocks', ['id' => $id, 'name' => 'Block ' . $code, 'code' => $code, 'kind' => 'Residential', 'note' => '', 'sort_order' => $i + 1, 'sample' => 1]);
        }
        $blocks[$code] = $id;
    }

    // Rooms: the same layout in every block, plus a store room.
    $roomLayout = [
        ['101', 'Ground', 'Double', 2], ['102', 'Ground', 'Double', 2], ['103', 'Ground', 'Triple', 3],
        ['201', '1st', 'Double', 2], ['202', '1st', 'Double', 2], ['203', '1st', 'Single', 1],
        ['Common room', 'Ground', 'Common room', null], ['Store room', 'Ground', 'Store rack', null],
    ];
    foreach ($blocks as $code => $bid) {
        $existing = $db->prepare("SELECT LOWER(number) FROM rooms WHERE block_id = ?");
        $existing->execute([$bid]);
        $have = array_flip($existing->fetchAll(PDO::FETCH_COLUMN));
        foreach ($roomLayout as $n => [$num, $floor, $type, $cap]) {
            if (isset($have[strtolower($num)])) {
                continue;
            }
            $insert('rooms', ['id' => "smp-r{$code}{$n}", 'block_id' => $bid, 'number' => $num, 'floor' => $floor, 'type' => $type, 'capacity' => $cap, 'sample' => 1]);
        }
    }

    // Users (no logins) and vendors.
    $users = [
        ['smp-u1', 'Suresh Kumar', 'Warden', 'A', '98450 10001'],
        ['smp-u2', 'Meena Sharma', 'Warden', 'B', '98450 10002'],
        ['smp-u3', 'Kavita Rao', 'Warden', 'C', '98450 10003'],
        ['smp-u4', 'Imran Khan', 'Warden', 'D', '98450 10004'],
        ['smp-u5', 'Ravi Patel', 'Maintenance', '', '98450 10005'],
        ['smp-u6', 'Anita Joshi', 'Store keeper', '', '98450 10006'],
    ];
    foreach ($users as [$id, $name, $role, $b, $phone]) {
        $insert('users', ['id' => $id, 'name' => $name, 'role' => $role, 'block_id' => $b ? $blocks[$b] : '', 'phone' => $phone, 'email' => null, 'active' => 'Yes', 'sample' => 1]);
    }
    $vendors = [
        ['smp-v1', 'Sharma Electricals', 'Repair contractor', '98450 20001', 'Mr. Sharma', 'Fans, geysers, AC service'],
        ['smp-v2', 'Comfort Furnishers', 'Supplier', '98450 20002', 'Anil', 'Beds, mattresses, chairs'],
        ['smp-v3', 'AquaPure Services', 'Supplier & repair', '98450 20003', 'Deepak', 'RO purifiers'],
    ];
    foreach ($vendors as [$id, $name, $kind, $phone, $contact, $notes]) {
        $insert('vendors', ['id' => $id, 'name' => $name, 'kind' => $kind, 'phone' => $phone, 'contact' => $contact, 'notes' => $notes, 'sample' => 1]);
    }

    // Assets: [id, block, tag-no, item type id, prefix, name, category, room, status, condition, supplier, last moved days ago]
    $types = [
        'BED' => ['tBED', 'Single bed, steel frame', 'Furniture'], 'MAT' => ['tMAT', 'Mattress, 4in foam', 'Bedding'],
        'FAN' => ['tFAN', 'Ceiling fan 48in', 'Electrical'], 'TBL' => ['tTBL', 'Study table', 'Furniture'],
        'CHR' => ['tCHR', 'Plastic chair', 'Furniture'], 'GEY' => ['tGEY', 'Water geyser 25L', 'Electrical'],
        'AC' => ['tAC', 'Split AC 1.5 ton', 'Electrical'], 'TV' => ['tTV', 'LED TV', 'Electronics'],
        'WP' => ['tWP', 'Water purifier RO', 'Kitchen & Mess'], 'FRG' => ['tFRG', 'Refrigerator', 'Kitchen & Mess'],
    ];
    $assets = [
        // Block A
        ['A', 'BED', 101, '101', 'In use', 'Good', 'smp-v2', 12], ['A', 'BED', 102, '101', 'In use', 'Good', 'smp-v2', 12],
        ['A', 'MAT', 101, '101', 'In use', 'Fair', 'smp-v2', 12], ['A', 'FAN', 101, '102', 'Under repair', 'Damaged', '', 3],
        ['A', 'TBL', 101, '103', 'In use', 'Good', '', null], ['A', 'TV', 101, 'Common room', 'In use', 'Good', '', 18],
        ['A', 'CHR', 101, 'Store room', 'In store', 'Good', 'smp-v2', 6],
        // Block B
        ['B', 'BED', 101, '201', 'In use', 'Good', 'smp-v2', 9], ['B', 'MAT', 101, '201', 'In use', 'Good', 'smp-v2', 9],
        ['B', 'CHR', 102, '202', 'Missing', 'Good', 'smp-v2', 5], ['B', 'GEY', 101, '203', 'In use', 'Fair', '', null],
        ['B', 'WP', 101, 'Common room', 'Under repair', 'Poor', 'smp-v3', 2], ['B', 'MAT', 102, 'Store room', 'In store', 'Good', 'smp-v2', 8],
        // Block C
        ['C', 'BED', 101, '102', 'In use', 'Good', 'smp-v2', 15], ['C', 'FAN', 101, '102', 'In use', 'Good', '', null],
        ['C', 'AC', 101, '203', 'In use', 'Good', '', 20], ['C', 'TBL', 101, '201', 'In use', 'Fair', '', 4],
        ['C', 'FRG', 101, 'Common room', 'In use', 'Good', '', null], ['C', 'BED', 102, 'Store room', 'Disposed', 'Damaged', 'smp-v2', 11],
        // Block D
        ['D', 'BED', 101, '101', 'In use', 'Good', 'smp-v2', 1], ['D', 'MAT', 101, '101', 'In use', 'Good', 'smp-v2', 1],
        ['D', 'FAN', 101, '103', 'In use', 'Good', '', null], ['D', 'CHR', 101, '202', 'In use', 'Fair', 'smp-v2', 7],
        ['D', 'TV', 101, 'Common room', 'In use', 'Good', '', null], ['D', 'GEY', 101, 'Store room', 'In store', 'Fair', '', 10],
    ];
    $assetIds = [];
    foreach ($assets as [$b, $pre, $no, $room, $status, $cond, $supplier, $daysAgo]) {
        $tag = "{$b}-{$pre}-{$no}";
        $chk = $db->prepare("SELECT id FROM assets WHERE tag = ?");
        $chk->execute([$tag]);
        if ($found = $chk->fetchColumn()) {
            $assetIds[$tag] = $found;
            continue;
        }
        [$typeId, $name, $cat] = $types[$pre];
        $id = 'smp-a-' . strtolower(str_replace('-', '', $tag));
        $assetIds[$tag] = $id;
        $insert('assets', [
            'id' => $id, 'tag' => $tag, 'name' => $name, 'item_type_id' => $typeId, 'category' => $cat,
            'block_id' => $blocks[$b], 'room' => $room, 'status' => $status, 'cond' => $cond, 'supplier_id' => $supplier,
            'notes' => '', 'created_at' => $now - 30 * $day, 'updated_at' => $now,
            'last_moved_at' => $daysAgo === null ? null : $now - $daysAgo * $day - 3600000 * 3, 'sample' => 1,
        ]);
    }

    // Movements matching the assets' current places: [tag, days ago, from block, from room, to block, to room, reason, status after, handled by, vendor, notes]
    $moves = [
        ['C-AC-101', 20, 'C', 'Store room', 'C', '203', 'Issued from store', 'In use', 'Anita Joshi', '', 'New AC for warden office'],
        ['A-TV-101', 18, 'B', 'Common room', 'A', 'Common room', 'Shifted between blocks', 'In use', 'Ravi Patel', '', ''],
        ['C-BED-101', 15, 'C', 'Store room', 'C', '102', 'Room allotment', 'In use', 'Kavita Rao', '', 'New admission'],
        ['A-BED-101', 12, 'A', 'Store room', 'A', '101', 'Room allotment', 'In use', 'Suresh Kumar', '', 'New admission'],
        ['A-BED-102', 12, 'A', 'Store room', 'A', '101', 'Room allotment', 'In use', 'Suresh Kumar', '', ''],
        ['A-MAT-101', 12, 'A', 'Store room', 'A', '101', 'Room allotment', 'In use', 'Suresh Kumar', '', ''],
        ['C-BED-102', 11, 'C', '201', 'C', 'Store room', 'Disposal', 'Disposed', 'Kavita Rao', '', 'Frame broken'],
        ['D-GEY-101', 10, 'D', '203', 'D', 'Store room', 'Moved to store', 'In store', 'Imran Khan', '', 'Room vacated'],
        ['B-BED-101', 9, 'A', '203', 'B', '201', 'Shifted between blocks', 'In use', 'Meena Sharma', '', ''],
        ['B-MAT-101', 9, 'A', '203', 'B', '201', 'Shifted between blocks', 'In use', 'Meena Sharma', '', ''],
        ['B-MAT-102', 8, 'B', '102', 'B', 'Store room', 'Moved to store', 'In store', 'Anita Joshi', '', ''],
        ['D-CHR-101', 7, 'D', 'Store room', 'D', '202', 'Issued from store', 'In use', 'Imran Khan', '', ''],
        ['A-CHR-101', 6, 'A', '201', 'A', 'Store room', 'Moved to store', 'In store', 'Suresh Kumar', '', 'Extra chair'],
        ['B-CHR-102', 5, 'B', '202', 'B', '202', 'Reported missing', 'Missing', 'Meena Sharma', '', 'Not found at monthly audit'],
        ['C-TBL-101', 4, 'C', '103', 'C', '201', 'Room allotment', 'In use', 'Kavita Rao', '', ''],
        ['A-FAN-101', 3, 'A', '102', 'A', '102', 'Sent for repair', 'Under repair', 'Ravi Patel', 'Sharma Electricals', 'Regulator burnt'],
        ['B-WP-101', 2, 'B', 'Common room', 'B', 'Common room', 'Sent for repair', 'Under repair', 'Ravi Patel', 'AquaPure Services', 'Membrane change'],
        ['D-BED-101', 1, 'D', 'Store room', 'D', '101', 'Room allotment', 'In use', 'Imran Khan', '', 'New admission'],
        ['D-MAT-101', 1, 'D', 'Store room', 'D', '101', 'Room allotment', 'In use', 'Imran Khan', '', ''],
    ];
    foreach ($moves as $n => [$tag, $daysAgo, $fb, $fr, $tb, $tr, $reason, $statusAfter, $by, $vendor, $notes]) {
        $chk = $db->prepare("SELECT name FROM assets WHERE tag = ?");
        $chk->execute([$tag]);
        $name = (string) $chk->fetchColumn();
        $at = $now - $daysAgo * $day - 3600000 * 3;
        $insert('movements', [
            'id' => sprintf('smp-m%02d', $n + 1), 'asset_id' => $assetIds[$tag] ?? '', 'asset_tag' => $tag, 'asset_name' => $name,
            'from_block_id' => $blocks[$fb], 'from_room' => $fr, 'to_block_id' => $blocks[$tb], 'to_room' => $tr,
            'reason' => $reason, 'status_after' => $statusAfter, 'handled_by' => $by, 'vendor' => $vendor, 'notes' => $notes,
            'at' => $at, 'logged_at' => $at, 'logged_by' => '', 'sample' => 1,
        ]);
    }

    return $added;
}
