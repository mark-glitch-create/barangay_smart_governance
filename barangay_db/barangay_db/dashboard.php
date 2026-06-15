<?php

date_default_timezone_set('Asia/Manila'); 

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'resident') {
    header("Location: login.php"); 
    exit();
}

$resident_name = $_SESSION['username'];
$user_id = $_SESSION['user_id'];
$msg = "";

$check = $conn->query("SELECT first_name, last_name, address_purok, contact_number FROM users WHERE id = $user_id");
$u_data = $check->fetch_assoc();

$is_incomplete = empty($u_data['first_name']) || empty($u_data['last_name']) || empty($u_data['address_purok']) || empty($u_data['contact_number']);

if (isset($_GET['withdraw_id'])) {
    $with_id = (int)$_GET['withdraw_id'];
    $stmt = $conn->prepare("UPDATE complaints SET status = 'Withdrawn' WHERE id = ? AND resident_name = ? AND status = 'Pending'");
    $stmt->bind_param("is", $with_id, $resident_name);
    if ($stmt->execute()) {
        $stmt->close();
        header("Location: dashboard.php?status=withdrawn#history");
        exit();
    }
}

if (isset($_GET['resubmit_id'])) {
    $res_id = (int)$_GET['resubmit_id'];
    $stmt = $conn->prepare("UPDATE complaints SET status = 'Pending', created_at = NOW() WHERE id = ? AND resident_name = ? AND status = 'Withdrawn'");
    $stmt->bind_param("is", $res_id, $resident_name);
    if ($stmt->execute()) {
        $stmt->close();
        header("Location: dashboard.php?status=resubmitted#history");
        exit();
    }
}

if (isset($_POST['save_update'])) {
    $report_id = $_POST['report_id'];
    $new_desc = $_POST['new_description'];
    $stmt = $conn->prepare("UPDATE complaints SET description = ? WHERE id = ? AND resident_name = ? AND status = 'Pending'");
    $stmt->bind_param("sis", $new_desc, $report_id, $resident_name);
    if ($stmt->execute()) {
        $stmt->close();
        header("Location: dashboard.php?status=updated#history");
        exit();
    }
}

if (isset($_POST['submit_complaint'])) {
    if ($is_incomplete) {
        $msg = "<div class='alert alert-danger'>⚠️ Access Denied: Please complete your profile parameters first.</div>";
    } else {
        $type = $_POST['complaint_type'];
        $loc = $_POST['location'];
        $desc = $_POST['description'];
        
        $stmt = $conn->prepare("INSERT INTO complaints (resident_name, complaint_type, location, description, status) VALUES (?, ?, ?, ?, 'Pending')");
        $stmt->bind_param("ssss", $resident_name, $type, $loc, $desc);
        
        if ($stmt->execute()) { 
            $stmt->close();
            header("Location: dashboard.php?status=inserted");
            exit();
        }
    }
}

if (isset($_GET['status'])) {
    if ($_GET['status'] == 'inserted') {
        $msg = "<div class='alert alert-success'>⚡ <strong>Success!</strong> Your concerns have been securely broadcasted to dispatchers.</div>";
    } elseif ($_GET['status'] == 'updated') {
        $msg = "<div class='alert alert-success'>✏️ Entry details successfully updated.</div>";
    } elseif ($_GET['status'] == 'withdrawn') {
        $msg = "<div class='alert alert-danger'>↩️ Tracking canceled. Entry relocated to your Trash Bin archive.</div>";
    } elseif ($_GET['status'] == 'resubmitted') {
        $msg = "<div class='alert alert-success'>🚀 Entry reactivated and re-queued into active management lists.</div>";
    }
}

$news = $conn->query("SELECT * FROM announcements WHERE is_archived = 0 ORDER BY created_at DESC");
$my_reports = $conn->query("SELECT * FROM complaints WHERE resident_name = '$resident_name' AND status NOT IN ('Withdrawn', 'Archived') ORDER BY created_at DESC");
$withdrawn_reports = $conn->query("SELECT * FROM complaints WHERE resident_name = '$resident_name' AND status = 'Withdrawn' ORDER BY created_at DESC");
?>

<!DOCTYPE html>
<html lang="en" style="scroll-behavior: smooth;">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resident Premium Hub</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');
        
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Plus Jakarta Sans', sans-serif; }
        
        body {
            background: #f6f9f8;
            color: #0f172a;
            min-height: 100vh;
            display: flex;
        }

        /* Sidebar Styling */
        .sidebar {
            width: 300px; 
            background: linear-gradient(185deg, #060d0a 0%, #0b1a14 50%, #030705 100%);
            height: 100vh;
            position: fixed; 
            padding: 44px 20px; 
            display: flex; 
            flex-direction: column;
            box-shadow: 8px 0 40px rgba(0, 0, 0, 0.15);
            z-index: 100;
            border-right: 1px solid rgba(255, 255, 255, 0.03);
            transition: box-shadow 0.3s ease, border-color 0.3s ease;
        }

        .sidebar.scrolled-glow {
            border-right-color: rgba(16, 185, 129, 0.4);
            box-shadow: 10px 0 30px rgba(16, 185, 129, 0.15), 2px 0 10px rgba(16, 185, 129, 0.1);
        }

        .sidebar-brand { 
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 44px;
            padding: 0 10px;
        }

        .sidebar-brand .logo-icon {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, #10b981, #059669);
            border-radius: 10px;
            box-shadow: 0 0 15px rgba(16, 185, 129, 0.4);
        }

        .sidebar-brand h2 { 
            font-size: 15px; 
            color: #ffffff; 
            letter-spacing: 2.5px; 
            font-weight: 800; 
            text-transform: uppercase;
            background: linear-gradient(135deg, #ffffff, #a7f3d0);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .sidebar-section-title {
            font-size: 10px;
            color: #475569;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin: 20px 0 10px 16px;
        }

        .nav-item {
            text-decoration: none; 
            color: #64748b; 
            padding: 14px 18px;
            border-radius: 14px; 
            margin-bottom: 6px; 
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); 
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600; 
            font-size: 14px;
            position: relative;
        }

        .nav-item:hover { 
            color: #e2e8f0;
            background: rgba(255, 255, 255, 0.03);
            transform: translateX(4px);
        }

        /* 🚀 UNIFIED SHADOW EFFECT: Cleaned up active rules so both tabs glow equally when active */
        .nav-item.active {
            background: rgba(16, 185, 129, 0.08) !important;
            color: #10b981 !important;
            font-weight: 700 !important;
        }

        .nav-item.active::before {
            content: '';
            position: absolute;
            left: -4px;
            top: 25%;
            height: 50%;
            width: 4px;
            background: #10b981;
            border-radius: 0 4px 4px 0;
            box-shadow: 3px 0 10px rgba(16, 185, 129, 0.8);
        }
        
        .sidebar-user-card {
            margin-top: auto;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.05);
            padding: 16px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            backdrop-filter: blur(10px);
            margin-bottom: 12px;
        }

        .user-avatar-placeholder {
            width: 38px;
            height: 38px;
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #10b981;
            font-weight: 800;
            font-size: 14px;
        }

        .user-card-info {
            display: flex;
            flex-direction: column;
            overflow: hidden;
            width: 100%;
        }

        .user-card-name {
            color: #f1f5f9;
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
            text-overflow: ellipsis;
            overflow: hidden;
        }

        .user-card-role {
            color: #475569;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 1px;
        }

        .logout-nav { 
            color: #fca5a5; 
            background: rgba(220, 38, 38, 0.05);
            border: 1px solid rgba(220, 38, 38, 0.08);
        }
        .logout-nav:hover { 
            background: #dc2626; 
            color: #ffffff; 
            box-shadow: 0 10px 24px rgba(220, 38, 38, 0.25);
            transform: translateY(-1px);
        }

        /* Layout Main Containers */
        .main-content { margin-left: 300px; padding: 50px 60px; width: calc(100% - 300px); }
        .container { max-width: 950px; margin: auto; }
        
        .header-box { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 44px; 
        }

        .card {
            background: #ffffff; 
            border: 1px solid rgba(232, 240, 236, 0.7);
            box-shadow: 0 20px 40px -15px rgba(15, 23, 42, 0.03), 0 15px 25px -10px rgba(15, 23, 42, 0.02);
            padding: 40px;
            border-radius: 24px; 
            margin-bottom: 36px; 
            position: relative;
        }

        h3 { margin-bottom: 26px; color: #0f2119; font-size: 13px; text-transform: uppercase; letter-spacing: 2px; font-weight: 800; display: flex; align-items: center; gap: 8px;}

        label { font-size: 11px; color: #64748b; font-weight: 800; text-transform: uppercase; letter-spacing: 0.8px; display: block; margin-bottom: 6px;}
        input, select, textarea {
            width: 100%; padding: 16px 18px; margin-top: 4px; background: #f8fafb;
            border: 1px solid #e4ebee; border-radius: 14px; color: #0f172a; outline: none;
            transition: all 0.25s ease; font-size: 14px; font-weight: 500;
        }
        input:focus, select:focus, textarea:focus {
            border-color: #10b981;
            box-shadow: 0 0 0 5px rgba(16, 185, 129, 0.08);
            background: #ffffff;
        }

        textarea { resize: none; }

        .btn {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%); 
            color: #ffffff; padding: 18px; border: none; border-radius: 14px;
            cursor: pointer; width: 100%; font-weight: 700; margin-top: 26px; transition: all 0.3s ease;
            text-align: center; display: block; text-decoration: none; font-size: 15px;
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.25);
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 12px 28px rgba(5, 150, 105, 0.35); }

        .report-item {
            background: #ffffff;
            border: 1px solid #eaf2ee;
            border-left: 5px solid #64748b;
            border-radius: 18px;
            padding: 26px 30px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.01);
        }
        .report-item:hover { transform: translateY(-3px); box-shadow: 0 15px 30px rgba(12, 34, 25, 0.05); border-color: #d1e2da; }
        
        .report-item.status-pending { border-left-color: #f59e0b; }
        .report-item.status-withdrawn { border-left-color: #ef4444; }
        .report-item.status-resolved { border-left-color: #10b981; }

        .report-meta { display: flex; flex-direction: column; gap: 8px; max-width: 72%; }
        .report-date { font-size: 12px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.3px; }
        .report-title { font-size: 17px; font-weight: 800; color: #0f1c15; }
        .report-desc { font-size: 14px; color: #475569; line-height: 1.6; }
        .report-loc { font-size: 11px; color: #047857; font-weight: 700; background: #e6f7f0; padding: 5px 12px; border-radius: 50px; width: fit-content; display: inline-flex; align-items: center; gap: 4px; margin-top: 4px;}

        .report-actions-zone { display: flex; flex-direction: column; align-items: flex-end; gap: 16px; }

        .status { padding: 6px 14px; border-radius: 50px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.8px; display: inline-block;}
        .pending { background: #fffbeb; color: #b45309; border: 1px solid #fef3c7; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.08); }
        .withdrawn { background: #fef2f2; color: #b91c1c; border: 1px solid #fee2e2; box-shadow: 0 4px 12px rgba(239, 44, 44, 0.08); }
        .resolved { background: #f0fdf4; color: #15803d; border: 1px solid #dcfce7; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.08); }

        .action-link { text-decoration: none; font-size: 13px; font-weight: 700; margin-left: 16px; transition: color 0.2s ease; }
        .edit-link { color: #3b82f6; }
        .edit-link:hover { color: #1d4ed8; }
        .unsubmit-link { color: #ef4444; }
        .unsubmit-link:hover { color: #991b1b; }
        .resubmit-link { color: #10b981; }
        .resubmit-link:hover { color: #047857; }
        
        .save-btn-small { background: #10b981; color: white; border: none; padding: 8px 18px; border-radius: 10px; cursor: pointer; font-size: 12px; margin-top: 12px; font-weight: 700; }

        .alert { padding: 18px 24px; border-radius: 16px; margin-bottom: 36px; font-weight: 600; font-size: 14px; border: 1px solid transparent; line-height: 1.5; }
        .alert-success { background: #e6f9f0; color: #064e3b; border-color: #a7f3d0; box-shadow: 0 10px 25px -5px rgba(16, 185, 129, 0.05); }
        .alert-danger { background: #fdf2f2; color: #7f1d1d; border-color: #fca5a5; box-shadow: 0 10px 25px -5px rgba(239, 68, 68, 0.05); }

        .lock-overlay {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(255, 255, 255, 0.95); z-index: 10; border-radius: 24px;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            padding: 40px; backdrop-filter: blur(6px);
        }

        .trash-toggle-btn {
            display: inline-block; margin-top: 20px; color: #64748b; 
            font-size: 13px; text-decoration: none; border-bottom: 1px dashed #cbd5e1;
            cursor: pointer; transition: all 0.2s ease; font-weight: 700;
        }
        .trash-toggle-btn:hover { color: #ef4444; border-color: #ef4444; }
    </style>
    <script>
        function toggleTrashBin() {
            var bin = document.getElementById('trash-bin-section');
            if (bin.style.display === 'none' || bin.style.display === '') {
                bin.style.display = 'block';
                document.getElementById('toggle-text').innerText = '🙈 Close Withdrawal Archive';
            } else {
                bin.style.display = 'none';
                document.getElementById('toggle-text').innerText = '📦 View Withdrawn Reports (Trash Bin)';
            }
        }
    </script>
</head>
<body>

<!-- 🚀 SIDEBAR ELEMENT ATTRIBUTES WITH SCAN SIGNATURES -->
<nav class="sidebar" id="mainSidebar">
    <div class="sidebar-brand">
        <div class="logo-icon"></div>
        <h2>Barangay Hub</h2>
    </div>

    <div class="sidebar-section-title">Main Menu</div>
    <a href="#" id="nav-dashboard" class="nav-item active">🏠 Dashboard</a>
    <a href="#history" id="nav-history" class="nav-item">🔍 Report History</a>

    <div class="sidebar-section-title">Account Security</div>
    <a href="profile.php" class="nav-item">👤 My Profile</a>

    <div class="sidebar-user-card">
        <div class="user-avatar-placeholder">
            <?php echo strtoupper(substr($resident_name, 0, 1)); ?>
        </div>
        <div class="user-card-info">
            <span class="user-card-name"><?php echo htmlspecialchars($resident_name); ?></span>
            <span class="user-card-role">Verified Resident</span>
        </div>
    </div>

    <a href="logout.php" class="nav-item logout-nav">🚪 Logout</a>
</nav>

<!-- 🚀 TOP BOUNDARY CONTAINER TARGET FOR PRIMARY VIEWPORT MAPPING -->
<div class="main-content" id="dashboard-top-section">
    <div class="container">
        
        <div class="header-box">
            <div>
                <p style="color: #64748b; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Welcome back</p>
                <h1 style="font-size: 34px; font-weight: 800; color: #0c1f17; margin-top: 4px; letter-spacing: -0.5px;"><?php echo htmlspecialchars($resident_name); ?></h1>
            </div>
            <div style="text-align: right;">
                <p style="color: #10b981; font-weight: 800; font-size: 17px; letter-spacing: -0.3px;"><?php echo date('F d, Y'); ?></p>
                <span style="font-size: 12px; color: #64748b; font-weight: 600;">Navarro, General Trias</span>
            </div>
        </div>

        <?php echo $msg; ?>

        <?php if($is_incomplete): ?>
            <div class="alert alert-danger" style="border-left: 5px solid #dc2626;">
                ⚠️ &nbsp; <span><strong>Profile Locked:</strong> Secure authorization requirements are unfulfilled. Map out your info inside the <a href="profile.php" style="color: #2563eb; font-weight:800; text-decoration: underline;">Profile Window</a> to activate form portals.</span>
            </div>
        <?php endif; ?>

        <!-- ANNOUNCEMENTS BOX -->
        <div class="card" style="border-top: 4px solid #10b981;">
            <h3>📢 Community Advisories & Announcements</h3>
            <?php if($news->num_rows > 0): ?>
                <?php while($n = $news->fetch_assoc()): ?>
                    <div style="margin-bottom: 24px; border-bottom: 1px solid #f0f5f3; padding-bottom: 20px;">
                        <strong style="color: #0c1f17; font-size: 16px; font-weight: 800; display: block;"><?php echo htmlspecialchars($n['title']); ?></strong>
                        <p style="font-size: 14px; color: #475569; margin-top: 8px; line-height: 1.65;"><?php echo htmlspecialchars($n['content']); ?></p>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p style="color: #94a3b8; font-size: 14px; font-weight: 500;">No neighborhood update notices broadcasted today.</p>
            <?php endif; ?>
        </div>

        <!-- FORM BOX -->
        <div class="card">
            <h3>📝 Submit a Community Concern</h3>
            
            <?php if ($is_incomplete): ?>
                <div class="lock-overlay">
                    <p style="font-weight: 800; color: #dc2626; margin-bottom: 4px; font-size: 19px;">🔒 Access Restrained</p>
                    <p style="font-size: 14px; color: #64748b; margin-bottom: 24px; max-width: 360px; line-height: 1.5;">Finish updating your citizen metrics profile page to bypass safety lock overlays.</p>
                    <a href="profile.php" class="btn" style="width: auto; padding: 14px 36px; margin-top:0; background: #dc2626; box-shadow: 0 8px 25px rgba(220, 38, 38, 0.25);">Complete Profile</a>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                    <div>
                        <label>Incident Categorization</label>
                        <select name="complaint_type" required>
                            <option value="Noise Disturbance">Noise Disturbance</option>
                            <option value="Garbage/Waste">Garbage/Waste Issue</option>
                            <option value="Peace and Order">Peace and Order</option>
                            <option value="Street Light Malfunction">Street Light Malfunction</option>
                            <option value="Illegal Parking">Illegal Parking</option>
                            <option value="Water Leak/Supply">Water Leak/Supply Issue</option>
                            <option value="Drainage Clogging">Drainage Clogging</option>
                            <option value="Stray Animals">Stray Animals</option>
                            <option value="Suspicious Activity">Suspicious Activity</option>
                            <option value="Others">Others</option>
                        </select>
                    </div>
                    <div>
                        <label>Exact Location Matrix / Landmark</label>
                        <input type="text" name="location" placeholder="e.g., Block 3 Lot 12, Phase 2" required>
                    </div>
                </div>

                <div style="margin-top: 24px;">
                    <label>Factual Incident Description</label>
                    <textarea name="description" rows="4" placeholder="Detail the ongoing situational conditions cleanly to allow fast dispatch analysis..." required></textarea>
                </div>
                
                <button type="submit" name="submit_complaint" class="btn">File Official Report</button>
            </form>
        </div>

        <!-- TIMELINE LOG FEED (TARGET BOX MODULE FOR THE LIVE SCROLLSPY) -->
        <div class="card" id="history">
            <h3>🔍 My Active Monitoring Feed</h3>
            
            <div style="margin-top: 12px;">
                <?php if($my_reports->num_rows == 0): ?>
                    <div style="text-align: center; color: #94a3b8; padding: 44px 20px; font-size: 14px; font-weight: 500;">
                         Your personal tracking timeline dashboard is completely clean.
                    </div>
                <?php else: ?>
                    <?php while($m = $my_reports->fetch_assoc()): ?>
                        <div class="report-item status-<?php echo strtolower($m['status']); ?>">
                            <div class="report-meta">
                                <span class="report-date">Filed: <?php echo date('M d, Y • h:i A', strtotime($m['created_at'])); ?></span>
                                <span class="report-title"><?php echo htmlspecialchars($m['complaint_type']); ?></span>
                                
                                <?php if(isset($_GET['edit_id']) && $_GET['edit_id'] == $m['id']): ?>
                                    <form method="POST" style="width: 100%; margin-top: 10px;">
                                        <input type="hidden" name="report_id" value="<?php echo $m['id']; ?>">
                                        <textarea name="new_description" rows="3" required><?php echo htmlspecialchars($m['description']); ?></textarea>
                                        <button type="submit" name="save_update" class="save-btn-small">Save Edits</button>
                                        <a href="dashboard.php#history" style="font-size: 12px; color: #ef4444; margin-left: 16px; font-weight: 700; text-decoration:none;">Discard</a>
                                    </form>
                                <?php else: ?>
                                    <p class="report-desc"><?php echo htmlspecialchars($m['description']); ?></p>
                                    <?php if(!empty($m['location'])): ?>
                                        <span class="report-loc">📍 Landmark: <?php echo htmlspecialchars($m['location']); ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            
                            <div class="report-actions-zone">
                                <span class="status <?php echo strtolower($m['status']); ?>"><?php echo $m['status']; ?></span>
                                <div style="display: flex; align-items: center;">
                                    <?php if($m['status'] == 'Pending'): ?>
                                        <a href="dashboard.php?edit_id=<?php echo $m['id']; ?>#history" class="action-link edit-link">Modify</a>
                                        <a href="dashboard.php?withdraw_id=<?php echo $m['id']; ?>" class="action-link unsubmit-link" onclick="return confirm('Confirm cancellation and withdrawal of this log entity?');">Cancel Log</a>
                                    <?php else: ?>
                                        <span style="font-size: 12px; color: #94a3b8; font-weight: 700; padding-right:12px;">Submitted</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php endif; ?>
            </div>

            <span class="trash-toggle-btn" onclick="toggleTrashBin()" id="toggle-text">📦 View Withdrawn Reports (Trash Bin)</span>

            <!-- ARCHIVE TRASH LOG -->
            <div id="trash-bin-section" style="display:none; margin-top: 36px; border-top: 2px dashed #e4ebee; padding-top:26px;">
                <h3>🗑️ Self-Withdrawn Archives</h3>
                
                <?php if($withdrawn_reports->num_rows == 0): ?>
                    <p style="color: #94a3b8; font-size: 14px; font-weight: 500; text-align: center; padding: 24px;">Your canceled log index storage is empty.</p>
                <?php else: ?>
                    <?php while($w = $withdrawn_reports->fetch_assoc()): ?>
                        <div class="report-item status-withdrawn" style="background: #fafcfb; opacity: 0.85;">
                            <div class="report-meta">
                                <span class="report-date">Logged: <?php echo date('M d, Y', strtotime($w['created_at'])); ?></span>
                                <span class="report-title" style="color: #64748b; text-decoration: line-through;"><?php echo htmlspecialchars($w['complaint_type']); ?></span>
                                <p class="report-desc" style="color:#94a3b8; font-style: italic;">"<?php echo htmlspecialchars($w['description']); ?>"</p>
                            </div>
                            <div class="report-actions-zone">
                                <span class="status withdrawn">Withdrawn</span>
                                <a href="dashboard.php?resubmit_id=<?php echo $w['id']; ?>" class="action-link resubmit-link" onclick="return confirm('Do you want to re-engage this tracking entry?');">🚀 Resubmit Entry</a>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<!-- 🚀 THE LIVE VIEWPORT SCROLLSPY CONTROLLER SCRIPT -->
<script>
    window.addEventListener('scroll', function() {
        const sidebar = document.getElementById('mainSidebar');
        const navDashboard = document.getElementById('nav-dashboard');
        const navHistory = document.getElementById('nav-history');
        const historySection = document.getElementById('history');
        
        // 1. Sidebar Edge Glow Layer Trigger
        if (window.scrollY > 20) {
            sidebar.classList.add('scrolled-glow');
        } else {
            sidebar.classList.remove('scrolled-glow');
        }

        // 2. Dynamic Scrollspy Switch Mechanism
        // Calculates distance of the Active Monitoring card from the top viewport ceiling
        const historyBoundary = historySection.getBoundingClientRect().top;

        // If the Report History card scrolls up into the top 35% of the viewport screen space
        if (historyBoundary <= window.innerHeight * 0.35) {
            navDashboard.classList.remove('active');
            navHistory.classList.add('active');
        } else {
            // Revert glow safely to Dashboard if they scroll back up
            navHistory.classList.remove('active');
            navDashboard.classList.add('active');
        }
    });
</script>
</body>
</html>