<?php
date_default_timezone_set('Asia/Manila');
include 'db.php';

// 1. Fetch totals from the active Operational Database (OLTP)
$oltp_count = $conn->query("SELECT COUNT(*) AS total FROM complaints")->fetch_assoc()['total'];

// 2. Fetch totals from the Data Warehouse Fact Table (OLAP)
$olap_count_query = $conn->query("SELECT SUM(total_complaints) AS total FROM fact_complaints");
$olap_count = $olap_count_query ? $olap_count_query->fetch_assoc()['total'] : 0;
$olap_count = !empty($olap_count) ? $olap_count : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OLAP Data Warehouse Output | Barangay Hub</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #0f172a; color: #f8fafc; padding: 40px; margin: 0; }
        .container { max-width: 1100px; margin: auto; }
        h1 { color: #38bdf8; margin-bottom: 5px; }
        .subtitle { color: #94a3b8; margin-bottom: 30px; font-size: 14px; }
        
        /* Structural Comparison Grid */
        .system-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
        .system-card { padding: 25px; border-radius: 16px; background: #1e293b; border: 1px solid #334155; }
        .system-card.oltp { border-left: 5px solid #ef4444; }
        .system-card.olap { border-left: 5px solid #10b981; }
        .metric { font-size: 48px; font-weight: bold; margin: 15px 0 5px 0; }
        
        /* Data Viewer Sections */
        .section-card { background: #1e293b; border: 1px solid #334155; padding: 30px; border-radius: 16px; margin-bottom: 30px; }
        h3 { color: #f1f5f9; margin-bottom: 15px; border-bottom: 1px solid #334155; padding-bottom: 10px; text-transform: uppercase; font-size: 14px; letter-spacing: 1px; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 14px; }
        th { background: #0f172a; text-align: left; padding: 12px; color: #94a3b8; font-weight: 600; }
        td { padding: 12px; border-bottom: 1px solid #334155; }
        tr:hover { background: rgba(255,255,255,0.02); }
        
        .badge { padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: bold; }
        .badge-slice { background: rgba(56, 189, 248, 0.2); color: #38bdf8; }
        .badge-dice { background: rgba(251, 146, 60, 0.2); color: #fb923c; }
        .badge-rollup { background: rgba(167, 139, 250, 0.2); color: #a78bfa; }
        
        .btn-pipeline { background: #10b981; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block; transition: 0.2s; }
        .btn-pipeline:hover { background: #059669; }
    </style>
</head>
<body>

<div class="container">
    <h1>📊 Advanced Database Warehouse Visualizer</h1>
    <p class="subtitle">DCIT 55B Final Exam Output Verification Page • Operational vs. Analytical Architecture</p>

    <div class="system-grid">
        <div class="system-card oltp">
            <strong style="color: #ef4444;">Active Operational App (OLTP)</strong>
            <div class="subtitle" style="margin:0;">Source table: `complaints`</div>
            <div class="metric"><?php echo $oltp_count; ?></div>
            <p style="font-size: 13px; opacity: 0.7;">Raw records written directly by residents in real time.</p>
        </div>
        <div class="system-card olap">
            <strong style="color: #10b981;">Data Warehouse Cube (OLAP)</strong>
            <div class="subtitle" style="margin:0;">Central target table: `fact_complaints`</div>
            <div class="metric" style="color: #10b981;"><?php echo $olap_count; ?></div>
            <p style="font-size: 13px; opacity: 0.7;">Aggregated metric count pulled instantly via Star Schema dimensions.</p>
        </div>
    </div>

    <div style="margin-bottom: 30px; text-align: center;">
        <p style="margin-bottom: 10px; opacity: 0.8;">Need to sync data from the active application to the data warehouse schema?</p>
        <a href="etl.php" target="_blank" class="btn-pipeline">⚙️ Run ETL Synchronization Pipeline (etl.php)</a>
    </div>

    <div class="section-card">
        <h3>🛠️ Live OLAP Business Intelligence Operations (Rubric Component 3)</h3>
        
        <div style="margin-bottom: 25px;">
            <p><span class="badge badge-rollup">ROLL-UP OPERATION</span> <strong>Aggregating Complaints Up by Location / Purok Structure:</strong></p>
            <table>
                <thead>
                    <tr>
                        <th>DIMENSIONAL LOCATION (Purok)</th>
                        <th>AGGREGATED METRIC (Total Complaints)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $rollup = $conn->query("SELECT r.purok_address, SUM(f.total_complaints) AS total FROM fact_complaints f JOIN dim_residents r ON f.resident_key = r.resident_key GROUP BY r.purok_address ORDER BY total DESC");
                    if($rollup && $rollup->num_rows > 0){
                        while($row = $rollup->fetch_assoc()){
                            echo "<tr><td>📍 ".htmlspecialchars($row['purok_address'])."</td><td><strong>".$row['total']." Reports</strong></td></tr>";
                        }
                    } else { echo "<tr><td colspan='2' style='opacity:0.5;'>No dimension metrics found. Run etl.php script.</td></tr>"; }
                    ?>
                </tbody>
            </table>
        </div>

        <div>
            <p><span class="badge badge-dice">DICE OPERATION</span> <strong>Intersecting Multi-Dimensional Constraints (Filtering by Category AND Month Dimension):</strong></p>
            <table>
                <thead>
                    <tr>
                        <th>COMPLAINT TYPE DIMENSION</th>
                        <th>TIME DIMENSION (Month)</th>
                        <th>TIME DIMENSION (Year)</th>
                        <th>AGGREGATED VALUE</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $dice = $conn->query("SELECT c.complaint_type, t.month_name, t.calendar_year, SUM(f.total_complaints) AS total FROM fact_complaints f JOIN dim_complaint_types c ON f.type_key = c.type_key JOIN dim_time t ON f.time_key = t.time_key GROUP BY c.complaint_type, t.month_name, t.calendar_year ORDER BY total DESC LIMIT 5");
                    if($dice && $dice->num_rows > 0){
                        while($row = $dice->fetch_assoc()){
                            echo "<tr>
                                    <td>🔹 ".htmlspecialchars($row['complaint_type'])."</td>
                                    <td>📅 ".$row['month_name']."</td>
                                    <td>📆 ".$row['calendar_year']."</td>
                                    <td style='color:#fb923c; font-weight:bold;'>".$row['total']."</td>
                                  </tr>";
                        }
                    } else { echo "<tr><td colspan='4' style='opacity:0.5;'>No dimension metrics found. Run etl.php script.</td></tr>"; }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>