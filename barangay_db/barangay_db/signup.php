<?php
include 'db.php';
$msg = "";

if (isset($_POST['register'])) {
    $user = $_POST['username'];
   
    $pass = password_hash($_POST['password'], PASSWORD_DEFAULT); 
    $role = 'resident'; 

    // 🔒 UNTOUCHED: Original precise prepared statement logic
    $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $check->bind_param("s", $user);
    $check->execute();
    
    if ($check->get_result()->num_rows > 0) {
        // Updated to matching custom notification system layout
        $msg = "<div class='alert alert-danger'>⚠️ <strong>Registration Error:</strong> Username already exists!</div>";
    } else {
        $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $user, $pass, $role);
        if ($stmt->execute()) {
            $msg = "<div class='alert alert-success'>🎉 <strong>Account Created!</strong> Your registration is verified. <a href='login.php'>Login here</a></div>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resident Signup | Secure Portal</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');
        
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Plus Jakarta Sans', sans-serif; }
        
        body {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            color: #0f172a;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-wrapper {
            width: 100%;
            max-width: 480px;
            background: #ffffff;
            border: 1px solid rgba(232, 240, 236, 0.8);
            box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.08), 0 12px 24px -8px rgba(15, 23, 42, 0.04);
            border-radius: 28px;
            padding: 48px;
            position: relative;
            overflow: hidden;
        }

        /* Top Emerald accent bar to match system theme colors */
        .login-wrapper::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, #10b981, #059669);
        }

        .brand-header {
            text-align: center;
            margin-bottom: 36px;
        }

        .brand-icon {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, #10b981, #059669);
            border-radius: 12px;
            margin: 0 auto 16px auto;
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.3);
        }

        .brand-header h1 {
            font-size: 24px;
            font-weight: 800;
            color: #0f2119;
            letter-spacing: -0.5px;
        }

        .brand-header p {
            font-size: 13px;
            color: #64748b;
            font-weight: 600;
            margin-top: 4px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        label { 
            font-size: 11px; 
            color: #475569; 
            font-weight: 800; 
            text-transform: uppercase; 
            letter-spacing: 0.8px; 
            display: block; 
            margin-bottom: 6px;
        }

        .input-group {
            margin-bottom: 22px;
        }

        input {
            width: 100%; padding: 16px 18px; margin-top: 4px; background: #f8fafb;
            border: 1px solid #e2e8f0; border-radius: 14px; color: #0f172a; outline: none;
            transition: all 0.25s ease; font-size: 14px; font-weight: 500;
        }

        input:focus {
            border-color: #10b981;
            box-shadow: 0 0 0 5px rgba(16, 185, 129, 0.08);
            background: #ffffff;
        }

        .btn {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%); 
            color: #ffffff; padding: 18px; border: none; border-radius: 14px;
            cursor: pointer; width: 100%; font-weight: 700; margin-top: 10px; transition: all 0.3s ease;
            text-align: center; display: block; text-decoration: none; font-size: 15px;
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.25);
        }

        .btn:hover { 
            transform: translateY(-1px); 
            box-shadow: 0 12px 28px rgba(5, 150, 105, 0.35); 
        }

        /* Modernized Alert Notification Boxes */
        .alert { 
            padding: 16px 20px; 
            border-radius: 14px; 
            margin-bottom: 24px; 
            font-weight: 600; 
            font-size: 13px; 
            line-height: 1.5; 
            border: 1px solid transparent;
        }
        
        .alert-danger {
            background: #fdf2f2; 
            color: #7f1d1d; 
            border-color: #fca5a5; 
        }

        .alert-success {
            background: #e6f9f0;
            color: #064e3b;
            border-color: #a7f3d0;
        }

        .alert-success a {
            color: #10b981;
            text-decoration: underline;
            font-weight: 700;
        }
        
        .form-footer {
            text-align: center;
            margin-top: 28px;
            font-size: 14px;
            color: #64748b;
            font-weight: 600;
        }

        .form-footer a {
            color: #10b981;
            text-decoration: none;
            font-weight: 700;
        }

        .form-footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>

<div class="login-wrapper">
    <div class="brand-header">
        <div class="brand-icon"></div>
        <h1>Resident Registration</h1>
        <p>Join our community</p>
    </div>

    <?php if(!empty($msg)) { echo $msg; } ?>

    <form method="POST" action="">
        <div class="input-group">
            <label>Username</label>
            <input type="text" name="username" placeholder="Choose a username" required autocomplete="off">
        </div>

        <div class="input-group">
            <label>Password</label>
            <input type="password" name="password" placeholder="Create secure password" required>
        </div>

        <button type="submit" name="register" class="btn">Register Now</button>
    </form>
    
    <div class="form-footer">
        Already a member? <a href="login.php">Login here</a>
    </div>
</div>

</body>
</html>