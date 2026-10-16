<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$userId = $_SESSION['user_id'];
$q = trim($_GET['q'] ?? '');

$sql = "
    SELECT 
        u.id, u.username, pr.nama, pr.foto, ap.jurusan, ap.profesi
    FROM users u
    LEFT JOIN profiles pr ON pr.user_id = u.id
    LEFT JOIN alumni_profiles ap ON ap.user_id = u.id
    WHERE u.id <> ?
";

$params = [$userId];
$types  = 'i';

if ($q !== '') {
    $sql .= " AND (u.username LIKE ? OR pr.nama LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ss';
}

$sql .= " ORDER BY pr.nama ASC LIMIT 15";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$result = [];
foreach ($users as $u) {
    $result[] = [
        'id'       => (int)$u['id'],
        'username' => $u['username'],
        'nama'     => $u['nama'] ?? $u['username'],
        'avatar'   => !empty($u['foto']) ? baseUrl($u['foto']) : null,
        'info'     => $u['profesi'] ?? $u['jurusan'] ?? 'Alumni'
    ];
}

echo json_encode(['success' => true, 'users' => $result]);
