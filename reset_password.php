<?php
session_start();

$conn = new mysqli("localhost", "root", "", "ccsjdm_system");
if ($conn->connect_error) {
    die("Database connection failed.");
}
$conn->set_charset("utf8mb4");

$token   = $_GET["token"] ?? $_POST["token"] ?? "";
$error   = "";
$success = false;

function getValidReset($conn, $token) {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $hash = hash("sha256", $token);
    $stmt = $conn->prepare(
        "SELECT id, student_id FROM password_resets
         WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
         LIMIT 1"
    );
    $stmt->bind_param("s", $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

$reset = getValidReset($conn, $token);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $password = $_POST["password"] ?? "";
    $confirm  = $_POST["confirm_password"] ?? "";

    if (!$reset) {
        $error = "Invalid o expired na reset link.";
    } elseif (strlen($password) < 8) {
        $error = "Dapat hindi bababa sa 8 characters ang password.";
    } elseif ($password !== $confirm) {
        $error = "Hindi magkapareho ang password.";
    } else {
        $hashed = password_hash($password, PASSWORD_DEFAULT);

        $upd = $conn->prepare("UPDATE users SET password_hash = ? WHERE student_id = ?");
        $upd->bind_param("ss", $hashed, $reset["student_id"]);
        $upd->execute();
        $upd->close();

        $mark = $conn->prepare("UPDATE password_resets SET used_at = NOW() WHERE student_id = ?");
        $mark->bind_param("s", $reset["student_id"]);
        $mark->execute();
        $mark->close();

        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password | CCSJDM</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: Arial, Helvetica, sans-serif; }
    body {
        min-height: 100vh; display: flex; justify-content: center; align-items: center; padding: 20px;
        color: #fff; background: linear-gradient(135deg, #16002b, #2b0645, #090014);
    }
    .card {
        width: 100%; max-width: 430px; padding: 40px 35px; border-radius: 24px;
        background: rgba(20,5,35,0.75); border: 1px solid rgba(255,255,255,0.15);
        box-shadow: 0 25px 60px rgba(0,0,0,0.45);
    }
    .logo { width: 100px; height: 100px; margin: 0 auto 20px; border-radius: 50%; overflow: hidden; background: #fff; }
    .logo img { width: 100%; height: 100%; object-fit: cover; display: block; }
    h1 { text-align: center; font-size: 24px; margin-bottom: 8px; }
    .subtitle { text-align: center; color: #d8cbe5; font-size: 14px; margin-bottom: 26px; }
    label { display: block; margin-bottom: 8px; font-size: 14px; color: #eee; }
    .input-group { margin-bottom: 20px; }
    input {
        width: 100%; padding: 14px 15px; border-radius: 12px; font-size: 15px; outline: none;
        border: 1px solid rgba(255,255,255,0.15); background: rgba(0,0,0,0.25); color: #fff;
    }
    input:focus { border-color: #c026d3; box-shadow: 0 0 0 3px rgba(192,38,211,0.15); }
    .hint { color: #777; font-size: 12px; margin-top: 6px; }
    .btn {
        width: 100%; padding: 14px; border: none; border-radius: 12px; color: #fff;
        font-size: 16px; font-weight: bold; cursor: pointer;
        background: linear-gradient(135deg, #9333ea, #ec4899);
    }
    .msg { padding: 12px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; text-align: center; }
    .ok  { background: rgba(34,197,94,0.15); border: 1px solid rgba(34,197,94,0.35); color: #86efac; }
    .err { background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.35); color: #ffb4b4; }
    .back { display: block; text-align: center; margin-top: 22px; color: #f0abfc; font-weight: bold; text-decoration: none; }
    .back:hover { text-decoration: underline; }
</style>
</head>
<body>
<div class="card">

    <div class="logo"><img src="backgroundcc.jpg" alt="CCSJDM Logo"></div>
    <h1>Reset Password</h1>
    <p class="subtitle">City College of San Jose Del Monte</p>

    <?php if ($success): ?>
        <div class="msg ok">Matagumpay na napalitan ang password. Maaari ka nang mag-login.</div>
        <a href="index.php" class="back">Go to Login</a>

    <?php elseif (!$reset): ?>
        <div class="msg err">Invalid o expired na reset link.</div>
        <a href="forgot_password.php" class="back">Request a new link</a>

    <?php else: ?>
        <?php if ($error !== ""): ?>
            <div class="msg err"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="reset_password.php">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

            <div class="input-group">
                <label for="password">New Password</label>
                <input type="password" id="password" name="password" placeholder="Enter new password" required>
                <div class="hint">Dapat hindi bababa sa 8 characters.</div>
            </div>

            <div class="input-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" placeholder="Repeat new password" required>
            </div>

            <button type="submit" class="btn">Update Password</button>
        </form>

        <a href="index.php" class="back">Back to Login</a>
    <?php endif; ?>

</div>
</body>
</html>