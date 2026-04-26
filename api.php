<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Database Credentials - UPDATE THESE
$host = 'localhost';
$db   = 'indujz9y_attend';
$user = 'indujz9y_harit';
$pass = 'April@042026';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die(json_encode(['error' => 'Database connection failed']));
}

$action = $_GET['action'] ?? '';
$data = json_decode(file_get_contents("php://input"), true);

switch ($action) {
    case 'login':
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$data['email']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($data['password'], $user['password'])) {
            // Bypass device check for admin
            if ($user['email'] !== 'admin@indujagroup.com' && $user['deviceId'] !== $data['deviceId'] && $data['deviceId'] !== 'ADMIN_BYPASS_DEVICE') {
                echo json_encode(['error' => 'Unauthorized Device. Please login from your registered device.']);
            } else {
                unset($user['password']); // Don't send password back
                echo json_encode(['user' => $user]);
            }
        } else {
            echo json_encode(['error' => 'Invalid email or password.']);
        }
        break;

    case 'register':
        $role = strpos($data['email'], 'admin') !== false ? 'admin' : 'employee';
        $status = $role === 'admin' ? 'approved' : 'pending';
        $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);

        try {
            $stmt = $pdo->prepare("INSERT INTO users (fullName, email, password, role, status, deviceId) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$data['fullName'], $data['email'], $hashedPassword, $role, $status, $data['deviceId']]);
            $userId = $pdo->lastInsertId();
            
            $stmt = $pdo->prepare("SELECT id, fullName, email, role, status, deviceId FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            echo json_encode(['user' => $stmt->fetch(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) {
            echo json_encode(['error' => 'Registration failed. Email might already exist.']);
        }
        break;

    case 'forgot_password_request':
        $stmt = $pdo->prepare("SELECT id, fullName FROM users WHERE email = ?");
        $stmt->execute([$data['email']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $otp = sprintf("%06d", mt_rand(100000, 999999));
            $stmt = $pdo->prepare("UPDATE users SET reset_token = ? WHERE email = ?");
            $stmt->execute([$otp, $data['email']]);
            
            $subject = "CorpTracker - Password Reset Code";
            $message = "Hello " . $user['fullName'] . ",\n\nYour password reset code is: $otp\n\nIf you did not request this, please ignore this email.";
            $headers = "From: noreply@indujagroup.com";
            
            mail($data['email'], $subject, $message, $headers);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['error' => 'Email not found']);
        }
        break;

    case 'forgot_password_verify':
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND reset_token = ? AND reset_token IS NOT NULL");
        $stmt->execute([$data['email'], $data['otp']]);
        if ($stmt->fetch()) {
            $hashedPassword = password_hash($data['newPassword'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL WHERE email = ?");
            $stmt->execute([$hashedPassword, $data['email']]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['error' => 'Invalid or expired OTP']);
        }
        break;

    case 'get_profile':
        $stmt = $pdo->prepare("SELECT id, fullName, email, role, status, deviceId FROM users WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        break;

    case 'get_locations':
        $stmt = $pdo->query("SELECT id, name, latitude, longitude FROM office_locations ORDER BY id ASC");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;

    case 'add_location':
        $stmt = $pdo->prepare("INSERT INTO office_locations (name, latitude, longitude, addedBy) VALUES (?, ?, ?, ?)");
        $stmt->execute([$data['name'], $data['latitude'], $data['longitude'], $data['addedBy']]);
        echo json_encode(['success' => true]);
        break;

    case 'delete_location':
        $stmt = $pdo->prepare("DELETE FROM office_locations WHERE id = ?");
        $stmt->execute([$data['location_id']]);
        echo json_encode(['success' => true]);
        break;

    case 'mark_attendance':
        $status = $data['status'] ?? 'Present';
        $location_name = $data['location_name'] ?? 'Unknown';
        $stmt = $pdo->prepare("INSERT INTO attendance (user_id, userName, latitude, longitude, distance, device, status, location_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$data['user_id'], $data['userName'], $data['latitude'], $data['longitude'], $data['distance'], $data['device'], $status, $location_name]);
        echo json_encode(['success' => true]);
        break;

    case 'checkout':
        $stmt = $pdo->prepare("UPDATE attendance SET checkout_time = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?");
        $stmt->execute([$data['log_id'], $data['user_id']]);
        echo json_encode(['success' => true]);
        break;

    case 'request_full_day':
        $stmt = $pdo->prepare("UPDATE attendance SET full_day_request = 1 WHERE id = ?");
        $stmt->execute([$data['log_id']]);
        echo json_encode(['success' => true]);
        break;

    case 'get_attendance':
        if (isset($_GET['user_id'])) {
            // Increased limit so summary calculations work better
            $stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? ORDER BY timestamp DESC LIMIT 60");
            $stmt->execute([$_GET['user_id']]);
        } else {
            $stmt = $pdo->query("SELECT * FROM attendance ORDER BY timestamp DESC LIMIT 1000");
        }
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;

    case 'get_users':
        $stmt = $pdo->query("SELECT id, fullName, email, role, status, deviceId FROM users ORDER BY createdAt DESC");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;

    case 'update_user_status':
        $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->execute([$data['status'], $data['id']]);
        echo json_encode(['success' => true]);
        break;

    case 'apply_leave':
        $stmt = $pdo->prepare("INSERT INTO leaves (user_id, userName, start_date, end_date, reason, status) VALUES (?, ?, ?, ?, ?, 'pending')");
        $stmt->execute([$data['user_id'], $data['userName'], $data['start_date'], $data['end_date'], $data['reason']]);
        echo json_encode(['success' => true]);
        break;

    case 'get_leaves':
        if (isset($_GET['user_id'])) {
            $stmt = $pdo->prepare("SELECT * FROM leaves WHERE user_id = ? ORDER BY created_at DESC");
            $stmt->execute([$_GET['user_id']]);
        } else {
            $stmt = $pdo->query("SELECT * FROM leaves ORDER BY created_at DESC");
        }
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;

    case 'update_leave_status':
        $stmt = $pdo->prepare("UPDATE leaves SET status = ? WHERE id = ?");
        $stmt->execute([$data['status'], $data['leave_id']]);
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['error' => 'Invalid action']);
}
