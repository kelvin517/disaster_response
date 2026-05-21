<?php
/**
 * AJAX Unread Count
 * Disaster Response & Resource Coordination System
 * Author: Kevin Kiplangat | INTE/MK/1299/09/23
 * 
 * Returns unread message count for real-time notifications
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['unread' => 0, 'error' => 'Not logged in']);
    exit;
}

$user_id = $_SESSION['user_id'];

// Get unread private messages
$stmt = $pdo->prepare("
    SELECT COUNT(*) as count 
    FROM messages 
    WHERE receiver_id = ? AND is_read = 0 AND deleted_by_receiver = 0
");
$stmt->execute([$user_id]);
$unread_private = $stmt->fetch()['count'];

// Get unread group messages
$stmt = $pdo->prepare("
    SELECT COUNT(*) as count 
    FROM groups g
    JOIN group_messages gm ON g.id = gm.group_id
    JOIN group_members gm_member ON g.id = gm_member.group_id
    LEFT JOIN user_groups ug ON ug.group_id = g.id AND ug.user_id = ?
    WHERE gm_member.user_id = ? 
        AND (ug.last_read IS NULL OR gm.created_at > ug.last_read)
");
$stmt->execute([$user_id, $user_id]);
$unread_group = $stmt->fetch()['count'];

echo json_encode([
    'unread' => $unread_private + $unread_group,
    'private' => $unread_private,
    'group' => $unread_group,
    'timestamp' => date('c')
]);