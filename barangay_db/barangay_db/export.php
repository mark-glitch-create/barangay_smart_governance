<?php
session_start();
include 'db.php';

// Safety Check: Ensure only logged-in Admins can download data
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Clean out any accidental output buffers to prevent file corruption
if (ob_get_contents()) ob_end_clean();

// Set browser headers to force download as a true CSV file
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=Barangay_Incident_Report_' . date('Y-m-d') . '.csv');

// Open the output write stream
$output = fopen('php://output', 'w');

// Write the spreadsheet headers (Column Titles)
fputcsv($output, array('Incident ID', 'Resident Name', 'Complaint Category', 'Location / Purok', 'Status', 'Date Submitted'));

// Fetch the raw transactional records from your OLTP table
$query = "SELECT id, resident_name, complaint_type, location, status, created_at FROM complaints ORDER BY id DESC";
$result = $conn->query($query);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        
        // =========================================================================
        // 🗓️ THE CSV FIX: STRING ENCAPSULATION LAYER
        // =========================================================================
        $timestamp = strtotime($row['created_at']);
        
        // Format layout matching your design: Month Day, Year Hour:Minute AM/PM
        $base_date = date('F d, Y g:i A', $timestamp);
        
        // Adding a leading tab/space character breaks Excel's column restriction.
        // It forces Excel to render the text instantly without collapsing it into ###!
        $formatted_date = "\t" . $base_date; 
        // =========================================================================

        fputcsv($output, array(
            $row['id'],
            $row['resident_name'],
            $row['complaint_type'],
            $row['location'],
            ucfirst($row['status']),
            $formatted_date // Clean date layout string that stays completely visible
        ));
    }
}

fclose($output);
exit();
?>