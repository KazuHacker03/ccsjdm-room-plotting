<?php
session_start();

/*
|--------------------------------------------------------------------------
| CCSJDM SYSTEM - MAIN DASHBOARD
|--------------------------------------------------------------------------
| Roles:
|   admin  : full access + approvals
|   editor : puwedeng mag-edit ng schedules at rooms
|   viewer : view lang
|--------------------------------------------------------------------------
*/

if (empty($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true) {
    header("Location: index.php");
    exit;
}

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

function addAuditLog($conn, $username, $action) {
    $stmt = $conn->prepare("INSERT INTO audit_logs (username, action) VALUES (?, ?)");
    if ($stmt) {
        $stmt->bind_param("ss", $username, $action);
        $stmt->execute();
        $stmt->close();
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
   REFRESH ROLE MULA SA DATABASE
   (para agad makita ang approval nang hindi nag-lo-logout)
================================ */
$stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$uid  = (int)($_SESSION["user_id"] ?? 0);
$stmt->bind_param("i", $uid);
$stmt->execute();
$fresh = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($fresh) {
    $_SESSION["role"] = $fresh["role"];
}

$role     = $_SESSION["role"] ?? "viewer";
$can_edit = in_array($role, ["admin", "editor"], true);
$is_admin = ($role === "admin");
$username = $_SESSION["username"] ?? "User";

/* ================================
   AUTO SETUP FOR ROOM PLOTTING
================================ */
$conn->query(
    "CREATE TABLE IF NOT EXISTS rooms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        room_name VARCHAR(100) NOT NULL UNIQUE
    )"
);

$col = $conn->query("SHOW COLUMNS FROM schedules LIKE 'room_id'");
if ($col && $col->num_rows === 0) {
    $conn->query("ALTER TABLE schedules ADD COLUMN room_id INT NULL DEFAULT NULL");
}

$cnt = $conn->query("SELECT COUNT(*) AS c FROM rooms");
if ($cnt && (int)$cnt->fetch_assoc()["c"] === 0) {
    $conn->query(
        "INSERT INTO rooms (room_name) VALUES
         ('Sound Programming'), ('Room 102'), ('Lab 1'), ('Lab 2')"
    );
}

/* ================================
   POST ACTIONS
================================ */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";

    if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) {
        $_SESSION["flash_msg"] = "Invalid request. Please refresh the page and try again.";
        header("Location: main.php");
        exit;
    }

    /* Logout: allowed sa lahat ng role */
    if ($action === "logout") {
        addAuditLog($conn, $username, "User logged out");

        $_SESSION = [];

        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                "",
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }

        session_destroy();
        header("Location: index.php");
        exit;
    }

    /* Server-side lock: viewer hindi makakapag-edit kahit i-send ang request nang direkta */
    $write_actions = ["save_schedule", "delete_schedule", "add_room", "assign_room", "release_room", "trigger_backup"];

    if (in_array($action, $write_actions, true) && !$can_edit) {
        http_response_code(403);
        $_SESSION["flash_msg"] = "View only ang access mo. Hintayin ang approval ng admin para makapag-edit.";
        header("Location: main.php");
        exit;
    }

    /* Add/update schedule */
    if ($action === "save_schedule") {

        $id      = intval($_POST["sched_id"] ?? 0);
        $prof    = trim($_POST["prof"] ?? "");
        $subject = trim($_POST["subject"] ?? "");
        $time    = trim($_POST["time"] ?? "");
        $day     = trim($_POST["day"] ?? "");

        if ($prof !== "" && $subject !== "" && $time !== "" && $day !== "") {

            if ($id > 0) {
                $stmt = $conn->prepare(
                    "UPDATE schedules
                     SET prof = ?, subject = ?, time_slot = ?, day_slot = ?
                     WHERE id = ?"
                );
                $stmt->bind_param("ssssi", $prof, $subject, $time, $day, $id);
                $stmt->execute();
                $stmt->close();

                addAuditLog($conn, $username, "Updated schedule ID: $id ($subject)");

            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO schedules (prof, subject, time_slot, day_slot)
                     VALUES (?, ?, ?, ?)"
                );
                $stmt->bind_param("ssss", $prof, $subject, $time, $day);
                $stmt->execute();
                $stmt->close();

                addAuditLog($conn, $username, "Added schedule: $subject");
            }
        }

        header("Location: main.php");
        exit;
    }

    /* Delete schedule */
    if ($action === "delete_schedule") {

        $id = intval($_POST["sched_id"] ?? 0);

        if ($id > 0) {
            $stmt = $conn->prepare("DELETE FROM schedules WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();

            addAuditLog($conn, $username, "Deleted schedule ID: $id");
        }

        header("Location: main.php");
        exit;
    }

    /* Add a new room */
    if ($action === "add_room") {

        $room_name = trim($_POST["room_name"] ?? "");

        if ($room_name !== "") {
            $stmt = $conn->prepare("INSERT IGNORE INTO rooms (room_name) VALUES (?)");
            $stmt->bind_param("s", $room_name);
            $stmt->execute();
            $added = $stmt->affected_rows > 0;
            $stmt->close();

            if ($added) {
                addAuditLog($conn, $username, "Added room: $room_name");
                $_SESSION["room_msg"] = "Room '$room_name' added.";
            } else {
                $_SESSION["room_msg"] = "Room '$room_name' already exists.";
            }
        }

        header("Location: main.php#rooms");
        exit;
    }

    /* Assign a schedule to an available room */
    if ($action === "assign_room") {

        $room_id  = intval($_POST["room_id"] ?? 0);
        $sched_id = intval($_POST["sched_id"] ?? 0);

        if ($room_id > 0 && $sched_id > 0) {

            $stmt = $conn->prepare("SELECT COUNT(*) FROM schedules WHERE room_id = ?");
            $stmt->bind_param("i", $room_id);
            $stmt->execute();
            $stmt->bind_result($taken);
            $stmt->fetch();
            $stmt->close();

            if ($taken > 0) {
                $_SESSION["room_msg"] = "That room is already occupied.";
            } else {
                $stmt = $conn->prepare(
                    "UPDATE schedules SET room_id = ?
                     WHERE id = ? AND room_id IS NULL"
                );
                $stmt->bind_param("ii", $room_id, $sched_id);
                $stmt->execute();
                $ok = $stmt->affected_rows > 0;
                $stmt->close();

                if ($ok) {
                    addAuditLog($conn, $username, "Plotted schedule ID: $sched_id to room ID: $room_id");
                    $_SESSION["room_msg"] = "Schedule successfully plotted to the room.";
                } else {
                    $_SESSION["room_msg"] = "Unable to assign. The schedule may already have a room.";
                }
            }
        } else {
            $_SESSION["room_msg"] = "Please select a schedule first.";
        }

        header("Location: main.php#rooms");
        exit;
    }

    /* Release a room */
    if ($action === "release_room") {

        $room_id = intval($_POST["room_id"] ?? 0);

        if ($room_id > 0) {
            $stmt = $conn->prepare("UPDATE schedules SET room_id = NULL WHERE room_id = ?");
            $stmt->bind_param("i", $room_id);
            $stmt->execute();
            $stmt->close();

            addAuditLog($conn, $username, "Released room ID: $room_id");
            $_SESSION["room_msg"] = "Room is now available.";
        }

        header("Location: main.php#rooms");
        exit;
    }

    /* Backup log trigger */
    if ($action === "trigger_backup") {

        addAuditLog($conn, $username, "Initiated System Back-up");
        $_SESSION["flash_msg"] = "Database backup completed successfully.";

        header("Location: main.php");
        exit;
    }
}

/* ================================
   FETCH DATA
================================ */
$schedules_result = $conn->query(
    "SELECT s.*, r.room_name
     FROM schedules s
     LEFT JOIN rooms r ON r.id = s.room_id
     ORDER BY s.id DESC"
);

$rooms_result = $conn->query(
    "SELECT r.id AS room_id, r.room_name,
            s.id AS sched_id, s.prof, s.subject, s.time_slot, s.day_slot
     FROM rooms r
     LEFT JOIN schedules s ON s.room_id = r.id
     ORDER BY r.id ASC"
);

$unassigned = [];
$un_result = $conn->query(
    "SELECT id, prof, subject, time_slot, day_slot
     FROM schedules WHERE room_id IS NULL ORDER BY id DESC"
);
if ($un_result) {
    while ($u = $un_result->fetch_assoc()) {
        $unassigned[] = $u;
    }
}

$full_name  = $_SESSION["full_name"] ?? "User";
$student_id = $_SESSION["username"] ?? "";

$role_label = ucfirst($role);
$flash_msg  = $_SESSION["flash_msg"] ?? "";
unset($_SESSION["flash_msg"]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CCSJDM Main Dashboard</title>

<style>
:root {
    --bg: #ffffff;
    --card: #ffffff;
    --purple: #7011ce;
    --purple2: #8b12f6;
    --pink: #d946ef;
    --blue: #3b82f6;
    --green: #16a34a;
    --red: #ef4444;
    --text: #1f1f1f;
    --muted: #6b7280;
    --border: #e5e7eb;
    --input-bg: #f9fafb;
}

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
    font-family: "Segoe UI", Tahoma, sans-serif;
}

body {
    min-height: 100vh;
    color: var(--text);
    background: var(--bg);
}

.container {
    width: min(94%, 1180px);
    margin: auto;
    padding: 28px 0 50px;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    padding: 22px;
    margin-bottom: 20px;
    border: 1px solid var(--border);
    border-radius: 18px;
    background: var(--card);
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
}

.brand h1 {
    font-size: 1.65rem;
    background: linear-gradient(90deg, #d946ef, #8b5cf6, #3b82f6);
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
}

.brand p {
    margin-top: 5px;
    color: var(--muted);
    font-size: .85rem;
}

.user-area {
    display: flex;
    align-items: center;
    gap: 14px;
}

.user-info {
    text-align: right;
}

.user-info strong {
    display: block;
}

.user-info small {
    color: var(--muted);
}

.role-badge {
    display: inline-block;
    margin-top: 4px;
    padding: 2px 10px;
    border-radius: 999px;
    background: #f5f3ff;
    color: var(--purple);
    font-size: .75rem;
    font-weight: 700;
}

.logout {
    border: 0;
    border-radius: 8px;
    padding: 9px 15px;
    color: white;
    background: var(--red);
    cursor: pointer;
    font-weight: 600;
}

.logout:hover {
    opacity: .88;
}

.flash {
    margin-bottom: 18px;
    padding: 11px 13px;
    border: 1px solid #fecaca;
    border-radius: 8px;
    color: #b91c1c;
    background: #fef2f2;
    font-size: .9rem;
}

.tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 18px;
}

.tab {
    padding: 10px 17px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: #ffffff;
    color: var(--text);
    cursor: pointer;
    transition: .2s;
    text-decoration: none;
    font-size: 14px;
}

.tab:hover,
.tab.active {
    background: var(--purple);
    border-color: var(--purple);
    color: #ffffff;
}

.panel {
    display: none;
    padding: 24px;
    border: 1px solid var(--border);
    border-radius: 15px;
    background: var(--card);
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
}

.panel.active {
    display: block;
}

.panel h2 {
    margin-bottom: 18px;
}

.view-only-note {
    margin-bottom: 18px;
    padding: 11px 13px;
    border-radius: 8px;
    background: #f5f3ff;
    color: var(--purple);
    font-size: .9rem;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin-bottom: 20px;
}

.form-grid input {
    width: 100%;
    padding: 11px;
    border: 1px solid #d1d5db;
    border-radius: 7px;
    outline: none;
    color: var(--text);
    background: var(--input-bg);
}

.form-grid input:focus {
    border-color: var(--pink);
}

.primary {
    border: 0;
    border-radius: 7px;
    padding: 11px 15px;
    color: white;
    background: var(--purple);
    cursor: pointer;
    font-weight: 600;
}

.primary:hover {
    background: var(--purple2);
}

.cancel {
    display: none;
    border: 0;
    border-radius: 7px;
    padding: 11px 15px;
    color: white;
    background: #6b7280;
    cursor: pointer;
}

.table-wrap {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th,
td {
    padding: 12px;
    border-bottom: 1px solid var(--border);
    text-align: left;
    white-space: nowrap;
}

th {
    color: var(--purple);
    background: #faf5ff;
}

.action {
    border: 0;
    border-radius: 5px;
    padding: 6px 9px;
    color: white;
    cursor: pointer;
}

.edit {
    background: var(--blue);
}

.delete {
    background: var(--red);
}

/* ---------- Room plotting ---------- */
.room-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 15px;
}

.room {
    padding: 20px;
    border: 1px solid var(--border);
    border-radius: 12px;
    text-align: center;
    background: #ffffff;
}

.room.occupied {
    border-color: var(--pink);
}

.status {
    margin: 8px 0;
    font-size: .8rem;
    font-weight: 700;
}

.available {
    color: var(--green);
}

.occupied-text {
    color: var(--pink);
}

.room small {
    display: block;
    color: #374151;
    line-height: 1.5;
}

.room small.muted {
    color: var(--muted);
}

.room-form {
    margin-top: 14px;
    display: grid;
    gap: 8px;
}

.room-form select,
.add-room input {
    width: 100%;
    padding: 9px;
    border: 1px solid #d1d5db;
    border-radius: 7px;
    outline: none;
    color: var(--text);
    background: var(--input-bg);
    font-size: .85rem;
}

.room-form select:focus,
.add-room input:focus {
    border-color: var(--pink);
}

.room-form button {
    width: 100%;
    padding: 9px;
    font-size: .85rem;
}

.release {
    background: var(--red);
}

.release:hover {
    background: #dc2626;
}

.add-room {
    display: flex;
    gap: 10px;
    margin-top: 22px;
    max-width: 420px;
}

.add-room button {
    white-space: nowrap;
}

.room-badge {
    display: inline-block;
    padding: 3px 9px;
    border-radius: 20px;
    font-size: .75rem;
    font-weight: 700;
}

.room-badge.on {
    background: rgba(217, 70, 239, .12);
    color: var(--pink);
}

.room-badge.off {
    background: #f3f4f6;
    color: var(--muted);
}

.roles {
    display: grid;
    gap: 12px;
}

.role {
    padding: 15px;
    border: 1px solid var(--border);
    border-radius: 9px;
    background: #fafafa;
}

.alert {
    margin-bottom: 15px;
    padding: 11px;
    border: 1px solid #bbf7d0;
    border-radius: 7px;
    color: #15803d;
    background: #f0fdf4;
}

@media (max-width: 800px) {
    .header {
        flex-direction: column;
        align-items: flex-start;
    }

    .user-area {
        width: 100%;
        justify-content: space-between;
    }

    .form-grid {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 500px) {
    .form-grid {
        grid-template-columns: 1fr;
    }

    .user-info {
        text-align: left;
    }

    .add-room {
        flex-direction: column;
    }
}
</style>
</head>

<body>

<div class="container">

    <header class="header">
        <div class="brand">
            <h1>City College of San Jose Del Monte</h1>
            <p>Scheduling & Management System</p>
        </div>

        <div class="user-area">
            <div class="user-info">
                <strong><?= e($full_name) ?></strong>
                <small><?= e($student_id) ?></small><br>
                <span class="role-badge"><?= e($role_label) ?></span>
            </div>

            <form method="POST" action="main.php">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="logout">
                <button class="logout" type="submit">Logout</button>
            </form>
        </div>
    </header>

    <?php if ($flash_msg !== ""): ?>
        <div class="flash"><?= e($flash_msg) ?></div>
    <?php endif; ?>

    <nav class="tabs">
        <button class="tab active" data-tab="scheduling" onclick="showTab('scheduling', this)">Class Schedule</button>
        <button class="tab" data-tab="rooms" onclick="showTab('rooms', this)">Room Plotting</button>
        <button class="tab" data-tab="access" onclick="showTab('access', this)">Access Control</button>
        <a class="tab" href="courses.php">Courses</a>
        <?php if ($is_admin): ?>
            <a class="tab" href="approvals.php">Approvals</a>
        <?php endif; ?>
    </nav>

    <!-- CLASS SCHEDULE -->
    <section id="scheduling" class="panel active">

        <h2>Class Scheduling</h2>

        <?php if ($can_edit): ?>

            <form method="POST" action="main.php" class="form-grid">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="save_schedule">
                <input type="hidden" name="sched_id" id="sched_id">

                <input type="text" name="prof" id="prof" placeholder="Professor" required>
                <input type="text" name="subject" id="subject" placeholder="Subject" required>
                <input type="text" name="time" id="time" placeholder="Time e.g. 8:00 AM - 10:00 AM" required>
                <input type="text" name="day" id="day" placeholder="Day e.g. Mon/Wed" required>

                <button type="submit" class="primary" id="saveBtn">Add Schedule</button>
                <button type="button" class="cancel" id="cancelBtn" onclick="resetForm()">Cancel</button>
            </form>

        <?php else: ?>

            <div class="view-only-note">
                View only ang access mo. Hintayin ang approval ng admin para makapag-edit.
            </div>

        <?php endif; ?>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Professor</th>
                        <th>Subject</th>
                        <th>Time</th>
                        <th>Day</th>
                        <th>Room</th>
                        <?php if ($can_edit): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>

                <tbody>
                <?php if ($schedules_result && $schedules_result->num_rows > 0): ?>

                    <?php while ($row = $schedules_result->fetch_assoc()): ?>
                    <tr>
                        <td><?= e($row["prof"]) ?></td>
                        <td><?= e($row["subject"]) ?></td>
                        <td><?= e($row["time_slot"]) ?></td>
                        <td><?= e($row["day_slot"]) ?></td>
                        <td>
                            <?php if (!empty($row["room_name"])): ?>
                                <span class="room-badge on"><?= e($row["room_name"]) ?></span>
                            <?php else: ?>
                                <span class="room-badge off">No room</span>
                            <?php endif; ?>
                        </td>

                        <?php if ($can_edit): ?>
                        <td>
                            <button
                                type="button"
                                class="action edit"
                                onclick='editSchedule(<?= json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                            >Edit</button>

                            <form method="POST" action="main.php" style="display:inline"
                                  onsubmit="return confirm('Delete this schedule?');">
                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="delete_schedule">
                                <input type="hidden" name="sched_id" value="<?= (int)$row["id"] ?>">
                                <button type="submit" class="action delete">Delete</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endwhile; ?>

                <?php else: ?>
                    <tr>
                        <td colspan="<?= $can_edit ? 6 : 5 ?>" style="text-align:center">No schedules found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    </section>

    <!-- ROOM PLOTTING -->
    <section id="rooms" class="panel">

        <h2>Room Plotting Status</h2>

        <?php if (!empty($_SESSION["room_msg"])): ?>
            <div class="alert">
                <?= e($_SESSION["room_msg"]) ?>
            </div>
            <?php unset($_SESSION["room_msg"]); ?>
        <?php endif; ?>

        <?php if (!$can_edit): ?>
            <div class="view-only-note">
                View only ang access mo. Makikita mo ang status ng rooms pero hindi ka makakapag-assign o makapag-release.
            </div>
        <?php endif; ?>

        <div class="room-grid">
        <?php if ($rooms_result && $rooms_result->num_rows > 0): ?>

            <?php while ($r = $rooms_result->fetch_assoc()): ?>
                <?php $is_occupied = !empty($r["sched_id"]); ?>

                <div class="room <?= $is_occupied ? 'occupied' : '' ?>">

                    <h3><?= e($r["room_name"]) ?></h3>

                    <?php if ($is_occupied): ?>

                        <p class="status occupied-text">Occupied</p>

                        <small>
                            <strong><?= e($r["subject"]) ?></strong><br>
                            Prof. <?= e($r["prof"]) ?><br>
                            <?= e($r["day_slot"]) ?> | <?= e($r["time_slot"]) ?>
                        </small>

                        <?php if ($can_edit): ?>
                        <form method="POST" action="main.php" class="room-form"
                              onsubmit="return confirm('Release this room?');">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="release_room">
                            <input type="hidden" name="room_id" value="<?= (int)$r["room_id"] ?>">
                            <button type="submit" class="action release">Release Room</button>
                        </form>
                        <?php endif; ?>

                    <?php else: ?>

                        <p class="status available">Available</p>

                        <?php if (!$can_edit): ?>
                            <small class="muted">View only</small>

                        <?php elseif (count($unassigned) > 0): ?>
                            <form method="POST" action="main.php" class="room-form">
                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="assign_room">
                                <input type="hidden" name="room_id" value="<?= (int)$r["room_id"] ?>">

                                <select name="sched_id" required>
                                    <option value="">-- Select schedule --</option>
                                    <?php foreach ($unassigned as $u): ?>
                                        <option value="<?= (int)$u["id"] ?>">
                                            <?= e($u["subject"] . " - " . $u["prof"] . " (" . $u["day_slot"] . " " . $u["time_slot"] . ")") ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <button type="submit" class="primary">Assign to Room</button>
                            </form>

                        <?php else: ?>
                            <small class="muted">No unassigned schedules.<br>Add one in Class Schedule.</small>
                        <?php endif; ?>

                    <?php endif; ?>

                </div>
            <?php endwhile; ?>

        <?php else: ?>
            <p>No rooms found.</p>
        <?php endif; ?>
        </div>

        <?php if ($can_edit): ?>
        <form method="POST" action="main.php" class="add-room">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="add_room">
            <input type="text" name="room_name" placeholder="New room name e.g. Room 103" required>
            <button type="submit" class="primary">Add Room</button>
        </form>
        <?php endif; ?>

    </section>

    <!-- ACCESS CONTROL -->
    <section id="access" class="panel">

        <h2>Access Control Roles</h2>

        <div class="roles">
            <div class="role">
                <strong>Program Chair</strong><br>
                Full Schedule Creation & Modification Rights
            </div>
            <div class="role">
                <strong>Dean</strong><br>
                Schedule & Plotting Approval Permissions
            </div>
            <div class="role">
                <strong>Registrar</strong><br>
                Master Schedule View & Student Enrollment Access
            </div>
            <div class="role">
                <strong>Professor</strong><br>
                View Personal Assigned Schedule
            </div>
        </div>

    </section>

</div>

<script>
function showTab(id, button) {
    document.querySelectorAll(".panel").forEach(function (panel) {
        panel.classList.remove("active");
    });
    document.querySelectorAll(".tab").forEach(function (tab) {
        tab.classList.remove("active");
    });

    document.getElementById(id).classList.add("active");
    button.classList.add("active");
}

/* Buksan ang tamang tab pagkatapos ng redirect (hal. main.php#rooms) */
(function () {
    const id = window.location.hash.replace("#", "");
    if (!id) return;

    const btn = document.querySelector('.tab[data-tab="' + id + '"]');
    if (btn && document.getElementById(id)) {
        showTab(id, btn);
        window.scrollTo(0, 0);
    }
})();

function editSchedule(item) {
    document.getElementById("sched_id").value = item.id;
    document.getElementById("prof").value = item.prof;
    document.getElementById("subject").value = item.subject;
    document.getElementById("time").value = item.time_slot;
    document.getElementById("day").value = item.day_slot;

    document.getElementById("saveBtn").textContent = "Update Schedule";
    document.getElementById("cancelBtn").style.display = "inline-block";

    document.getElementById("scheduling").scrollIntoView({ behavior: "smooth" });
}

function resetForm() {
    document.getElementById("sched_id").value = "";
    document.getElementById("prof").value = "";
    document.getElementById("subject").value = "";
    document.getElementById("time").value = "";
    document.getElementById("day").value = "";

    document.getElementById("saveBtn").textContent = "Add Schedule";
    document.getElementById("cancelBtn").style.display = "none";
}
</script>

</body>
</html>