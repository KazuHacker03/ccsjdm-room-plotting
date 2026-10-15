<?php
session_start();

if (empty($_SESSION["logged_in"])) {
    header("Location: index.php");
    exit;
}

const DB_HOST = "localhost";
const DB_USER = "root";
const DB_PASS = "";
const DB_NAME = "ccsjdm_system";

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

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

/* ================================
   DATABASE CONNECTION
================================ */
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $ex) {
    error_log("DB connection failed: " . $ex->getMessage());
    die("Service temporarily unavailable. Please try again later.");
}

/* ================================
   ADMIN CHECK (kuha ang latest role sa database)
================================ */
$uid = (int)($_SESSION["user_id"] ?? 0);

$stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $uid);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();

$_SESSION["role"] = $me["role"] ?? "viewer";

if ($_SESSION["role"] !== "admin") {
    http_response_code(403);
    die("Access denied. Admin lang ang makakapasok dito.");
}

$admin_name = $_SESSION["username"] ?? "admin";

/* ================================
   POST ACTIONS (approve / reject / revoke)
================================ */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) {
        $_SESSION["flash"] = ["error", "Invalid request. Subukan ulit."];

    } else {

        $target = (int)($_POST["user_id"] ?? 0);
        $action = $_POST["action"] ?? "";

        if ($target > 0 && $action === "approve") {
            $stmt = $conn->prepare(
                "UPDATE users
                 SET role = 'editor', requested_role = 'editor'
                 WHERE id = ? AND role = 'viewer' AND requested_role = 'editor'"
            );
            $stmt->bind_param("i", $target);
            $stmt->execute();
            $n = $stmt->affected_rows;
            $stmt->close();

            if ($n > 0) {
                writeAuditLog($conn, $admin_name, "Approved editor access for user ID: $target");
                $_SESSION["flash"] = ["success", "Na-approve bilang Editor."];
            } else {
                $_SESSION["flash"] = ["error", "Hindi na-approve. Baka na-process na ang request."];
            }

        } elseif ($target > 0 && $action === "reject") {
            $stmt = $conn->prepare(
                "UPDATE users
                 SET requested_role = 'viewer'
                 WHERE id = ? AND role = 'viewer' AND requested_role = 'editor'"
            );
            $stmt->bind_param("i", $target);
            $stmt->execute();
            $n = $stmt->affected_rows;
            $stmt->close();

            if ($n > 0) {
                writeAuditLog($conn, $admin_name, "Rejected editor request for user ID: $target");
                $_SESSION["flash"] = ["success", "Na-reject ang request. Mananatiling Viewer."];
            } else {
                $_SESSION["flash"] = ["error", "Hindi na-reject. Baka na-process na ang request."];
            }

        } elseif ($target > 0 && $action === "revoke") {
            $stmt = $conn->prepare(
                "UPDATE users
                 SET role = 'viewer', requested_role = 'viewer'
                 WHERE id = ? AND role = 'editor'"
            );
            $stmt->bind_param("i", $target);
            $stmt->execute();
            $n = $stmt->affected_rows;
            $stmt->close();

            if ($n > 0) {
                writeAuditLog($conn, $admin_name, "Revoked editor access for user ID: $target");
                $_SESSION["flash"] = ["success", "Na-revoke ang Editor access. Viewer na ulit."];
            } else {
                $_SESSION["flash"] = ["error", "Hindi ma-revoke ang user na ito."];
            }
        }
    }

    header("Location: approvals.php");
    exit;
}

/* ================================
   FETCH DATA
================================ */
$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);

$pending = $conn->query(
    "SELECT id, full_name, student_id
     FROM users
     WHERE requested_role = 'editor' AND role = 'viewer'
     ORDER BY id DESC"
)->fetch_all(MYSQLI_ASSOC);

$editors = $conn->query(
    "SELECT id, full_name, student_id
     FROM users
     WHERE role = 'editor'
     ORDER BY full_name ASC"
)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Approvals | CCSJDM</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; font-family: "Segoe UI", Tahoma, Arial, sans-serif; }
body { background: #ffffff; color: #1f1f1f; padding: 30px 20px; }
.container { max-width: 900px; margin: 0 auto; }
.top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 10px; }
h1 { font-size: 1.6rem; color: #7011ce; }
h2 { font-size: 1.15rem; margin-bottom: 14px; color: #374151; }
.back { color: #7011ce; text-decoration: none; font-weight: 700; }
.back:hover { text-decoration: underline; }
.card { border: 1px solid #e5e7eb; border-radius: 14px; padding: 22px; margin-bottom: 24px; box-shadow: 0 10px 30px rgba(0,0,0,.05); }
.msg { padding: 11px 13px; border-radius: 8px; margin-bottom: 18px; font-size: .9rem; }
.msg.error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
.msg.success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
table { width: 100%; border-collapse: collapse; font-size: .92rem; }
th, td { text-align: left; padding: 11px 10px; border-bottom: 1px solid #e5e7eb; }
th { color: #374151; background: #f9fafb; }
.empty { color: #6b7280; text-align: center; padding: 20px; }
.actions { display: flex; gap: 8px; }
button { padding: 8px 14px; border: 0; border-radius: 7px; color: #fff; font-weight: 700; cursor: pointer; font-size: .85rem; }
.btn-approve { background: #16a34a; }
.btn-reject { background: #ef4444; }
.btn-revoke { background: #6b7280; }
.badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: .75rem; font-weight: 700; background: #fef3c7; color: #92400e; }
</style>
</head>
<body>
<div class="container">

    <div class="top">
        <h1>Editor Approvals</h1>
        <a class="back" href="main.php">&larr; Bumalik sa Dashboard</a>
    </div>

    <?php if ($flash): ?>
        <div class="msg <?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
    <?php endif; ?>

    <div class="card">
        <h2>Pending Requests <span class="badge"><?= count($pending) ?></span></h2>
        <table>
            <thead>
                <tr>
                    <th>Pangalan</th>
                    <th>Professor ID</th>
                    <th>Aksyon</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($pending)): ?>
                <tr><td colspan="3" class="empty">Walang pending na request.</td></tr>
            <?php else: foreach ($pending as $p): ?>
                <tr>
                    <td><?= e($p["full_name"]) ?></td>
                    <td><?= e($p["student_id"]) ?></td>
                    <td>
                        <div class="actions">
                            <form method="POST" action="approvals.php">
                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="user_id" value="<?= (int)$p["id"] ?>">
                                <input type="hidden" name="action" value="approve">
                                <button type="submit" class="btn-approve">Approve</button>
                            </form>
                            <form method="POST" action="approvals.php"
                                  onsubmit="return confirm('I-reject ang request na ito?');">
                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="user_id" value="<?= (int)$p["id"] ?>">
                                <input type="hidden" name="action" value="reject">
                                <button type="submit" class="btn-reject">Reject</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Kasalukuyang Editors <span class="badge"><?= count($editors) ?></span></h2>
        <table>
            <thead>
                <tr>
                    <th>Pangalan</th>
                    <th>Professor ID</th>
                    <th>Aksyon</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($editors)): ?>
                <tr><td colspan="3" class="empty">Wala pang editor.</td></tr>
            <?php else: foreach ($editors as $ed): ?>
                <tr>
                    <td><?= e($ed["full_name"]) ?></td>
                    <td><?= e($ed["student_id"]) ?></td>
                    <td>
                        <form method="POST" action="approvals.php"
                              onsubmit="return confirm('Bawiin ang Editor access? Magiging Viewer na ulit.');">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="user_id" value="<?= (int)$ed["id"] ?>">
                            <input type="hidden" name="action" value="revoke">
                            <button type="submit" class="btn-revoke">Revoke</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

</div>
</body>
</html>