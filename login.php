<?php
require_once 'db.php';
require_once 'auth.php';

// Jika sudah login, langsung ke admin
if (isLoggedIn()) {
    header("Location: admin.php");
    exit;
}

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (!empty($username) && !empty($password)) {
        // AMAN: Baca dari .env (Cek $_ENV, $_SERVER, atau getenv)
        $envUser = $_ENV['ADMIN_USER'] ?? $_SERVER['ADMIN_USER'] ?? getenv('ADMIN_USER') ?: null;
        $envPass = $_ENV['ADMIN_PASS'] ?? $_SERVER['ADMIN_PASS'] ?? getenv('ADMIN_PASS') ?: null;

        if ($envUser === null || $envPass === null) {
            sleep(2);
            $error = "Konfigurasi server tidak valid. Hubungi administrator.";
        } elseif ($username === $envUser && $password === $envPass) {
            // Anti Session Fixation Hack: Regenerate ID baru setelah sukses login
            session_regenerate_id(true);
            
            $_SESSION['admin_id'] = 1;
            $_SESSION['admin_user'] = $username;
            
            // Catat aksi login ke audit_logs
            auditLog($pdo, 'LOGIN', "username=$username");

            header("Location: admin.php");
            exit;
        } else {
            // Anti Brute-force: delay agar tidak bisa spam
            sleep(1);
            $error = "Username atau password salah!";
        }
    } else {
        $error = "Mohon isi semua field!";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Admin Survei - Premium</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .login-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }
        .login-card {
            width: 100%;
            max-width: 420px;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.6);
            border-radius: 24px;
            box-shadow: 0 15px 35px rgba(13, 44, 94, 0.15);
            padding: 40px 35px;
            text-align: center;
            animation: slideUpFade 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        @keyframes slideUpFade {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .login-header img {
            height: 60px;
            margin-bottom: 20px;
        }
        .login-header h1 {
            color: var(--primary-color);
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        .login-header p {
            color: var(--text-muted);
            font-size: 0.95rem;
            margin-bottom: 30px;
        }
        .login-form {
            text-align: left;
        }
        .input-group label {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            margin-bottom: 8px;
            display: block;
            font-weight: 700;
        }
        .input-wrapper {
            position: relative;
        }
        .input-wrapper i {
            position: absolute;
            top: 50%;
            left: 16px;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 1.1rem;
        }
        .input-wrapper input {
            width: 100%;
            padding: 14px 16px 14px 45px;
            border: 2px solid var(--border);
            border-radius: 12px;
            font-family: inherit;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: rgba(255,255,255,0.9);
        }
        .input-wrapper input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(13, 44, 94, 0.1);
        }
        .btn-login {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-family: inherit;
            font-size: 1.05rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 15px;
            box-shadow: 0 4px 15px rgba(13, 44, 94, 0.2);
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(13, 44, 94, 0.3);
        }
        .error-msg {
            background: #fff0f0;
            color: #e03131;
            padding: 12px 15px;
            border-radius: 10px;
            border-left: 4px solid #e03131;
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            text-align: left;
            animation: shake 0.5s ease-in-out;
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
        }
    </style>
</head>
<body>
    <div class="background-container">
        <div class="circle circle-1"></div>
        <div class="circle circle-2"></div>
        <div class="circle circle-3"></div>
    </div>
    
    <div class="login-wrapper">
        <div class="login-card">
            <div class="login-header">
                <img src="assets/images/logo-apt.svg" alt="APT Pranoto Logo">
                <h1>Admin Panel</h1>
                <p>Silakan masuk untuk mengelola survei</p>
            </div>

            <?php if ($error): ?>
                <div class="error-msg">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST" class="login-form">
                <div class="input-group">
                    <label for="username">Username</label>
                    <div class="input-wrapper">
                        <i class="fa-solid fa-user"></i>
                        <input type="text" id="username" name="username" placeholder="Masukkan username" required autofocus>
                    </div>
                </div>
                
                <div class="input-group">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <i class="fa-solid fa-lock"></i>
                        <input type="password" id="password" name="password" placeholder="Masukkan password" required>
                    </div>
                </div>
                
                <button type="submit" class="btn-login">Masuk ke Dashboard <i class="fa-solid fa-arrow-right-to-bracket"></i></button>
            </form>
        </div>
    </div>
</body>
</html>
