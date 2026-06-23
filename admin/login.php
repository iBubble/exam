<?php
require_once '../inc/db.inc.php';
require_once '../inc/functions.inc.php';
startAdminSession();

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (!empty($username) && !empty($password)) {
        // 查询管理员信息
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();
        
        if ($admin) {
            // 首先尝试使用password_verify验证密码（标准方式）
            if (password_verify($password, $admin['password'])) {
                // 重新生成 Session ID 防范会话固定攻击
                session_regenerate_id(true);
                // 密码验证成功，登录
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                logAdminAction($pdo, '管理员登录', 'success', '登录成功');
                header('Location: index.php');
                exit;
            }
            
            // 如果password_verify失败，检查是否是初始密码（仅用于首次登录时自动加密）
            // 注意：只有在数据库中的密码还是旧的未加密格式时，才执行此逻辑
            // 如果密码已经是加密的，但验证失败，说明密码错误，不应该更新
            if ($username === 'Admin' && $password === 'Gl5181081') {
                // 检查数据库中的密码是否已经是加密格式（以$2y$开头）
                if (!preg_match('/^\$2[ayb]\$.{56}$/', $admin['password'])) {
                    // 如果密码不是加密格式，说明是首次登录，更新为加密密码
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE admins SET password = ? WHERE username = ?");
                    $stmt->execute([$hashed_password, $username]);
                    
                    // 重新生成 Session ID 防范会话固定攻击
                    session_regenerate_id(true);
                    
                    $_SESSION['admin_id'] = $admin['id'];
                    $_SESSION['admin_username'] = $username;
                    logAdminAction($pdo, '管理员登录-初始化加密', 'success', '首次登录自动加密');
                    header('Location: index.php');
                    exit;
                }
            }
        }
        
        // 如果到这里，说明密码验证失败
            $error = '用户名或密码错误！';
            logAdminAction($pdo, '管理员登录', 'failed', '用户名或密码错误');
    } else {
        $error = '请填写用户名和密码！';
        logAdminAction($pdo, '管理员登录', 'failed', '缺少用户名或密码');
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理员登录 - <?php echo getRandomTitle(); ?></title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.svg">
    <style>
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }
        
        body {
            font-family: 'Microsoft YaHei', 'PingFang SC', 'Helvetica Neue', Arial, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
            background-size: 400% 400%;
            animation: gradientShift 15s ease infinite;
        }
        
        /* 背景动画 */
        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        
        /* 背景装饰元素 */
        body::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: 
                radial-gradient(circle at 20% 50%, rgba(255,255,255,0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(255,255,255,0.1) 0%, transparent 50%),
                radial-gradient(circle at 40% 20%, rgba(255,255,255,0.05) 0%, transparent 50%);
            animation: float 20s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            33% { transform: translate(30px, -30px) rotate(120deg); }
            66% { transform: translate(-20px, 20px) rotate(240deg); }
        }
        
        .login-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            padding: 45px 40px;
            border-radius: 20px;
            box-shadow: 
                0 20px 60px rgba(0, 0, 0, 0.3),
                0 0 0 1px rgba(255, 255, 255, 0.5) inset;
            width: 100%;
            max-width: 420px;
            position: relative;
            z-index: 1;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .login-container:hover {
            transform: translateY(-5px);
            box-shadow: 
                0 25px 70px rgba(0, 0, 0, 0.35),
                0 0 0 1px rgba(255, 255, 255, 0.6) inset;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 35px;
        }
        
        .login-header .icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
            font-size: 40px;
            color: white;
        }
        
        h1 {
            font-size: 28px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 8px;
            letter-spacing: 0.5px;
        }
        
        .login-subtitle {
            color: #7f8c8d;
            font-size: 14px;
            font-weight: 400;
        }
        
        .form-group {
            margin-bottom: 24px;
        }
        
        label {
            display: block;
            margin-bottom: 10px;
            color: #34495e;
            font-weight: 500;
            font-size: 14px;
            letter-spacing: 0.3px;
        }
        
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 14px 18px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.3s ease;
            background: #fafafa;
            color: #2c3e50;
            font-family: inherit;
        }
        
        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #667eea;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
            transform: translateY(-2px);
        }
        
        input[type="text"]::placeholder,
        input[type="password"]::placeholder {
            color: #bdc3c7;
        }
        
        .btn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
            letter-spacing: 0.5px;
            position: relative;
            overflow: hidden;
        }
        
        .btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }
        
        .btn:hover::before {
            width: 300px;
            height: 300px;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
        }
        
        .btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 10px rgba(102, 126, 234, 0.4);
        }
        
        .btn span {
            position: relative;
            z-index: 1;
        }
        
        .error {
            color: #e74c3c;
            text-align: center;
            margin-bottom: 20px;
            padding: 14px 18px;
            background: linear-gradient(135deg, rgba(231, 76, 60, 0.1) 0%, rgba(231, 76, 60, 0.05) 100%);
            border-radius: 12px;
            font-size: 14px;
            border: 1px solid rgba(231, 76, 60, 0.2);
            animation: shake 0.5s ease;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }
        
        /* 响应式设计 */
        @media (max-width: 480px) {
            .login-container {
                padding: 35px 25px;
                margin: 20px;
                border-radius: 16px;
            }
            
            h1 {
                font-size: 24px;
            }
            
            .login-header .icon {
                width: 60px;
                height: 60px;
                font-size: 30px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="icon">🔐</div>
            <h1>管理员登录</h1>
            <p class="login-subtitle">后台管理 · 安全访问</p>
        </div>
        <?php if ($error): ?>
            <div class="error"><?php echo escape($error); ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label for="username">用户名</label>
                <input type="text" id="username" name="username" required placeholder="请输入用户名">
            </div>
            <div class="form-group">
                <label for="password">密码</label>
                <input type="password" id="password" name="password" required placeholder="请输入密码">
            </div>
            <button type="submit" class="btn">
                <span>登录 →</span>
            </button>
        </form>
    </div>
    <?php include '../inc/footer.php'; ?>
</body>
</html>

