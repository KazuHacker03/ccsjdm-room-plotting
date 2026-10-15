<?php
session_start();

/*
|--------------------------------------------------------------------------
| CCSJDM SYSTEM - SIGN UP
|--------------------------------------------------------------------------
| Creates users in: ccsjdm_system.users
| Fields: full_name, student_id, password_hash, role, requested_role
|--------------------------------------------------------------------------
*/

const DB_HOST = "localhost";
const DB_USER = "root";
const DB_PASS = "";
const DB_NAME = "ccsjdm_system";

const FB_URL  = "https://www.facebook.com/HaroldCachoAnimator";
const LOGO    = "backgroundcc.jpg";

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
   CSRF TOKEN
================================ */
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$error       = "";
$success     = "";
$full_name   = "";
$student_id  = "";
$access_type = "viewer";

/* ================================
   SIGN UP PROCESS
================================ */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $postedToken = $_POST["csrf_token"] ?? "";

    // Lahat ng bagong account ay viewer muna. Editor ay request lang na kailangan i-approve ng admin.
    $access_type = (($_POST["access_type"] ?? "viewer") === "editor") ? "editor" : "viewer";

    if (!hash_equals($_SESSION["csrf_token"], $postedToken)) {
        $error = "Invalid request. Please refresh the page and try again.";

    } elseif ($dbError) {
        $error = "Service temporarily unavailable. Please try again later.";

    } else {

        $full_name        = trim($_POST["full_name"] ?? "");
        $student_id       = trim($_POST["student_id"] ?? "");
        $password         = $_POST["password"] ?? "";
        $confirm_password = $_POST["confirm_password"] ?? "";

        if ($full_name === "" || $student_id === "" || $password === "" || $confirm_password === "") {
            $error = "Please complete all fields.";

        } elseif (mb_strlen($full_name) > 100) {
            $error = "Full name is too long.";

        } elseif (mb_strlen($student_id) > 50) {
            $error = "Professor/Student ID is too long.";

        } elseif (strlen($password) < 8) {
            $error = "Password must contain at least 8 characters.";

        } elseif ($password !== $confirm_password) {
            $error = "Passwords do not match.";

        } else {

            $check = $conn->prepare("SELECT id FROM users WHERE student_id = ? LIMIT 1");

            if (!$check) {
                error_log("Prepare failed (check): " . $conn->error);
                $error = "Unable to process the registration.";

            } else {

                $check->bind_param("s", $student_id);
                $check->execute();
                $check->store_result();
                $exists = $check->num_rows > 0;
                $check->close();

                if ($exists) {
                    $error = "That Professor/Student ID is already registered.";

                } else {

                    $password_hash = password_hash($password, PASSWORD_DEFAULT);

                    $stmt = $conn->prepare(
                        "INSERT INTO users (full_name, student_id, password_hash, role, requested_role)
                         VALUES (?, ?, ?, 'viewer', ?)"
                    );

                    if (!$stmt) {
                        error_log("Prepare failed (insert): " . $conn->error);
                        $error = "Unable to create the account.";

                    } else {

                        try {
                            $stmt->bind_param("ssss", $full_name, $student_id, $password_hash, $access_type);
                            $stmt->execute();

                            $action = ($access_type === "editor")
                                ? "Requested editor access"
                                : "Created a new user account";
                            writeAuditLog($conn, $student_id, $action);

                            $success = ($access_type === "editor")
                                ? "Account created! Naipadala na ang Editor request. Hintayin ang approval ng admin."
                                : "Account created successfully! You can now log in.";

                            $full_name   = "";
                            $student_id  = "";
                            $access_type = "viewer";

                        } catch (mysqli_sql_exception $ex) {
                            if ($ex->getCode() === 1062) {
                                $error = "That Professor/Student ID is already registered.";
                            } else {
                                error_log("Insert failed: " . $ex->getMessage());
                                $error = "Unable to create the account.";
                            }
                        }

                        $stmt->close();
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign Up | CCSJDM System</title>

<style>
* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
    font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
}

:root {
    --purple: #7011ce;
    --purple2: #8b12f6;
    --pink: #d946ef;
    --text: #1f1f1f;
    --muted: #6b7280;
    --border: #e5e7eb;
    --input-bg: #f9fafb;
}

body {
    min-height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 25px 0;
    color: var(--text);
    background: #ffffff;
}

.signup-wrapper {
    width: min(92%, 450px);
}

.signup-card {
    position: relative;
    padding: 35px;
    border: 1px solid var(--border);
    border-radius: 20px;
    background: #ffffff;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.08);
}

.fb-corner {
    position: absolute;
    top: 14px;
    left: 14px;
    width: 36px;
    height: 36px;
    display: flex;
    justify-content: center;
    align-items: center;
    border-radius: 50%;
    background: rgba(24, 119, 242, .08);
    border: 1px solid rgba(24, 119, 242, .25);
    color: #1877f2;
    transition: .2s;
    z-index: 2;
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

.logo-img {
    display: flex;
    justify-content: center;
    align-items: center;
    margin: 0 auto 16px;
    width: 100%;
}

.logo-img img {
    display: block;
    width: 100px;
    height: 100px;
    object-fit: cover;
    border-radius: 50%;
    box-shadow: 0 10px 30px rgba(147, 51, 234, 0.2);
}

.title {
    text-align: center;
    font-size: 1.75rem;
    font-weight: 800;
    background: linear-gradient(90deg, #d946ef, #8b5cf6, #3b82f6);
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
}

.subtitle {
    margin: 8px 0 25px;
    color: var(--muted);
    text-align: center;
    font-size: .88rem;
}

.message {
    margin-bottom: 18px;
    padding: 11px 13px;
    border-radius: 8px;
    font-size: .87rem;
    text-align: center;
}

.error {
    border: 1px solid #fecaca;
    color: #b91c1c;
    background: #fef2f2;
}

.success {
    border: 1px solid #bbf7d0;
    color: #15803d;
    background: #f0fdf4;
}

.form-group {
    margin-bottom: 17px;
}

label {
    display: block;
    margin-bottom: 7px;
    color: #374151;
    font-size: .86rem;
    font-weight: 600;
}

input {
    width: 100%;
    padding: 12px 13px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    outline: none;
    color: #111827;
    background: var(--input-bg);
    font-size: 15px;
    transition: .2s;
}

input::placeholder {
    color: #9ca3af;
}

input:focus {
    border-color: var(--pink);
    box-shadow: 0 0 0 3px rgba(217, 70, 239, .15);
}

.password-box {
    position: relative;
}

.password-box input {
    padding-right: 65px;
}

.show-password {
    position: absolute;
    top: 50%;
    right: 8px;
    transform: translateY(-50%);
    padding: 5px;
    border: 0;
    color: var(--muted);
    background: transparent;
    cursor: pointer;
    font-size: 13px;
}

.show-password:hover {
    color: #111827;
}

.password-note {
    margin-top: 6px;
    color: var(--muted);
    font-size: .72rem;
}

/* Access type (View Only / Editor) */
.access-options {
    display: flex;
    gap: 10px;
}

.access-option {
    flex: 1;
    text-align: center;
    padding: 11px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    color: #374151;
    background: var(--input-bg);
    transition: .2s;
}

.access-option input {
    display: none;
}

.access-option:has(input:checked) {
    border-color: var(--purple);
    background: #f5f3ff;
    color: var(--purple);
}

.signup-btn {
    width: 100%;
    margin-top: 4px;
    padding: 13px;
    border: 0;
    border-radius: 9px;
    color: #ffffff;
    background: linear-gradient(135deg, var(--purple), var(--purple2));
    font-weight: 700;
    font-size: 16px;
    cursor: pointer;
    transition: .2s;
}

.signup-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px rgba(217, 70, 239, .25);
}

.login-link {
    margin-top: 20px;
    padding-top: 18px;
    border-top: 1px solid var(--border);
    text-align: center;
    color: var(--muted);
    font-size: .85rem;
}

.login-link a {
    color: var(--purple);
    text-decoration: none;
    font-weight: 700;
}

.login-link a:hover {
    color: var(--pink);
    text-decoration: underline;
}

@media (max-width: 480px) {
    .signup-card {
        padding: 27px 21px;
    }
}
</style>
</head>

<body>

<main class="signup-wrapper">
    <section class="signup-card">

        <a href="<?= e(FB_URL) ?>"
           class="fb-corner"
           target="_blank"
           rel="noopener noreferrer"
           title="Facebook Profile">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.09 10.13 24v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.69 4.53-4.69 1.31 0 2.68.24 2.68.24v2.97h-1.51c-1.49 0-1.96.93-1.96 1.88v2.26h3.33l-.53 3.49h-2.8V24C19.61 23.09 24 18.1 24 12.07z"/>
            </svg>
        </a>

        <div class="logo-img">
            <img src="<?= e(LOGO) ?>" alt="CCSJDM Logo" width="100" height="100">
        </div>

        <h1 class="title">Create Account</h1>

        <p class="subtitle">City College of San Jose Del Monte</p>

        <?php if ($error !== ""): ?>
            <div class="message error"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($success !== ""): ?>
            <div class="message success"><?= e($success) ?></div>
        <?php endif; ?>

        <form method="POST" action="signup.php" autocomplete="on">

            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

            <div class="form-group">
                <label for="full_name">Full Name</label>
                <input
                    type="text"
                    id="full_name"
                    name="full_name"
                    placeholder="Enter your full name"
                    maxlength="100"
                    autocomplete="name"
                    value="<?= e($full_name) ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label for="student_id">Professor ID</label>
                <input
                    type="text"
                    id="student_id"
                    name="student_id"
                    placeholder="Enter your ID"
                    maxlength="50"
                    autocomplete="username"
                    value="<?= e($student_id) ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label>Access Type</label>
                <div class="access-options">
                    <label class="access-option">
                        <input type="radio" name="access_type" value="viewer"
                            <?= $access_type === "viewer" ? "checked" : "" ?>>
                        View Only
                    </label>
                    <label class="access-option">
                        <input type="radio" name="access_type" value="editor"
                            <?= $access_type === "editor" ? "checked" : "" ?>>
                        Editor
                    </label>
                </div>
                <div class="password-note">Ang Editor access ay kailangang i-approve ng admin.</div>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="password-box">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Create a password"
                        autocomplete="new-password"
                        required
                    >
                    <button type="button" class="show-password" data-target="password">Show</button>
                </div>
                <div class="password-note">Password must contain at least 8 characters.</div>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <div class="password-box">
                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        placeholder="Repeat your password"
                        autocomplete="new-password"
                        required
                    >
                    <button type="button" class="show-password" data-target="confirm_password">Show</button>
                </div>
            </div>

            <button type="submit" class="signup-btn">Create Account</button>
        </form>

        <div class="login-link">
            Already have an account?
            <a href="index.php">Login here</a>
        </div>

    </section>
</main>

<script>
document.querySelectorAll(".show-password").forEach(function (button) {
    button.addEventListener("click", function () {
        const input = document.getElementById(button.dataset.target);
        const isHidden = input.type === "password";
        input.type = isHidden ? "text" : "password";
        button.textContent = isHidden ? "Hide" : "Show";
    });
});
</script>

</body>
</html>