<?php
session_start();

require_once __DIR__ . "/vendor/autoload.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$conn = new mysqli("localhost", "root", "", "ccsjdm_system");
if ($conn->connect_error) {
    die("Database connection failed.");
}
$conn->set_charset("utf8mb4");

/* ---------- CONFIG ---------- */
define("BASE_URL", "http://localhost/CCSDJM%20ROOM");
define("SMTP_USER", "haroldcacho16@gmail.com");
define("SMTP_PASS", "puhlhrounbwagjwl"); // 16 characters, walang espasyo
define("SMTP_FROM_NAME", "CCSJDM System");
define("DEV_MODE", false);                              // Ilagay sa false kapag gumana na

/* ---------- SEND EMAIL ---------- */
function sendResetEmail($to, $link) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = "smtp.gmail.com";
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = "UTF-8";

        $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
        $mail->addAddress($to);

        $mail->Subject = "CCSJDM - Password Reset";
        $mail->Body    = "I-click ang link sa ibaba para i-reset ang password (valid for 30 minutes):\n\n"
                       . $link
                       . "\n\nKung hindi ikaw ang nag-request, balewalain ang email na ito.";

        $mail->send();
        return "";
    } catch (Exception $e) {
        return $mail->ErrorInfo;
    }
}

/* ---------- PROCESS ---------- */
$info     = "";
$error    = "";
$devLink  = "";
$devError = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $student_id = trim($_POST["student_id"] ?? "");
    $email      = trim($_POST["email"] ?? "");

    if ($student_id === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Ilagay ang tamang ID at email.";
    } else {

        $stmt = $conn->prepare(
            "SELECT student_id, email FROM users WHERE student_id = ? AND email = ? LIMIT 1"
        );
        $stmt->bind_param("ss", $student_id, $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user) {
            // Burahin ang lumang token ng user
            $del = $conn->prepare("DELETE FROM password_resets WHERE student_id = ?");
            $del->bind_param("s", $student_id);
            $del->execute();
            $del->close();

            // Gumawa ng bagong token
            $token     = bin2hex(random_bytes(32));
            $tokenHash = hash("sha256", $token);

            // Isang INSERT lang. Ang expiry ay kinukuha sa MySQL para tugma sa NOW()
            $ins = $conn->prepare(
                "INSERT INTO password_resets (student_id, token_hash, expires_at)
                 VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))"
            );
            $ins->bind_param("ss", $student_id, $tokenHash);

            if (!$ins->execute()) {
                die("INSERT error: " . $ins->error);
            }
            $ins->close();

            $resetLink = BASE_URL . "/reset_password.php?token=" . $token;
            $mailError = sendResetEmail($email, $resetLink);

            if (DEV_MODE) {
                $devLink  = $resetLink;
                $devError = ($mailError === "")
                    ? "Naipadala na ang email sa " . $email
                    : $mailError;
            }
        } elseif (DEV_MODE) {
            $devError = "Walang professor na tumugma sa ID at email sa database.";
        }

        // Parehong message kahit mali ang ID o email (ibalik sa normal kapag tapos na ang testing)
        $info = "Kung tama ang impormasyon, makakatanggap ka ng reset link sa iyong email.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password | CCSJDM</title>
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
    .btn {
        width: 100%; padding: 14px; border: none; border-radius: 12px; color: #fff;
        font-size: 16px; font-weight: bold; cursor: pointer;
        background: linear-gradient(135deg, #9333ea, #ec4899);
    }
    .msg { padding: 12px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; text-align: center; }
    .ok  { background: rgba(34,197,94,0.15); border: 1px solid rgba(34,197,94,0.35); color: #86efac; }
    .err { background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.35); color: #ffb4b4; }
    .dev { font-size: 12px; color: #fbbf24; word-break: break-all; margin-bottom: 20px; }
    .dev a { color: #fbbf24; }
    .back { display: block; text-align: center; margin-top: 22px; color: #f0abfc; font-weight: bold; text-decoration: none; }
    .back:hover { text-decoration: underline; }
</style>
</head>
<body>
<div class="card">

    <div class="logo"><img src="backgroundcc.jpg" alt="CCSJDM Logo"></div>
    <h1>Forgot Password</h1>
    <p class="subtitle">City College of San Jose Del Monte</p>

    <?php if ($error !== ""): ?>
        <div class="msg err"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($info !== ""): ?>
        <div class="msg ok"><?= htmlspecialchars($info) ?></div>

        <?php if ($devError !== ""): ?>
            <div class="dev">
                [DEV MODE] <?= htmlspecialchars($devError) ?><br><br>
                Reset link:<br>
                <a href="<?= htmlspecialchars($devLink) ?>"><?= htmlspecialchars($devLink) ?></a>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <form method="POST" action="forgot_password.php">
            <div class="input-group">
                <label for="student_id">Professor ID</label>
                <input type="text" id="student_id" name="student_id" placeholder="Enter your ID" maxlength="50" required>
            </div>
            <div class="input-group">
                <label for="email">Registered Email</label>
                <input type="email" id="email" name="email" placeholder="Enter your registered email" maxlength="150" required>
            </div>
            <button type="submit" class="btn">Send Reset Link</button>
        </form>
    <?php endif; ?>

    <a href="index.php" class="back">Back to Login</a>
</div>
</body>
</html>