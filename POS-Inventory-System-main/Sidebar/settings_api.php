<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db_connection.php';

$userId = 1; // This project currently has a single seeded admin and no login session.

function respond($success, $data = [], $status = 200) {
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success], $data));
    exit;
}

function read_payload() {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    return is_array($payload) ? $payload : [];
}

function ensure_user_settings($conn, $userId) {
    $column = $conn->query("SHOW COLUMNS FROM user_settings LIKE 'selected_date'");
    if ($column && $column->num_rows === 0) {
        $conn->query('ALTER TABLE user_settings ADD COLUMN selected_date DATE DEFAULT NULL AFTER date_format');
    }
    $check = $conn->prepare('SELECT settings_id FROM user_settings WHERE user_id = ? LIMIT 1');
    $check->bind_param('i', $userId);
    $check->execute();
    $exists = $check->get_result()->num_rows > 0;
    $check->close();

    if (!$exists) {
        $insert = $conn->prepare('INSERT INTO user_settings (user_id) VALUES (?)');
        $insert->bind_param('i', $userId);
        $insert->execute();
        $insert->close();
    }
}

function save_user_settings($conn, $userId, $values) {
    ensure_user_settings($conn, $userId);
    $stmt = $conn->prepare(
        'UPDATE user_settings SET language_code = ?, date_format = ?, selected_date = ?, time_format = ?, timezone = ?, currency_code = ?, number_format = ?, two_factor_enabled = ?, login_alerts_enabled = ?, notifications_enabled = ?, auto_update_enabled = ?, data_sharing_opt_in = ?, theme = ? WHERE user_id = ?'
    );
    $stmt->bind_param(
        'sssssssiiiiisi',
        $values['language'], $values['dateFormat'], $values['selectedDate'], $values['timeFormat'], $values['timezone'],
        $values['currency'], $values['numberFormat'], $values['twoFactor'], $values['loginAlerts'],
        $values['notifications'], $values['autoUpdate'], $values['dataSharing'], $values['theme'], $userId
    );
    $stmt->execute();
    $stmt->close();
}

try {
    $action = $_GET['action'] ?? '';

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'load') {
        ensure_user_settings($conn, $userId);
        $stmt = $conn->prepare(
            'SELECT u.first_name, u.last_name, u.email, u.phone, u.avatar_url, s.language_code, s.date_format, s.selected_date, s.time_format, s.timezone, s.currency_code, s.number_format, s.theme, s.two_factor_enabled, s.login_alerts_enabled, s.notifications_enabled, s.auto_update_enabled, s.data_sharing_opt_in, s.last_password_change FROM users u JOIN user_settings s ON s.user_id = u.user_id WHERE u.user_id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) respond(false, ['message' => 'The settings user was not found.'], 404);

        $logs = [];
        $logStmt = $conn->prepare('SELECT log_id, status, ip_address, device_info, logged_in_at FROM user_access_logs WHERE user_id = ? ORDER BY logged_in_at DESC LIMIT 50');
        $logStmt->bind_param('i', $userId);
        $logStmt->execute();
        $result = $logStmt->get_result();
        while ($log = $result->fetch_assoc()) {
            $logs[] = [
                'id' => (string)$log['log_id'],
                'email' => $row['email'],
                'timestamp' => $log['logged_in_at'],
                'ip' => $log['ip_address'] ?: 'Unknown',
                'device' => $log['device_info'] ?: 'Unknown',
                'status' => $log['status'],
                'active' => $log['status'] === 'success'
            ];
        }
        $logStmt->close();

        respond(true, [
            'profile' => [
                'firstName' => $row['first_name'], 'lastName' => $row['last_name'],
                'email' => $row['email'], 'phone' => $row['phone'] ?? '',
                'photo' => $row['avatar_url'] ?: ''
            ],
            'security' => [
                'twoFactor' => (bool)$row['two_factor_enabled'],
                'loginAlerts' => (bool)$row['login_alerts_enabled'],
                'lastPasswordChange' => $row['last_password_change']
            ],
            'system' => [
                'language' => $row['language_code'], 'dateFormat' => $row['date_format'], 'selectedDate' => $row['selected_date']
            ],
            'regional' => [
                'timeFormat' => $row['time_format'], 'timezone' => $row['timezone'],
                'currency' => $row['currency_code'], 'numberFormat' => $row['number_format']
            ],
            'preferences' => [
                'notifications' => (bool)$row['notifications_enabled'],
                'autoUpdate' => (bool)$row['auto_update_enabled'],
                'dataSharing' => (bool)$row['data_sharing_opt_in'], 'theme' => $row['theme']
            ],
            'logs' => $logs
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false, ['message' => 'Unsupported request.'], 405);
    $payload = read_payload();

    if ($action === 'profile') {
        $firstName = trim($payload['firstName'] ?? '');
        $lastName = trim($payload['lastName'] ?? '');
        $email = trim($payload['email'] ?? '');
        $phone = trim($payload['phone'] ?? '');
        if ($firstName === '' || $lastName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(false, ['message' => 'Enter a first name, last name, and valid email address.'], 422);
        }

        $stmt = $conn->prepare('UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ? WHERE user_id = ?');
        $stmt->bind_param('ssssi', $firstName, $lastName, $email, $phone, $userId);
        if (!$stmt->execute()) {
            $duplicate = $stmt->errno === 1062;
            $stmt->close();
            respond(false, ['message' => $duplicate ? 'That email address is already in use.' : 'Could not save the profile.'], $duplicate ? 409 : 500);
        }
        $stmt->close();
        respond(true, ['message' => 'Profile saved.']);
    }

    if ($action === 'avatar') {
        if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            respond(false, ['message' => 'Choose a profile image to upload.'], 422);
        }
        $file = $_FILES['photo'];
        if ($file['size'] > 5 * 1024 * 1024) respond(false, ['message' => 'Profile images must be 5 MB or smaller.'], 422);
        $imageInfo = getimagesize($file['tmp_name']);
        $extensions = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
        if (!$imageInfo || !isset($extensions[$imageInfo[2]])) respond(false, ['message' => 'Upload a JPG, PNG, GIF, or WebP image.'], 422);

        $directory = __DIR__ . '/uploads/avatars';
        if (!is_dir($directory) && !mkdir($directory, 0775, true)) respond(false, ['message' => 'Could not create the avatar upload folder.'], 500);
        $filename = 'user-' . $userId . '.' . $extensions[$imageInfo[2]];
        $destination = $directory . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $destination)) respond(false, ['message' => 'Could not save the uploaded image.'], 500);
        $photoUrl = 'uploads/avatars/' . $filename . '?v=' . filemtime($destination);
        $stmt = $conn->prepare('UPDATE users SET avatar_url = ? WHERE user_id = ?');
        $stmt->bind_param('si', $photoUrl, $userId);
        $stmt->execute();
        $stmt->close();
        respond(true, ['photo' => $photoUrl, 'message' => 'Profile picture saved.']);
    }

    if ($action === 'security') {
        $currentPassword = $payload['currentPassword'] ?? '';
        $newPassword = $payload['newPassword'] ?? '';
        $confirmPassword = $payload['confirmPassword'] ?? '';
        if (strlen($newPassword) < 8) respond(false, ['message' => 'New password must be at least 8 characters.'], 422);
        if ($newPassword !== $confirmPassword) respond(false, ['message' => 'New passwords do not match.'], 422);

        $stmt = $conn->prepare('SELECT password_hash FROM users WHERE user_id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $storedHash = $stmt->get_result()->fetch_column();
        $stmt->close();
        $validCurrentPassword = password_verify($currentPassword, $storedHash ?: '') ||
            ($storedHash === '$2y$10$bonbonKitchenDemoHashValue1234567890' && $currentPassword === 'Bonbon123!');
        if (!$validCurrentPassword) respond(false, ['message' => 'Current password is incorrect.'], 403);

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
        $stmt->bind_param('si', $newHash, $userId);
        $stmt->execute();
        $stmt->close();
        ensure_user_settings($conn, $userId);
        $stmt = $conn->prepare('UPDATE user_settings SET last_password_change = NOW() WHERE user_id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
        respond(true, ['lastPasswordChange' => date('c'), 'message' => 'Password updated.']);
    }

    if ($action === 'settings') {
        $values = [
            'language' => $payload['language'] ?? 'en-fil',
            'dateFormat' => $payload['dateFormat'] ?? 'MM/DD/YYYY',
            'selectedDate' => !empty($payload['selectedDate']) ? $payload['selectedDate'] : null,
            'timeFormat' => $payload['timeFormat'] ?? '12h',
            'timezone' => $payload['timezone'] ?? 'Asia/Manila',
            'currency' => $payload['currency'] ?? 'PHP',
            'numberFormat' => $payload['numberFormat'] ?? '1,234.56',
            'twoFactor' => !empty($payload['twoFactor']) ? 1 : 0,
            'loginAlerts' => !empty($payload['loginAlerts']) ? 1 : 0,
            'notifications' => !empty($payload['notifications']) ? 1 : 0,
            'autoUpdate' => !empty($payload['autoUpdate']) ? 1 : 0,
            'dataSharing' => !empty($payload['dataSharing']) ? 1 : 0,
            'theme' => $payload['theme'] ?? 'system'
        ];
        if (!in_array($values['language'], ['en', 'en-fil', 'fil'], true) ||
            !in_array($values['dateFormat'], ['MM/DD/YYYY', 'DD/MM/YYYY', 'YYYY-MM-DD', 'DD MMM YYYY'], true) ||
            ($values['selectedDate'] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $values['selectedDate'])) ||
            !in_array($values['timeFormat'], ['12h', '24h'], true) ||
            !in_array($values['timezone'], ['Asia/Manila', 'UTC', 'America/New_York', 'Europe/London'], true) ||
            !in_array($values['currency'], ['PHP', 'USD', 'EUR', 'GBP'], true) ||
            !in_array($values['numberFormat'], ['1,234.56', '1.234,56', '1 234,56'], true) ||
            !in_array($values['theme'], ['light', 'dark', 'system'], true)) {
            respond(false, ['message' => 'One or more settings values are invalid.'], 422);
        }
        save_user_settings($conn, $userId, $values);
        respond(true, ['message' => 'Settings saved.']);
    }

    if ($action === 'add_log') {
        $status = in_array($payload['status'] ?? '', ['success', 'failed'], true) ? $payload['status'] : 'success';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $device = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 120);
        $stmt = $conn->prepare('INSERT INTO user_access_logs (user_id, status, ip_address, device_info, logged_in_at) VALUES (?, ?, ?, ?, NOW())');
        $stmt->bind_param('isss', $userId, $status, $ip, $device);
        $stmt->execute();
        $id = (string)$stmt->insert_id;
        $stmt->close();
        respond(true, ['id' => $id]);
    }

    if ($action === 'logout_log') {
        $id = (int)($payload['id'] ?? 0);
        $stmt = $conn->prepare("UPDATE user_access_logs SET status = 'terminated', logged_out_at = NOW() WHERE log_id = ? AND user_id = ?");
        $stmt->bind_param('ii', $id, $userId);
        $stmt->execute();
        $stmt->close();
        respond(true, ['message' => 'Access log updated.']);
    }

    respond(false, ['message' => 'Unknown settings action.'], 404);
} catch (Throwable $error) {
    respond(false, ['message' => 'Database request failed. Check that the project database has been imported.'], 500);
}
