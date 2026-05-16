<?php
/**
 * Export Incidents to CSV
 * Disaster Response & Resource Coordination System
 */

session_start();
require_once __DIR__ . '/../../includes/config/config.php';
require_once __DIR__ . '/../../includes/functions/auth.php';

// Only admins and responders can export
role_guard(['admin', 'responder']);

// Get filter parameters
$status_filter = $_GET['status'] ?? '';
$severity_filter = $_GET['severity'] ?? '';
$type_filter = $_GET['type'] ?? '';
$search = $_GET['search'] ?? '';

// Build WHERE clause
$where_conditions = [];
$params = [];

if (!empty($status_filter)) {
    $where_conditions[] = "i.status = ?";
    $params[] = $status_filter;
}

if (!empty($severity_filter)) {
    $where_conditions[] = "i.severity = ?";
    $params[] = $severity_filter;
}

if (!empty($type_filter)) {
    $where_conditions[] = "i.incident_type = ?";
    $params[] = $type_filter;
}

if (!empty($search)) {
    $where_conditions[] = "(i.location_name LIKE ? OR i.description LIKE ? OR u.full_name LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Fetch all incidents matching filters
$sql = "
    SELECT i.id, i.incident_type, i.severity, i.location_name, i.description, 
           i.latitude, i.longitude, i.status, i.reported_at, i.updated_at,
           u.full_name as reporter_name, u.phone as reporter_phone, u.email as reporter_email,
           r.full_name as responder_name
    FROM incidents i
    LEFT JOIN users u ON i.reporter_id = u.id
    LEFT JOIN users r ON i.assigned_to = r.id
    $where_clause
    ORDER BY i.reported_at DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$incidents = $stmt->fetchAll();

// Set CSV headers
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="incidents_export_' . date('Y-m-d_H-i-s') . '.csv"');

// Create output stream
$output = fopen('php://output', 'w');

// Add CSV headers
fputcsv($output, [
    'Incident ID',
    'Type',
    'Severity',
    'Location',
    'Description',
    'Latitude',
    'Longitude',
    'Status',
    'Reported At',
    'Updated At',
    'Reporter Name',
    'Reporter Phone',
    'Reporter Email',
    'Responder Name'
]);

// Add data rows
$severity_labels = ['', 'Low', 'Medium', 'High', 'Critical'];
foreach ($incidents as $incident) {
    fputcsv($output, [
        'INC-' . str_pad($incident['id'], 5, '0', STR_PAD_LEFT),
        ucfirst(str_replace('_', ' ', $incident['incident_type'])),
        $severity_labels[$incident['severity']] ?? 'Unknown',
        $incident['location_name'] ?? '',
        strip_tags($incident['description'] ?? ''),
        $incident['latitude'],
        $incident['longitude'],
        ucfirst($incident['status']),
        date('Y-m-d H:i:s', strtotime($incident['reported_at'])),
        date('Y-m-d H:i:s', strtotime($incident['updated_at'] ?? $incident['reported_at'])),
        $incident['reporter_name'] ?? 'Anonymous',
        $incident['reporter_phone'] ?? '',
        $incident['reporter_email'] ?? '',
        $incident['responder_name'] ?? ''
    ]);
}

fclose($output);
exit();
?>