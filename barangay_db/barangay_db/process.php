<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = "localhost";
$user = "root"; 
$pass = ""; 
$dbname = "barangay_db";


$conn = new mysqli($host, $user, $pass, $dbname);


if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}


if (isset($_POST['submit_report'])) {
    

    $name      = $_POST['complainant_name'];
    $purok     = $_POST['purok'];
    $contact   = $_POST['contact'];
    $date      = $_POST['incident_date'];
    $address   = $_POST['address'];
    $type      = $_POST['complaint_type'];
    $details   = $_POST['details'];
    $anonymous = isset($_POST['anonymous']) ? 1 : 0;
    $status    = "Pending";

  
    $stmt = $conn->prepare("INSERT INTO complaints (complainant_name, contact, address, purok, complaint_type, incident_date, details, anonymous, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    if ($stmt === false) {
        die("SQL Error: " . $conn->error);
    }

  
    $stmt->bind_param("sssssssis", $name, $contact, $address, $purok, $type, $date, $details, $anonymous, $status);

    if ($stmt->execute()) {
        echo "<script>alert('Complaint submitted successfully!'); window.location.href='index.html';</script>";
    } else {
        echo "Error: " . $stmt->error;
    }

    $stmt->close();
} else {
    echo "Form not submitted correctly. Please check your button type and name.";
}

$conn->close();
?>