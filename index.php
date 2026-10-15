<?php
session_start();

/* ================================
   CONFIG
================================ */
const DB_HOST = "localhost";
const DB_USER = "root";
const DB_PASS = "";
const DB_NAME = "ccsjdm_system";

/* ================================
   HELPERS
================================ */
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function writeAuditLog(mysqli $conn, string $username, string $action): void {
    $log = $conn->prepare("INSERT INTO audit_logs (username, action) VALUES (?, ?)");
    if ($log) {
        $log->bind_param("ss", $username, $action);
        $log->execute();
        $log->close();
    }
}

/* ================================
   DATABASE CONNECTION
================================ */
$conn = null;
$dbError = false;

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $ex) {
    error_log("DB connection failed: " . $ex->getMessage());
    $dbError = true;
}

/* ================================
   IF ALREADY LOGGED IN
================================ */
if (!empty($_SESSION["logged_in"]) && $_SESSION["logged_in"] === true) {
    header("Location: main.php");
    exit;
}

/* ================================
   CSRF TOKEN
================================ */
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

/* ================================
   LOGIN PROCESS
================================ */
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) {
        $error = "Invalid request. Please refresh the page and try again.";

    } elseif ($dbError) {
        $error = "Service temporarily unavailable. Please try again later.";

    } else {

        $student_id = trim($_POST["student_id"] ?? "");
        $password   = $_POST["password"] ?? "";

        if ($student_id === "" || $password === "") {
            $error = "Please enter your ID and password.";

        } else {

            $stmt = $conn->prepare(
                "SELECT id, full_name, student_id, password_hash, role
                 FROM users
                 WHERE student_id = ?
                 LIMIT 1"
            );

            if (!$stmt) {
                error_log("Prepare failed (login): " . $conn->error);
                $error = "Something went wrong. Please try again.";

            } else {

                $stmt->bind_param("s", $student_id);
                $stmt->execute();
                $result = $stmt->get_result();

                $userData = ($result && $result->num_rows === 1)
                    ? $result->fetch_assoc()
                    : null;

                if ($userData && password_verify($password, $userData["password_hash"])) {

                    // Prevent session fixation
                    session_regenerate_id(true);

                    $_SESSION["logged_in"] = true;
                    $_SESSION["user_id"]   = (int)$userData["id"];
                    $_SESSION["username"]  = $userData["student_id"];
                    $_SESSION["full_name"] = $userData["full_name"];
                    $_SESSION["role"]      = $userData["role"] ?? "viewer";

                    // Bagong CSRF token pagkatapos ng login
                    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

                    writeAuditLog($conn, $userData["student_id"], "User logged in");

                    $stmt->close();
                    header("Location: main.php");
                    exit;

                } else {
                    writeAuditLog($conn, $student_id, "Failed login attempt");
                    $error = "Incorrect ID or password.";
                }

                $stmt->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>CCSJDM | Login</title>

    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        font-family: Arial, Helvetica, sans-serif;
    }

    body {
        min-height: 100vh;
        display: flex;
        justify-content: center;
        align-items: center;
        background: #ffffff;
        color: #1f1f1f;
        padding: 20px;
        overflow-x: hidden;
    }

    .login-container {
        position: relative;
        width: 100%;
        max-width: 430px;
    }

    .login-card {
        position: relative;
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 24px;
        padding: 40px 35px;
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.08);
        animation: fadeIn 0.6s ease;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(20px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    /* Facebook button (kaliwang itaas) */
    .fb-corner {
        position: absolute;
        top: 14px;
        left: 14px;
        z-index: 2;
        width: 38px;
        height: 38px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background: rgba(24, 119, 242, .08);
        border: 1px solid rgba(24, 119, 242, .25);
        color: #1877f2;
        transition: 0.2s;
    }

    .fb-corner:hover {
        color: #ffffff;
        background: #1877f2;
        border-color: #1877f2;
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(24, 119, 242, .35);
    }

    .fb-corner svg {
        width: 18px;
        height: 18px;
        fill: currentColor;
    }

    .logo {
        width: 100px;
        height: 100px;
        margin: 0 auto 20px;
        border-radius: 50%;
        background: #ffffff;
        overflow: hidden;
        box-shadow: 0 10px 30px rgba(147, 51, 234, 0.25);
    }

    .logo img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    h1 {
        text-align: center;
        font-size: 27px;
        margin-bottom: 8px;
        color: #1f1f1f;
    }

    .subtitle {
        text-align: center;
        color: #6b7280;
        font-size: 14px;
        margin-bottom: 30px;
    }

    .input-group {
        margin-bottom: 20px;
    }

    label {
        display: block;
        margin-bottom: 8px;
        font-size: 14px;
        color: #374151;
    }

    .input-wrapper {
        position: relative;
    }

    input {
        width: 100%;
        padding: 14px 45px 14px 15px;
        border-radius: 12px;
        border: 1px solid #d1d5db;
        background: #f9fafb;
        color: #111827;
        outline: none;
        font-size: 15px;
        transition: 0.3s;
    }

    input::placeholder {
        color: #9ca3af;
    }

    input:focus {
        border-color: #c026d3;
        box-shadow: 0 0 0 3px rgba(192, 38, 211, 0.15);
    }

    .show-password {
        position: absolute;
        right: 13px;
        top: 50%;
        transform: translateY(-50%);
        cursor: pointer;
        color: #6b7280;
        font-size: 13px;
        user-select: none;
    }

    .show-password:hover {
        color: #111827;
    }

    .error {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #b91c1c;
        padding: 12px;
        border-radius: 10px;
        margin-bottom: 20px;
        font-size: 14px;
        text-align: center;
    }

    .login-btn {
        width: 100%;
        padding: 14px;
        border: none;
        border-radius: 12px;
        background: linear-gradient(135deg, #0f0f10, #090909);
        color: #ffffff;
        font-size: 16px;
        font-weight: bold;
        cursor: pointer;
        transition: 0.3s;
        box-shadow: 0 8px 25px rgba(147, 51, 234, 0.25);
    }

    .login-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 30px rgba(236, 72, 153, 0.3);
    }

    .links-row {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 10px;
        margin-top: 18px;
        font-size: 14px;
    }

    .forgot-link {
        color: #6b7280;
        text-decoration: none;
        transition: 0.3s;
    }

    .forgot-link:hover {
        color: #0e0d0f;
        text-decoration: underline;
    }

    .signup-link {
        color: #0c0b0c;
        font-weight: bold;
        text-decoration: none;
        transition: 0.3s;
    }

    .signup-link:hover {
        color: #0a090b;
        text-decoration: underline;
    }

    .signup-area {
        text-align: center;
        margin-top: 25px;
        padding-top: 22px;
        border-top: 1px solid #e5e7eb;
        color: #6b7280;
        font-size: 14px;
    }

    .developer {
        margin-top: 12px;
        font-size: 13px;
        color: #6b7280;
    }

    .footer {
        text-align: center;
        margin-top: 20px;
        font-size: 12px;
        color: #9ca3af;
    }

    @media (max-width: 480px) {
        .login-card {
            padding: 30px 22px;
        }

        h1 {
            font-size: 23px;
        }
    }
    </style>

</head>

<body>

<div class="login-container">

    <div class="login-card">

        <!-- FACEBOOK BUTTON -->
        <a href="https://www.facebook.com/HaroldCachoAnimator"
           class="fb-corner"
           target="_blank"
           rel="noopener noreferrer"
           title="Facebook Profile">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.09 10.13 24v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.69 4.53-4.69 1.31 0 2.68.24 2.68.24v2.97h-1.51c-1.49 0-1.96.93-1.96 1.88v2.26h3.33l-.53 3.49h-2.8V24C19.61 23.09 24 18.1 24 12.07z"/>
            </svg>
        </a>

        <div class="logo">
            <img src="backgroundcc.jpg" alt="CCSJDM Logo">
        </div>

        <h1>CCSJDM</h1>

        <p class="subtitle">
            Scheduling & Room Plotting
        </p>

        <?php if ($error !== ""): ?>
            <div class="error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="index.php">

            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

            <div class="input-group">
                <label for="student_id">Professor ID</label>
                <input
                    type="text"
                    id="student_id"
                    name="student_id"
                    placeholder="Enter your ID"
                    maxlength="50"
                    required
                    autocomplete="username"
                    value="<?= e($_POST['student_id'] ?? '') ?>"
                >
            </div>

            <div class="input-group">
                <label for="password">Password</label>
                <div class="input-wrapper">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        required
                        autocomplete="current-password"
                    >
                    <span class="show-password" id="toggleText" role="button" tabindex="0">Show</span>
                </div>
            </div>

            <button type="submit" class="login-btn">Login</button>
        </form>

        <!-- FORGOT PASSWORD + SIGN UP -->
        <div class="links-row">
            <a href="forgot_password.php" class="forgot-link">Forget Password?</a>

            <div class="developer">Dont have an Account?</div>

            <a href="signup.php" class="signup-link">Sign up</a>
        </div>

        <!-- FOOTER AREA -->
        <div class="signup-area">
            <div class="developer">Developer: Harold Cacho</div>
        </div>

    </div>

    <div class="footer">
        City College of San Jose Del Monte
    </div>

</div>

<script>
    const passwordInput = document.getElementById("password");
    const toggleText    = document.getElementById("toggleText");

    function togglePassword() {
        const isHidden = passwordInput.type === "password";
        passwordInput.type = isHidden ? "text" : "password";
        toggleText.textContent = isHidden ? "Hide" : "Show";
    }

    toggleText.addEventListener("click", togglePassword);
    toggleText.addEventListener("keydown", function (event) {
        if (event.key === "Enter" || event.key === " ") {
            event.preventDefault();
            togglePassword();
        }
    });
</script>

</body>
</html>