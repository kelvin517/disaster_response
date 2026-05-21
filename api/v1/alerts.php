<?php
/**
 * API v1 - Alerts
 * Disaster Response & Resource Coordination System
 * 
 * Endpoints:
 *   GET  /api/v1/alerts               - Get active alerts
 *   GET  /api/v1/alerts?history=1     - Get alert history
 *   GET  /api/v1/alerts?priority=urgent - Filter by priority
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../includes/config/config.php';

function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit();
}

// GET request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $history = isset($_GET['history']);
    $priority = $_GET['priority'] ?? null;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    
    try {
        if ($history) {
            // Get alert history
            $sql = "
                SELECT a.*, u.full_name as created_by_name
                FROM alerts a
                JOIN users u ON a.created_by = u.id
                WHERE 1=1
            ";
            $params = [];
            
            if ($priority) {
                $sql .= " AND a.priority = ?";
                $params[] = $priority;
            }
            
            $sql .= " ORDER BY a.created_at DESC LIMIT ?";
            $params[] = $limit;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            sendResponse($alerts);
        } else {
            // Get active alerts (not expired)
            $sql = "
                SELECT id, priority, title, message, target_area, created_at, expires_at
                FROM alerts
                WHERE (expires_at IS NULL OR expires_at > NOW())
                ORDER BY 
                    CASE priority 
                        WHEN 'emergency' THEN 1 
                        WHEN 'urgent' THEN 2 
                        WHEN 'warning' THEN 3 
                        ELSE 4 
                    END ASC,
                    created_at DESC
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $priority_config = [
                'info' => ['color' => '#3b82f6', 'icon' => 'info-circle', 'level' => 1],
                'warning' => ['color' => '#f59e0b', 'icon' => 'exclamation-triangle', 'level' => 2],
                'urgent' => ['color' => '#ef4444', 'icon' => 'exclamation-octagon', 'level' => 3],
                'emergency' => ['color' => '#dc2626', 'icon' => 'megaphone-fill', 'level' => 4]
            ];
            
            foreach ($alerts as &$alert) {
                $alert['config'] = $priority_config[$alert['priority']] ?? $priority_config['info'];
            }
            
            sendResponse([
                'active_alerts' => $alerts,
                'total' => count($alerts),
                'timestamp' => date('c')
            ]);
        }
    } catch (PDOException $e) {
        sendResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}
?>