<?php
function exportToCSV($data, $filename) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="'.$filename.'.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, array_keys($data[0]));
    foreach($data as $row) fputcsv($output, $row);
    fclose($output);
    exit();
}
?>