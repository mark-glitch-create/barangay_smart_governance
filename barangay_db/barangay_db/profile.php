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

$user_id = $_SESSION['user_id'];
$resident_name = $_SESSION['username'];
$msg = "";

// Fetch current user details
$stmt = $conn->prepare("SELECT first_name, last_name, address_purok, contact_number FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$u_data = $result->fetch_assoc();
$stmt->close();

$is_incomplete = empty($u_data['first_name']) || empty($u_data['last_name']) || empty($u_data['address_purok']) || empty($u_data['contact_number']);

if (isset($_POST['update_profile'])) {
    $fname = trim($_POST['first_name']);
    $lname = trim($_POST['last_name']);
    $purok = trim($_POST['address_purok']); 
    $contact = trim($_POST['contact_number']);

    // 1. Check if any fields are strictly empty
    if (empty($fname) || empty($lname) || empty($purok) || empty($contact)) {
        $msg = "<div class='alert alert-danger'>⚠️ <strong>Error:</strong> All profile metrics are strictly mandatory.</div>";
    } 
    // 2. BACKEND BLOCK: Validate that contact number contains ONLY numbers (0-9)
    elseif (!preg_match('/^[0-9]+$/', $contact)) {
        $msg = "<div class='alert alert-danger'>⚠️ <strong>Error:</strong> Invalid contact number format. Symbols, spaces, and special characters are not allowed.</div>";
    } 
    // 3. Strict Backend Length Restriction: Must be exactly 11 digits long
    elseif (strlen($contact) !== 11) {
        $msg = "<div class='alert alert-danger'>⚠️ <strong>Error:</strong> Invalid length. Contact number must be exactly 11 digits long (e.g., 09123456789).</div>";
    }
    else {
        $update_stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, address_purok = ?, contact_number = ? WHERE id = ?");
        $update_stmt->bind_param("ssssi", $fname, $lname, $purok, $contact, $user_id);
        
        if ($update_stmt->execute()) {
            $msg = "<div class='alert alert-success'>🛡️ <strong>Profile Secured:</strong> Your registration metrics have been safely encrypted.</div>";
            // Refresh data arrays
            $u_data['first_name'] = $fname;
            $u_data['last_name'] = $lname;
            $u_data['address_purok'] = $purok;
            $u_data['contact_number'] = $contact;
            $is_incomplete = false;
        } else {
            $msg = "<div class='alert alert-danger'>⚠️ Systems fault error occurred during save. Try again.</div>";
        }
        $update_stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Citizen Profile Vault</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');
        
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Plus Jakarta Sans', sans-serif; }
        
        body {
            background: #f6f9f8;
            color: #0f172a;
            min-height: 100vh;
            display: flex;
        }

        /* Sidebar Elements */
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

        .nav-item.active {
            background: rgba(16, 185, 129, 0.08);
            color: #10b981;
            font-weight: 700;
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

        /* Layout Framework */
        .main-content { margin-left: 300px; padding: 50px 60px; width: calc(100% - 300px); }
        .container { max-width: 800px; margin: auto; }

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
            padding: 44px;
            border-radius: 24px; 
            position: relative;
        }

        h3 { margin-bottom: 30px; color: #0f2119; font-size: 13px; text-transform: uppercase; letter-spacing: 2px; font-weight: 800; display: flex; align-items: center; gap: 8px;}

        label { font-size: 11px; color: #64748b; font-weight: 800; text-transform: uppercase; letter-spacing: 0.8px; display: block; margin-bottom: 6px;}
        input {
            width: 100%; padding: 16px 18px; margin-top: 4px; background: #f8fafb;
            border: 1px solid #e4ebee; border-radius: 14px; color: #0f172a; outline: none;
            transition: all 0.25s ease; font-size: 14px; font-weight: 500;
        }
        input:focus {
            border-color: #10b981;
            box-shadow: 0 0 0 5px rgba(16, 185, 129, 0.08);
            background: #ffffff;
        }

        input:disabled {
            background: #f1f5f9;
            color: #94a3b8;
            cursor: not-allowed;
            border-color: #e2e8f0;
        }

        .btn {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%); 
            color: #ffffff; padding: 18px; border: none; border-radius: 14px;
            cursor: pointer; width: 100%; font-weight: 700; margin-top: 14px; transition: all 0.3s ease;
            text-align: center; display: block; text-decoration: none; font-size: 15px;
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.25);
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 12px 28px rgba(5, 150, 105, 0.35); }

        .alert { padding: 18px 24px; border-radius: 16px; margin-bottom: 36px; font-weight: 600; font-size: 14px; border: 1px solid transparent; line-height: 1.5; }
        .alert-success { background: #e6f9f0; color: #064e3b; border-color: #a7f3d0; box-shadow: 0 10px 25px -5px rgba(16, 185, 129, 0.05); }
        .alert-danger { background: #fdf2f2; color: #7f1d1d; border-color: #fca5a5; box-shadow: 0 10px 25px -5px rgba(239, 68, 68, 0.05); }

        .badge-status {
            padding: 6px 14px; border-radius: 50px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.8px; display: inline-flex; align-items: center; gap: 6px;
        }
        .badge-verified { background: #f0fdf4; color: #15803d; border: 1px solid #dcfce7; }
        .badge-pending { background: #fffbeb; color: #b45309; border: 1px solid #fef3c7; }
    </style>
</head>
<body>

<nav class="sidebar">
    <div class="sidebar-brand">
        <div class="logo-icon"></div>
        <h2>Barangay Hub</h2>
    </div>

    <div class="sidebar-section-title">Main Menu</div>
    <a href="dashboard.php" class="nav-item">🏠 Dashboard</a>
    <a href="dashboard.php#history" class="nav-item">🔍 Report History</a>

    <div class="sidebar-section-title">Account Security</div>
    <a href="profile.php" class="nav-item active">👤 My Profile</a>

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

<div class="main-content">
    <div class="container">
        
        <div class="header-box">
            <div>
                <p style="color: #64748b; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Identity Vault</p>
                <h1 style="font-size: 34px; font-weight: 800; color: #0c1f17; margin-top: 4px; letter-spacing: -0.5px;">Profile Settings</h1>
            </div>
            <div>
                <?php if($is_incomplete): ?>
                    <span class="badge-status badge-pending">⚠️ Action Required</span>
                <?php else: ?>
                    <span class="badge-status badge-verified">🛡️ Profile Verified</span>
                <?php endif; ?>
            </div>
        </div>

        <?php echo $msg; ?>

        <div class="card">
            <h3>👤 Personal Registration Parameters</h3>
            
            <form method="POST">
                <div style="display: grid; grid-template-columns: 1fr; gap: 24px; margin-bottom: 24px;">
                    <div>
                        <label>Account Login Handle (Immutable)</label>
                        <input type="text" value="<?php echo htmlspecialchars($resident_name); ?>" disabled>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px;">
                    <div>
                        <label>First Name</label>
                        <input type="text" name="first_name" placeholder="e.g., John" value="<?php echo htmlspecialchars($u_data['first_name'] ?? ''); ?>" required>
                    </div>
                    <div>
                        <label>Last Name</label>
                        <input type="text" name="last_name" placeholder="e.g., Doe" value="<?php echo htmlspecialchars($u_data['last_name'] ?? ''); ?>" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 30px;">
                    <div>
                        <label>Address / Purok Details</label>
                        <input type="text" name="address_purok" placeholder="e.g., Blk 4 Lot 2, Purok 3" value="<?php echo htmlspecialchars($u_data['address_purok'] ?? ''); ?>" required>
                    </div>
                    <div>
                        <label>Active Contact Number</label>
                        <input 
                            type="text" 
                            name="contact_number" 
                            placeholder="e.g., 09123456789" 
                            value="<?php echo htmlspecialchars($u_data['contact_number'] ?? ''); ?>" 
                            inputmode="numeric"
                            pattern="[0-9]{11}"
                            maxlength="11"
                            title="Please enter exactly 11 digits. Special characters, spaces, and extra letters are not allowed."
                            required>
                    </div>
                </div>
                
                <button type="submit" name="update_profile" class="btn">Save Configuration Changes</button>
            </form>
        </div>

    </div>
</div>

</body>
</html>