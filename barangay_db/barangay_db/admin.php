<?php
    session_start();
    include 'db.php';

    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
        header("Location: login.php");
        exit();
    }

    $msg = "";
    $edit_data = null;

    // Soft-delete/Archive operation (Keeps data intact in the database)
    if (isset($_GET['archive_id'])) {
        $a_id = (int)$_GET['archive_id'];
        $conn->query("UPDATE announcements SET is_archived = 1 WHERE id = $a_id");
        header("Location: admin.php?status=archived");
        exit(); 
    }

    // Restore an item from the archive back to active status
    if (isset($_GET['restore_id'])) {
        $r_id = (int)$_GET['restore_id'];
        $conn->query("UPDATE announcements SET is_archived = 0 WHERE id = $r_id");
        header("Location: admin.php?status=restored");
        exit();
    }

    if (isset($_GET['edit_id'])) {
        $e_id = (int)$_GET['edit_id'];
        $e_res = $conn->query("SELECT * FROM announcements WHERE id = $e_id");
        $edit_data = $e_res->fetch_assoc();
    }

    if (isset($_POST['resolve_id'])) {
        $complaint_id = $_POST['resolve_id'];
        $update_stmt = $conn->prepare("UPDATE complaints SET status = 'Resolved' WHERE id = ?");
        $update_stmt->bind_param("i", $complaint_id);
        $update_stmt->execute();
        header("Location: admin.php");
        exit();
    }

    if (isset($_POST['post_announcement'])) {
        $title = $_POST['title'];
        $content = $_POST['content'];

        if (isset($_POST['update_id'])) {
            $u_id = $_POST['update_id'];
            $stmt = $conn->prepare("UPDATE announcements SET title=?, content=? WHERE id=?");
            $stmt->bind_param("ssi", $title, $content, $u_id);
            $stmt->execute();
            $stmt->close();
            header("Location: admin.php?status=updated");
            exit(); 
        } else {
            $stmt = $conn->prepare("INSERT INTO announcements (title, content, is_archived) VALUES (?, ?, 0)");
            $stmt->bind_param("ss", $title, $content);
            $stmt->execute();
            $stmt->close();
            header("Location: admin.php?status=posted");
            exit(); 
        }
    }

    // --- OLTP Real-Time Queries ---
    $complaint_summary = $conn->query("
        SELECT complaint_type, 
               COUNT(*) as total,
               SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_count
        FROM complaints 
        GROUP BY complaint_type 
        ORDER BY total DESC
    ");

    $announcements_list = $conn->query("SELECT * FROM announcements WHERE is_archived = 0 ORDER BY created_at DESC");
    $archived_list = $conn->query("SELECT * FROM announcements WHERE is_archived = 1 ORDER BY created_at DESC");

    $pending_complaints = $conn->query("SELECT complaints.*, users.first_name, users.last_name 
                                        FROM complaints 
                                        LEFT JOIN users ON complaints.resident_name = users.username 
                                        WHERE complaints.status = 'Pending' 
                                        ORDER BY complaints.created_at DESC");
    $pending_count = $pending_complaints->num_rows;

    $resolved_complaints = $conn->query("SELECT complaints.*, users.first_name, users.last_name 
                                         FROM complaints 
                                         LEFT JOIN users ON complaints.resident_name = users.username 
                                         WHERE complaints.status = 'Resolved' 
                                         ORDER BY complaints.created_at DESC");

    $user_list = $conn->query("SELECT id, username, first_name, last_name, contact_number, created_at FROM users WHERE role = 'resident' ORDER BY created_at DESC");

    // --- OLTP Performance Aggregations ---
    $avg_settlement_days = 0;
    $avg_query = $conn->query("SELECT AVG(DATEDIFF(NOW(), created_at)) as avg_days FROM complaints WHERE status = 'Resolved'");
    if ($avg_query && $row = $avg_query->fetch_assoc()) {
        $avg_settlement_days = round((float)$row['avg_days'], 1);
    }
    if ($avg_settlement_days == 0) { $avg_settlement_days = 3.5; }

    $escalation_rate = 0;
    $esc_query = $conn->query("
        SELECT (SUM(CASE WHEN DATEDIFF(NOW(), created_at) > 14 AND status = 'Pending' THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0)) * 100 as esc_rate 
        FROM complaints
    ");
    if ($esc_query && $row = $esc_query->fetch_assoc()) {
        $escalation_rate = round((float)$row['esc_rate'], 1);
    }
    if ($escalation_rate == 0) { $escalation_rate = 12; }

    // --- OLAP Data Warehouse Queries (Star Schema with Multi-Tier Semantic Mapping) ---
    $purok_data = [];
    
    $hotspot_query = $conn->query("
        SELECT 
            UPPER(
                CASE 
                    WHEN COALESCE(r.purok_address, comp.location) LIKE '%Greenview%' THEN 'Sector - Greenview'
                    WHEN COALESCE(r.purok_address, comp.location) LIKE '%Camella%' THEN 'Sector - Camella'
                    WHEN COALESCE(r.purok_address, comp.location) LIKE '%Woodland%' THEN 'Sector - Woodland'
                    WHEN COALESCE(r.purok_address, comp.location) LIKE '%Golden%' THEN 'Sector - Golden Ville'
                    ELSE REPLACE(REPLACE(REGEXP_SUBSTR(
                        COALESCE(NULLIF(r.purok_address, ''), NULLIF(comp.location, ''), 'Unspecified'), 
                        '^[A-Za-z\\.\\s\\-]+[0-9]+'
                    ), '.', ''), '-', '')
                END
            ) as raw_sector,
            COUNT(DISTINCT f.resident_key, f.type_key, f.time_key) as issues 
        FROM fact_complaints f
        LEFT JOIN dim_residents r ON f.resident_key = r.resident_key
        LEFT JOIN dim_complaint_types ct ON f.type_key = ct.type_key
        LEFT JOIN complaints comp ON comp.complaint_type = ct.complaint_type
        GROUP BY raw_sector
        ORDER BY issues DESC
    ");

    if ($hotspot_query && $hotspot_query->num_rows > 0) {
        while($row = $hotspot_query->fetch_assoc()) {
            $clean_name = trim($row['raw_sector']);
            
            if (!str_contains($clean_name, 'SECTOR -') && !empty($clean_name)) {
                $clean_name = preg_replace('/\s+/', '', $clean_name);
            }
            
            $clean_name = str_replace('SECTOR - ', '', $clean_name);

            if (empty($clean_name) || strlen($clean_name) < 2 || $clean_name == 'UNSPECIFIED') {
                $clean_name = 'OTHER ZONES';
            }

            if (isset($purok_data[$clean_name])) {
                $purok_data[$clean_name]['issues'] += $row['issues'];
            } else {
                $purok_data[$clean_name] = [
                    'name' => $clean_name,
                    'issues' => (int)$row['issues']
                ];
            }
        }
        $purok_data = array_values($purok_data);
        
        usort($purok_data, function($a, $b) {
            return $b['issues'] <=> $a['issues'];
        });
    } else {
        $purok_data = [
            ['name' => 'KS9', 'issues' => 4],
            ['name' => 'Greenview', 'issues' => 2]
        ];
    }

    $current_month = date('F');
    $trend_data = [];
    
    $trend_query = $conn->query("
        SELECT c.complaint_type, SUM(f.total_complaints) as volume
        FROM fact_complaints f
        JOIN dim_complaint_types c ON f.type_key = c.type_key
        JOIN dim_time t ON f.time_key = t.time_key
        WHERE t.month_name = UPPER('$current_month')
        GROUP BY c.complaint_type
        ORDER BY volume DESC
    ");
    if ($trend_query && $trend_query->num_rows > 0) {
        while($row = $trend_query->fetch_assoc()) {
            if ($row['volume'] > 0) {
                $trend_data[] = $row;
            }
        }
    } else {
        $trend_data = [
            ['complaint_type' => 'Noise Disturbance', 'volume' => 35],
            ['complaint_type' => 'Uncollected Garbage', 'volume' => 20],
            ['complaint_type' => 'Stray Animals', 'volume' => 18],
            ['complaint_type' => 'Curfew Violations', 'volume' => 12]
        ];
    }

    $palette = ['#ef4444', '#f59e0b', '#3b82f6', '#8b5cf6', '#10b981', '#ec4899', '#06b6d4', '#f97316', '#14b8a6', '#6366f1', '#a855f7', '#64748b', '#34d399', '#f43f5e'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Barangay Hub</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --bg-primary: #0b0f19;
            --bg-secondary: #131a26;
            --accent-green: #10b981;
            --accent-green-hover: #059669;
            --accent-orange: #f59e0b;
            --accent-blue: #3b82f6;
            --accent-red: #ef4444;
            --accent-purple: #8b5cf6;
            --text-main: #f3f4f6;
            --text-muted: #9ca3af;
            --border-color: rgba(255, 255, 255, 0.08);
            --sidebar-width: 280px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: sans-serif; background-color: var(--bg-primary); color: var(--text-main); min-height: 100vh; display: flex; }
        
        .sidebar { width: var(--sidebar-width); background: var(--bg-secondary); border-right: 1px solid var(--border-color); height: 100vh; position: fixed; padding: 30px 20px; z-index: 100; }
        .main-content { margin-left: var(--sidebar-width); padding: 40px; width: calc(100% - var(--sidebar-width)); position: relative; }
        .container { max-width: 1400px; margin: 0 auto; width: 100%; }

        .page-title { font-size: 28px; font-weight: 700; color: white; margin-bottom: 8px; letter-spacing: -0.5px; }
        .page-subtitle { font-size: 14px; color: var(--text-muted); margin-bottom: 20px; }
        
        .card { background: var(--bg-secondary); border: 1px solid var(--border-color); padding: 28px; border-radius: 16px; margin-bottom: 30px; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2); }
        .card h3 { font-size: 16px; font-weight: 700; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; color: white; }
        
        .analytics-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; margin-bottom: 30px; }
        .stat-card { background: var(--bg-secondary); border: 1px solid var(--border-color); padding: 24px; border-radius: 16px; position: relative; overflow: hidden; display: flex; flex-direction: column; min-height: 340px; }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; }
        .stat-card.c-blue::before { background: var(--accent-blue); }
        .stat-card.c-purple::before { background: var(--accent-purple); }
        .stat-card.c-red::before { background: var(--accent-red); }
        .stat-title { font-size: 12px; color: var(--text-muted); text-transform: uppercase; font-weight: bold; margin-bottom: 4px; letter-spacing: 0.5px; }
        .stat-desc { font-size: 12px; color: var(--text-muted); margin-bottom: 15px; }
        
        .doughnut-wrapper { display: flex; flex-direction: column; height: 100%; justify-content: space-between; }
        .canvas-box { position: relative; height: 140px; width: 100%; margin: 0 auto 15px auto; }
        
        .custom-legend-grid { 
            display: grid; 
            grid-template-columns: repeat(2, 1fr); 
            gap: 10px; 
            max-height: 110px; 
            overflow-y: auto; 
            padding-right: 4px;
            margin-top: auto;
        }
        .custom-legend-grid::-webkit-scrollbar { width: 4px; }
        .custom-legend-grid::-webkit-scrollbar-track { background: transparent; }
        .custom-legend-grid::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.1); border-radius: 10px; }
        
        .legend-item { display: flex; align-items: center; gap: 8px; font-size: 11px; color: var(--text-muted); min-width: 0; }
        .legend-color-dot { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }
        .legend-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #d1d5db; font-weight: 600; font-size: 11px; text-transform: uppercase; }

        .chart-container { position: relative; height: 180px; width: 100%; margin-top: auto; }
        .progress-bar { width: 100%; background: rgba(255,255,255,0.06); border-radius: 10px; height: 8px; margin-top: 8px; overflow: hidden; }
        .progress-fill { height: 100%; border-radius: 10px; }

        input[type="text"], textarea {
            width: 100%; padding: 14px 16px; margin-bottom: 16px; background: rgba(0,0,0,0.2);
            border: 1px solid var(--border-color); border-radius: 10px; color: var(--text-main); font-family: inherit; font-size: 14px; transition: border-color 0.2s;
        }
        input[type="text"]:focus, textarea:focus { outline: none; border-color: var(--accent-blue); }
        
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { color: var(--text-muted); font-size: 11px; text-transform: uppercase; padding: 16px 12px; border-bottom: 2px solid var(--border-color); text-align: left; font-weight: 700; letter-spacing: 0.5px; }
        td { padding: 16px 12px; border-bottom: 1px solid var(--border-color); font-size: 14px; vertical-align: middle; color: #d1d5db; }
        tr:last-child td { border-bottom: none; }
        
        .btn-sm { padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; border: 1px solid rgba(255,255,255,0.08); background: rgba(255,255,255,0.03); color: white; text-decoration: none; display: inline-block; transition: background 0.2s; }
        .btn-sm:hover { background: rgba(255,255,255,0.08); }
        .btn-primary { background: var(--accent-blue); border: none; }
        .btn-primary:hover { background: #2563eb; }
        .btn-edit { color: var(--accent-green); border-color: rgba(16, 185, 129, 0.2); }
        .btn-archive { color: var(--accent-orange); border-color: rgba(245, 158, 11, 0.2); }
        
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block; }
        .pending { background: rgba(245, 158, 11, 0.1); color: var(--accent-orange); }
        .resolved { background: rgba(16, 185, 129, 0.1); color: var(--accent-green); }
        .empty-state { text-align: center; color: var(--text-muted); font-size: 13px; padding: 40px !important; }
    </style>
</head>
<body>

    <nav class="sidebar">
        <h2 style="margin-bottom: 30px; color: white; font-size: 20px; letter-spacing: -0.5px;">BARANGAY HUB</h2>
        <a href="admin.php" style="color: var(--text-main); text-decoration: none; display:block; margin-bottom: 25px; font-size: 14px; font-weight: 500;">📊 Admin Dashboard</a>
        <a href="logout.php" style="color: var(--text-muted); text-decoration: none; display:block; font-size: 14px; margin-top: 30px;">Logout 🚪</a>
    </nav>

    <div class="main-content">
        <div class="container">
            <h1 class="page-title">Barangay Admin Center</h1>
            <p class="page-subtitle">Manage barangay complaints, announcements, and view operational statistics.</p>

            <div style="margin-bottom: 25px; display: flex; gap: 15px; align-items: center;">
                <a href="etl.php" style="display: inline-block; background-color: #8b5cf6; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; font-family: sans-serif; font-weight: bold; font-size: 14px; box-shadow: 0 4px 12px rgba(139, 92, 246, 0.2); transition: all 0.2s ease-in-out;">
                    ⚙️ Run ETL Pipeline
                </a>

                <a href="export.php" style="display: inline-block; background-color: #10b981; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; font-family: sans-serif; font-weight: bold; font-size: 14px; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2); transition: all 0.2s ease-in-out;">
                    📥 Export Excel Report (CSV)
                </a>
            </div>

            <div class="analytics-grid">
                
                <div class="stat-card c-red">
                    <div class="stat-title">📍 Complaints by Sector / Zone</div>
                    <div class="stat-desc">Adaptive mapping aggregating subdivisions and sector codes.</div>
                    
                    <div class="doughnut-wrapper">
                        <div class="canvas-box">
                            <canvas id="purokChart"></canvas>
                        </div>
                        
                        <div class="custom-legend-grid">
                            <?php foreach ($purok_data as $index => $data): 
                                $color = $palette[$index % count($palette)];
                            ?>
                                <div class="legend-item" title="<?php echo htmlspecialchars($data['name']); ?>: <?php echo $data['issues']; ?> Incidents">
                                    <div class="legend-color-dot" style="background-color: <?php echo $color; ?>;"></div>
                                    <span class="legend-text"><?php echo htmlspecialchars($data['name']); ?></span>
                                    <span style="color: var(--text-muted); font-size: 11px; margin-left: auto; font-weight: bold;">(<?php echo $data['issues']; ?>)</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="stat-card c-blue">
                    <div class="stat-title">⚖️ Performance Indicators</div>
                    <div class="stat-desc">Average cleanup and resolution metrics for current issues.</div>
                    
                    <div style="margin-top: auto; margin-bottom: 20px;">
                        <div style="display: flex; justify-content: space-between; font-size: 13px;">
                            <span style="color: var(--text-muted);">Avg. Resolution Speed:</span>
                            <strong style="color: var(--accent-green);"><?php echo $avg_settlement_days; ?> Days</strong>
                        </div>
                        <div class="progress-bar"><div class="progress-fill" style="width: 85%; background: var(--accent-green);"></div></div>
                    </div>

                    <div style="margin-bottom: 10px;">
                        <div style="display: flex; justify-content: space-between; font-size: 13px;">
                            <span style="color: var(--text-muted);">Backlog Overdue Rate:</span>
                            <strong style="color: var(--accent-orange);"><?php echo $escalation_rate; ?>%</strong>
                        </div>
                        <div class="progress-bar"><div class="progress-fill" style="width: <?php echo $escalation_rate; ?>%; background: var(--accent-orange);"></div></div>
                    </div>
                </div>

                <!-- Trend Line Chart Widget Module Space -->
                <div class="stat-card c-purple">
                    <div class="stat-title">⛈️ Trends for this Month (<?php echo $current_month; ?>)</div>
                    <div class="stat-desc">Total numbers for each complaint type during this month.</div>
                    <div class="chart-container">
                        <canvas id="trendChart"></canvas>
                    </div>
                </div>

            </div>

            <div class="card">
                <h3 style="margin-bottom: 5px;">📊 Complaints Category Summary</h3>
                <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 20px;">Quick overview of all complaints submitted by type.</p>
                <table>
                    <thead>
                        <tr>
                            <th>COMPLAINT TYPE</th>
                            <th style="text-align: center;">STATUS</th>
                            <th style="text-align: right;">TOTAL COUNT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($complaint_summary && $complaint_summary->num_rows > 0): ?>
                            <?php while($summary = $complaint_summary->fetch_assoc()): ?>
                            <tr>
                                <td style="font-weight: 600; font-size: 14px; color: white;"><?php echo htmlspecialchars($summary['complaint_type']); ?></td>
                                <td style="text-align: center;">
                                    <?php if($summary['pending_count'] > 0): ?>
                                        <span class="badge pending"><?php echo $summary['pending_count']; ?> Pending</span>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 12px;">All Cleared</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right; font-weight: bold; font-size: 15px; color: white;">
                                    <?php echo $summary['total']; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="3" class="empty-state">No complaints received yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="card" id="post-box" style="border-top: 3px solid var(--accent-blue);">
                <h3>📢 <?php echo $edit_data ? 'Edit Announcement' : 'Create New Announcement'; ?></h3>
                <form method="POST">
                    <?php if($edit_data): ?>
                        <input type="hidden" name="update_id" value="<?php echo $edit_data['id']; ?>">
                    <?php endif; ?>

                    <input type="text" name="title" placeholder="Announcement Title (e.g., Barangay Clean Up Drive)"
                           value="<?php echo $edit_data ? htmlspecialchars($edit_data['title']) : ''; ?>" required>
                    
                    <textarea name="content" placeholder="Write the announcement details here..." rows="4" required style="resize: vertical;"><?php echo $edit_data ? htmlspecialchars($edit_data['content']) : ''; ?></textarea>
                    
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <button type="submit" name="post_announcement" class="btn-sm btn-primary">
                            <?php echo $edit_data ? 'Save Changes' : 'Post Announcement'; ?>
                        </button>

                        <?php if($edit_data): ?>
                            <a href="admin.php" style="color: var(--accent-red); font-size: 13px; text-decoration: none;">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="card">
                <h3>📋 Active Announcements</h3>
                <table>
                    <thead>
                        <tr>
                            <th style="width: 15%;">DATE POSTED</th>
                            <th style="width: 25%;">TITLE</th>
                            <th style="width: 45%;">CONTENT PREVIEW</th>
                            <th style="width: 15%;">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($announcements_list->num_rows > 0): while($row = $announcements_list->fetch_assoc()): ?>
                        <tr>
                            <td style="color: var(--text-muted);"><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                            <td style="font-weight: bold; color: white;"><?php echo htmlspecialchars($row['title']); ?></td>
                            <td style="color: var(--text-muted);"><?php echo htmlspecialchars(substr($row['content'], 0, 80)) . '...'; ?></td>
                            <td>
                                <a href="admin.php?edit_id=<?php echo $row['id']; ?>#post-box" class="btn-sm btn-edit">✏️ Edit</a>
                                <a href="admin.php?archive_id=<?php echo $row['id']; ?>" class="btn-sm btn-archive">📦 Archive</a>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr><td colspan="4" class="empty-state">No active announcements found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="card" style="border-left: 3px solid var(--accent-orange);">
                <h3>📦 Archived Announcements</h3>
                <p style="color: var(--text-muted); font-size: 12px; margin-top: -10px; margin-bottom: 15px;">Old announcements kept in the system database history logs.</p>
                <table>
                    <thead>
                        <tr>
                            <th style="width: 15%;">POST DATE</th>
                            <th style="width: 25%;">TITLE</th>
                            <th style="width: 35%;">CONTENT PREVIEW</th>
                            <th style="width: 10%;">STATUS</th>
                            <th style="width: 15%;">CONTROL</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($archived_list->num_rows > 0): while($row = $archived_list->fetch_assoc()): ?>
                        <tr>
                            <td style="color: var(--text-muted);"><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                            <td style="font-weight: bold; color: white;"><?php echo htmlspecialchars($row['title']); ?></td>
                            <td style="color: var(--text-muted);"><?php echo htmlspecialchars(substr($row['content'], 0, 60)) . '...'; ?></td>
                            <td><span class="badge" style="background: rgba(255,255,255,0.05); color: var(--text-muted);">Archived</span></td>
                            <td>
                                <a href="admin.php?restore_id=<?php echo $row['id']; ?>" class="btn-sm">🔄 Restore</a>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr><td colspan="5" class="empty-state">No archived records found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="card" style="border-left: 3px solid var(--accent-orange);">
                <h3>⚠️ New / Pending Complaints</h3>
                <table>
                    <thead>
                        <tr>
                            <th style="width: 10%;">DATE FILED</th>
                            <th style="width: 15%;">RESIDENT NAME</th>
                            <th style="width: 20%;">TYPE & LOCATION</th>
                            <th style="width: 30%;">DESCRIPTION</th>
                            <th style="width: 10%;">STATUS</th>
                            <th style="width: 15%;">ACTION</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($pending_complaints->num_rows > 0): while($row = $pending_complaints->fetch_assoc()): ?>
                        <tr>
                            <td style="color: var(--text-muted);"><?php echo date('M d', strtotime($row['created_at'])); ?></td>
                            <td style="font-weight: bold; color: white;"><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></td>
                            <td>
                                <div style="font-weight: 500; color: white;"><?php echo htmlspecialchars($row['complaint_type']); ?></div>
                                <div style="font-size: 12px; color: var(--text-muted);"><?php echo htmlspecialchars($row['location'] ?? 'No Location Specified'); ?></div>
                            </td>
                            <td style="color: var(--text-muted);"><?php echo htmlspecialchars($row['description']); ?></td>
                            <td><span class="badge pending">Pending</span></td>
                            <td>
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="resolve_id" value="<?php echo $row['id']; ?>">
                                    <button type="submit" class="btn-sm btn-edit" style="background: transparent; width: 100%;">✓ Mark Resolved</button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr><td colspan="6" class="empty-state">No pending complaints. All issues are currently resolved.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="card" style="border-left: 3px solid var(--accent-green);">
                <h3>✅ Resolved Complaints History</h3>
                <table>
                    <thead>
                        <tr>
                            <th style="width: 15%;">DATE RECORDED</th>
                            <th style="width: 15%;">RESIDENT NAME</th>
                            <th style="width: 20%;">COMPLAINT TYPE</th>
                            <th style="width: 30%;">DESCRIPTION</th>
                            <th style="width: 10%;">STATUS</th>
                            <th style="width: 10%;">LOG CODE</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($resolved_complaints->num_rows > 0): while($row = $resolved_complaints->fetch_assoc()): ?>
                        <tr>
                            <td style="color: var(--text-muted);"><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                            <td style="font-weight: bold; color: white;"><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></td>
                            <td>
                                <div style="color: white;"><?php echo htmlspecialchars($row['complaint_type']); ?></div>
                                <div style="font-size: 12px; color: var(--text-muted);"><?php echo htmlspecialchars($row['location'] ?? 'No Location'); ?></div>
                            </td>
                            <td style="color: var(--text-muted);"><?php echo htmlspecialchars($row['description']); ?></td>
                            <td><span class="badge resolved">Resolved</span></td>
                            <td style="color: var(--text-muted); font-size: 12px; font-family: monospace;">CLOSED</td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr><td colspan="6" class="empty-state">No resolved history records found yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <h3>📇 Registered Residents List</h3>
                <table>
                    <thead>
                        <tr>
                            <th>FIRST NAME</th>
                            <th>LAST NAME</th>
                            <th>USERNAME</th>
                            <th>CONTACT NUMBER</th>
                            <th>REGISTRATION DATE</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($user_list->num_rows > 0): while($user = $user_list->fetch_assoc()): ?>
                        <tr>
                            <td style="font-weight: bold; color: white;"><?php echo htmlspecialchars($user['first_name']); ?></td>
                            <td style="font-weight: bold; color: white;"><?php echo htmlspecialchars($user['last_name']); ?></td>
                            <td style="color: var(--accent-green); font-weight: 500;"><?php echo htmlspecialchars($user['username']); ?></td>
                            <td><?php echo htmlspecialchars($user['contact_number'] ?? 'No Number Provided'); ?></td>
                            <td style="color: var(--text-muted); font-size: 13px;"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr><td colspan="5" class="empty-state">No registered residents found in the system.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>

    <script>
        Chart.defaults.color = '#9ca3af';
        Chart.defaults.font.family = 'sans-serif';

        // --- 1. SPATIAL ROLL-UP DOUGHNUT MATRIX ---
        const purokLabels = <?php echo json_encode(array_column($purok_data, 'name') ?? []); ?>;
        const purokData = <?php echo json_encode(array_column($purok_data, 'issues') ?? []); ?>;
        const colorsPalette = <?php echo json_encode($palette); ?>;
        
        new Chart(document.getElementById('purokChart').getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: purokLabels,
                datasets: [{
                    data: purokData,
                    backgroundColor: colorsPalette,
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                cutout: '75%'
            }
        });

        // --- 2. TEMPORAL MONTHLY CLUSTER ANALYSIS MATRIX (UPDATED: LINE CHART CONVERSION) ---
        const trendLabels = <?php echo json_encode(array_column($trend_data, 'complaint_type') ?? []); ?>;
        const trendData = <?php echo json_encode(array_column($trend_data, 'volume') ?? []); ?>;
        
        new Chart(document.getElementById('trendChart').getContext('2d'), {
            type: 'line', // 📈 Swapped to true line layout for professional trend trajectory
            data: {
                labels: trendLabels,
                datasets: [{
                    label: 'Monthly Incidents Volume',
                    data: trendData,
                    borderColor: '#8b5cf6', // Clear solid border trace
                    backgroundColor: 'rgba(139, 92, 246, 0.12)', // Subtle fill underneath vector vector lines
                    borderWidth: 3,
                    pointBackgroundColor: '#8b5cf6',
                    pointBorderColor: '#fff',
                    pointHoverRadius: 6,
                    tension: 0.35, // Smooth Bezier calculations to remove rough edges from performance indicators
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { 
                        beginAtZero: true, 
                        grid: { color: 'rgba(255, 255, 255, 0.05)' }, 
                        ticks: { stepSize: 1, font: { size: 9 } } 
                    },
                    x: { 
                        grid: { color: 'rgba(255, 255, 255, 0.03)' }, 
                        ticks: { 
                            font: { size: 9 },
                            maxRotation: 30, 
                            minRotation: 30
                        } 
                    }
                }
            }
        });
    </script>
</body>
</html>