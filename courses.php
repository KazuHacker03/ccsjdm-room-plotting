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

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$uid = (int)($_SESSION["user_id"] ?? 0);

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
   REFRESH ROLE MULA SA DATABASE
   (para agad gumana ang approval nang hindi nag-lo-logout)
================================ */
if (!$dbError) {
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $fresh = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($fresh) {
        $_SESSION["role"] = $fresh["role"];
    }
}

$role     = $_SESSION["role"] ?? "viewer";
$can_edit = in_array($role, ["admin", "editor"], true);

$msg = "";
$msg_type = "";

if (!$dbError && $_SERVER["REQUEST_METHOD"] === "POST") {

    if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) {
        $msg = "Invalid request. Please refresh the page.";
        $msg_type = "error";

    } elseif (!$can_edit) {
        http_response_code(403);
        $msg = "Access denied. View only ang account mo.";
        $msg_type = "error";

    } else {
        $action = $_POST["action"] ?? "";

        if ($action === "add") {
            $code    = trim($_POST["course_code"] ?? "");
            $name    = trim($_POST["course_name"] ?? "");
            $section = trim($_POST["section"] ?? "");

            if ($code === "" || $name === "") {
                $msg = "Kailangan ang course code at course name.";
                $msg_type = "error";
            } elseif (mb_strlen($code) > 20 || mb_strlen($name) > 150 || mb_strlen($section) > 20) {
                $msg = "Masyadong mahaba ang input.";
                $msg_type = "error";
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO courses (course_code, course_name, section, professor_id)
                     VALUES (?, ?, ?, ?)"
                );
                $stmt->bind_param("sssi", $code, $name, $section, $uid);
                $stmt->execute();
                $stmt->close();
                $msg = "Course added.";
                $msg_type = "success";
            }

        } elseif ($action === "delete") {
            $course_id = (int)($_POST["course_id"] ?? 0);

            if ($role === "admin") {
                $stmt = $conn->prepare("DELETE FROM courses WHERE id = ?");
                $stmt->bind_param("i", $course_id);
            } else {
                $stmt = $conn->prepare("DELETE FROM courses WHERE id = ? AND professor_id = ?");
                $stmt->bind_param("ii", $course_id, $uid);
            }
            $stmt->execute();
            $deleted = $stmt->affected_rows > 0;
            $stmt->close();

            $msg = $deleted ? "Course deleted." : "Hindi mo ma-delete ang course na ito.";
            $msg_type = $deleted ? "success" : "error";
        }
    }
}

$courses = [];
if (!$dbError) {
    $result = $conn->query(
        "SELECT c.id, c.course_code, c.course_name, c.section, c.professor_id, u.full_name
         FROM courses c
         JOIN users u ON u.id = c.professor_id
         ORDER BY c.created_at DESC"
    );
    while ($row = $result->fetch_assoc()) {
        $courses[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Courses | CCSJDM</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; font-family: "Segoe UI", Tahoma, Arial, sans-serif; }
body { background: #ffffff; color: #1f1f1f; padding: 30px 20px; }
.container { max-width: 900px; margin: 0 auto; }
h1 { font-size: 1.6rem; color: #7011ce; margin-bottom: 4px; }
.meta { color: #6b7280; font-size: .9rem; margin-bottom: 24px; }
.badge { display: inline-block; padding: 2px 10px; border-radius: 999px; background: #f5f3ff; color: #7011ce; font-size: .8rem; font-weight: 700; }
.msg { padding: 11px 13px; border-radius: 8px; margin-bottom: 18px; font-size: .9rem; }
.msg.error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
.msg.success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
.card { border: 1px solid #e5e7eb; border-radius: 14px; padding: 22px; margin-bottom: 24px; box-shadow: 0 10px 30px rgba(0,0,0,.05); }
.form-row { display: grid; grid-template-columns: 1fr 2fr 1fr auto; gap: 10px; }
input { padding: 11px 12px; border: 1px solid #d1d5db; border-radius: 8px; background: #f9fafb; font-size: 14px; width: 100%; }
button { padding: 11px 16px; border: 0; border-radius: 8px; color: #fff; background: linear-gradient(135deg, #7011ce, #8b12f6); font-weight: 700; cursor: pointer; }
.btn-delete { background: #ef4444; padding: 6px 12px; font-size: 13px; }
table { width: 100%; border-collapse: collapse; font-size: .92rem; }
th, td { text-align: left; padding: 11px 10px; border-bottom: 1px solid #e5e7eb; }
th { color: #374151; background: #f9fafb; }
.empty { color: #6b7280; text-align: center; padding: 20px; }
.view-only { color: #6b7280; font-style: italic; }
@media (max-width: 640px) { .form-row { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="container">

    <h1>Courses</h1>
    <div class="meta">
        Logged in: <strong><?= e($_SESSION["full_name"] ?? "") ?></strong>
        &nbsp;
        <span class="badge"><?= e(ucfirst($role)) ?></span>
    </div>

    <?php if ($dbError): ?>
        <div class="msg error">Hindi makakonekta sa database. Subukan ulit mamaya.</div>
    <?php endif; ?>

    <?php if ($msg !== ""): ?>
        <div class="msg <?= e($msg_type) ?>"><?= e($msg) ?></div>
    <?php endif; ?>

    <?php if ($can_edit): ?>
    <div class="card">
        <h3 style="margin-bottom:14px;">Add Course</h3>
        <form method="POST" action="courses.php">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="add">
            <div class="form-row">
                <input type="text" name="course_code" placeholder="Course Code (e.g. IT101)" maxlength="20" required>
                <input type="text" name="course_name" placeholder="Course Name" maxlength="150" required>
                <input type="text" name="section" placeholder="Section" maxlength="20">
                <button type="submit">Add</button>
            </div>
        </form>
    </div>
    <?php else: ?>
        <p class="view-only" style="margin-bottom:20px;">View only ang access mo. Hintayin ang approval ng admin para makapag-edit.</p>
    <?php endif; ?>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Course Name</th>
                    <th>Section</th>
                    <th>Professor</th>
                    <?php if ($can_edit): ?><th></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($courses)): ?>
                <tr><td colspan="5" class="empty">Wala pang courses.</td></tr>
            <?php else: foreach ($courses as $c): ?>
                <tr>
                    <td><?= e($c["course_code"]) ?></td>
                    <td><?= e($c["course_name"]) ?></td>
                    <td><?= e($c["section"]) ?></td>
                    <td><?= e($c["full_name"]) ?></td>
                    <?php if ($can_edit && ($role === "admin" || (int)$c["professor_id"] === $uid)): ?>
                    <td>
                        <form method="POST" action="courses.php" onsubmit="return confirm('Idelete ang course na ito?');">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="course_id" value="<?= (int)$c['id'] ?>">
                            <button type="submit" class="btn-delete">Delete</button>
                        </form>
                    </td>
                    <?php elseif ($can_edit): ?>
                    <td></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

</div>
</body>
</html>