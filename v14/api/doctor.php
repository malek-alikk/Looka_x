<?php
// ══════════════════════════════════════════════
//  api/doctor.php — بورتال الدكتور
//  GET  ?action=me              → بيانات الدكتور
//  GET  ?action=patients        → قايمة المرضى
//  GET  ?action=patient&uid=N   → تفاصيل مريض
//  GET  ?action=records&uid=N   → سجلات مريض
//  GET  ?action=snacks&uid=N    → سناكس مريض
//  GET  ?action=notes&uid=N     → التوصيات لمريض
//  POST ?action=login           → دخول الدكتور
//  POST ?action=logout          → خروج
//  POST ?action=register        → تسجيل دكتور جديد
//  POST ?action=link_patient    → ربط مريض { patient_username }
//  POST ?action=unlink_patient  → فك ربط مريض { patient_id }
//  POST ?action=set_goals       → ضبط أهداف مريض { patient_id, ...goals }
//  POST ?action=add_note        → إضافة توصية { patient_id, note, type }
//  POST ?action=update_meds     → تعديل أدوية مريض { patient_id, meds[] }
//  DELETE ?action=note&id=N     → حذف توصية
// ══════════════════════════════════════════════
require_once __DIR__ . '/config.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$body   = get_body();

// ══════════════════════════════════════════════
//  Helpers
// ══════════════════════════════════════════════
function getDoctorId(): int {
    if (isset($_SESSION['doctor_id'])) return (int)$_SESSION['doctor_id'];
    http_response_code(401);
    die(json_encode(['error' => 'غير مصرح — سجّل دخولك كدكتور أولاً'], JSON_UNESCAPED_UNICODE));
}

function ensureDoctorTables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS doctors (
          id            INT AUTO_INCREMENT PRIMARY KEY,
          username      VARCHAR(60)  NOT NULL UNIQUE,
          password_hash VARCHAR(255) NOT NULL,
          name          VARCHAR(100),
          specialty     VARCHAR(100),
          clinic        VARCHAR(150),
          phone         VARCHAR(30),
          created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS doctor_patients (
          id         INT AUTO_INCREMENT PRIMARY KEY,
          doctor_id  INT NOT NULL,
          patient_id INT NOT NULL,
          linked_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uq_dp (doctor_id, patient_id),
          FOREIGN KEY (doctor_id)  REFERENCES doctors(id) ON DELETE CASCADE,
          FOREIGN KEY (patient_id) REFERENCES users(id)   ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS doctor_notes (
          id         INT AUTO_INCREMENT PRIMARY KEY,
          doctor_id  INT NOT NULL,
          patient_id INT NOT NULL,
          type       VARCHAR(30) DEFAULT 'general',
          note       TEXT        NOT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (doctor_id)  REFERENCES doctors(id) ON DELETE CASCADE,
          FOREIGN KEY (patient_id) REFERENCES users(id)   ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

// ══════════════════════════════════════════════
//  AUTH — تسجيل الدخول
// ══════════════════════════════════════════════
if ($action === 'login' && $method === 'POST') {
    $pdo = getDB();
    ensureDoctorTables($pdo);
    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';
    if (!$username || !$password) json_out(['error' => 'أدخل اسم المستخدم والباسورد'], 400);

    $stmt = $pdo->prepare('SELECT id, name, username, password_hash, specialty, clinic, phone FROM doctors WHERE username = ?');
    $stmt->execute([$username]);
    $doc = $stmt->fetch();

    if (!$doc || !password_verify($password, $doc['password_hash']))
        json_out(['error' => 'اسم المستخدم أو الباسورد غلط'], 401);

    $_SESSION['doctor_id']   = $doc['id'];
    $_SESSION['doctor_name'] = $doc['name'];
    json_out(['ok' => true, 'doctor' => array_diff_key($doc, ['password_hash' => ''])]);
}

// ══════════════════════════════════════════════
//  AUTH — تسجيل دكتور جديد
// ══════════════════════════════════════════════
if ($action === 'register' && $method === 'POST') {
    $pdo = getDB();
    ensureDoctorTables($pdo);
    $username  = trim($body['username']  ?? '');
    $password  = $body['password']  ?? '';
    $name      = trim($body['name']      ?? '');
    $specialty = trim($body['specialty'] ?? '');
    $clinic    = trim($body['clinic']    ?? '');
    $phone     = trim($body['phone']     ?? '');

    if (!$username || !$password || !$name) json_out(['error' => 'الاسم واسم المستخدم والباسورد مطلوبين'], 400);
    if (strlen($password) < 6)              json_out(['error' => 'الباسورد لازم 6 أحرف على الأقل'], 400);

    try {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare('INSERT INTO doctors (username, password_hash, name, specialty, clinic, phone) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$username, $hash, $name, $specialty, $clinic, $phone]);
        $docId = $pdo->lastInsertId();
        $_SESSION['doctor_id']   = $docId;
        $_SESSION['doctor_name'] = $name;
        json_out(['ok' => true, 'doctor' => ['id' => $docId, 'name' => $name, 'username' => $username]], 201);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') json_out(['error' => 'اسم المستخدم ده موجود'], 409);
        json_out(['error' => 'خطأ: ' . $e->getMessage()], 500);
    }
}

// ══════════════════════════════════════════════
//  AUTH — خروج
// ══════════════════════════════════════════════
if ($action === 'logout' && $method === 'POST') {
    unset($_SESSION['doctor_id'], $_SESSION['doctor_name']);
    json_out(['ok' => true]);
}

// ══════════════════════════════════════════════
//  ME — بيانات الدكتور
// ══════════════════════════════════════════════
if ($action === 'me') {
    $did = getDoctorId();
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT id, username, name, specialty, clinic, phone, created_at FROM doctors WHERE id = ?');
    $stmt->execute([$did]);
    $doc = $stmt->fetch();
    json_out($doc ?: ['error' => 'مش موجود'], $doc ? 200 : 404);
}

// ══════════════════════════════════════════════
//  PATIENTS — قايمة المرضى
// ══════════════════════════════════════════════
if ($action === 'patients') {
    $did = getDoctorId();
    $pdo = getDB();
    ensureDoctorTables($pdo);
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.name, u.created_at,
               p.dob, p.gender, p.dtype, p.weight, p.height, p.phone,
               dp.linked_at,
               (SELECT r.fasting FROM records r WHERE r.user_id = u.id ORDER BY r.record_date DESC LIMIT 1) AS last_fasting,
               (SELECT r.record_date FROM records r WHERE r.user_id = u.id ORDER BY r.record_date DESC LIMIT 1) AS last_date,
               (SELECT AVG(r2.fasting) FROM records r2 WHERE r2.user_id = u.id AND r2.record_date >= DATE_SUB(CURDATE(),INTERVAL 7 DAY)) AS avg7,
               (SELECT COUNT(*) FROM records r3 WHERE r3.user_id = u.id) AS total_records
        FROM doctor_patients dp
        JOIN users   u ON u.id = dp.patient_id
        LEFT JOIN profile p ON p.user_id = u.id
        WHERE dp.doctor_id = ?
        ORDER BY dp.linked_at DESC
    ");
    $stmt->execute([$did]);
    json_out($stmt->fetchAll());
}

// ══════════════════════════════════════════════
//  PATIENT DETAIL — تفاصيل مريض واحد
// ══════════════════════════════════════════════
if ($action === 'patient') {
    $did = getDoctorId();
    $uid = (int)($_GET['uid'] ?? 0);
    if (!$uid) json_out(['error' => 'uid مطلوب'], 400);
    $pdo = getDB();

    // تأكد إن المريض ده تابع للدكتور ده
    $chk = $pdo->prepare('SELECT 1 FROM doctor_patients WHERE doctor_id=? AND patient_id=?');
    $chk->execute([$did, $uid]);
    if (!$chk->fetch()) json_out(['error' => 'المريض ده مش في قايمتك'], 403);

    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.name, u.created_at,
               p.dob, p.gender, p.dtype, p.weight, p.height, p.phone,
               p.doctor, p.conditions, p.allergies, p.diag_year,
               g.fast_min, g.fast_max, g.post_min, g.post_max,
               g.low_alert, g.high_alert, g.hba1c, g.hba1c_last,
               g.weight AS goal_weight, g.activity, g.bp, g.chol
        FROM users u
        LEFT JOIN profile p ON p.user_id = u.id
        LEFT JOIN goals   g ON g.user_id = u.id
        WHERE u.id = ?
    ");
    $stmt->execute([$uid]);
    json_out($stmt->fetch());
}

// ══════════════════════════════════════════════
//  RECORDS — سجلات السكر لمريض
// ══════════════════════════════════════════════
if ($action === 'records') {
    $did = getDoctorId();
    $uid = (int)($_GET['uid'] ?? 0);
    if (!$uid) json_out(['error' => 'uid مطلوب'], 400);
    $pdo = getDB();
    $chk = $pdo->prepare('SELECT 1 FROM doctor_patients WHERE doctor_id=? AND patient_id=?');
    $chk->execute([$did, $uid]);
    if (!$chk->fetch()) json_out(['error' => 'مش مصرح'], 403);

    $limit = min((int)($_GET['limit'] ?? 90), 365);
    $stmt  = $pdo->prepare('SELECT * FROM records WHERE user_id=? ORDER BY record_date DESC LIMIT ?');
    $stmt->execute([$uid, $limit]);
    json_out($stmt->fetchAll());
}

// ══════════════════════════════════════════════
//  SNACKS — سناكس مريض
// ══════════════════════════════════════════════
if ($action === 'snacks') {
    $did = getDoctorId();
    $uid = (int)($_GET['uid'] ?? 0);
    if (!$uid) json_out(['error' => 'uid مطلوب'], 400);
    $pdo = getDB();
    $chk = $pdo->prepare('SELECT 1 FROM doctor_patients WHERE doctor_id=? AND patient_id=?');
    $chk->execute([$did, $uid]);
    if (!$chk->fetch()) json_out(['error' => 'مش مصرح'], 403);

    $stmt = $pdo->prepare('SELECT * FROM snacks WHERE user_id=? ORDER BY snack_date DESC, snack_time DESC LIMIT 60');
    $stmt->execute([$uid]);
    json_out($stmt->fetchAll());
}

// ══════════════════════════════════════════════
//  NOTES — توصيات الدكتور لمريض
// ══════════════════════════════════════════════
if ($action === 'notes') {
    $did = getDoctorId();
    $uid = (int)($_GET['uid'] ?? 0);
    if (!$uid) json_out(['error' => 'uid مطلوب'], 400);
    $pdo = getDB();
    ensureDoctorTables($pdo);
    $stmt = $pdo->prepare('SELECT * FROM doctor_notes WHERE doctor_id=? AND patient_id=? ORDER BY created_at DESC');
    $stmt->execute([$did, $uid]);
    json_out($stmt->fetchAll());
}

// ══════════════════════════════════════════════
//  LINK PATIENT — ربط مريض بالدكتور
// ══════════════════════════════════════════════
if ($action === 'link_patient' && $method === 'POST') {
    $did = getDoctorId();
    $pdo = getDB();
    ensureDoctorTables($pdo);
    $username = trim($body['patient_username'] ?? '');
    if (!$username) json_out(['error' => 'أدخل اسم مستخدم المريض'], 400);

    $stmt = $pdo->prepare('SELECT id, name, username FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $patient = $stmt->fetch();
    if (!$patient) json_out(['error' => 'المريض ده مش موجود'], 404);

    try {
        $pdo->prepare('INSERT INTO doctor_patients (doctor_id, patient_id) VALUES (?,?)')->execute([$did, $patient['id']]);
        json_out(['ok' => true, 'patient' => $patient]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') json_out(['error' => 'المريض ده موجود بالفعل في قايمتك'], 409);
        json_out(['error' => 'خطأ: ' . $e->getMessage()], 500);
    }
}

// ══════════════════════════════════════════════
//  UNLINK PATIENT — فك ربط مريض
// ══════════════════════════════════════════════
if ($action === 'unlink_patient' && $method === 'POST') {
    $did = getDoctorId();
    $pid = (int)($body['patient_id'] ?? 0);
    if (!$pid) json_out(['error' => 'patient_id مطلوب'], 400);
    $pdo = getDB();
    $pdo->prepare('DELETE FROM doctor_patients WHERE doctor_id=? AND patient_id=?')->execute([$did, $pid]);
    json_out(['ok' => true]);
}

// ══════════════════════════════════════════════
//  SET GOALS — ضبط أهداف مريض
// ══════════════════════════════════════════════
if ($action === 'set_goals' && $method === 'POST') {
    $did = getDoctorId();
    $pid = (int)($body['patient_id'] ?? 0);
    if (!$pid) json_out(['error' => 'patient_id مطلوب'], 400);
    $pdo = getDB();
    $chk = $pdo->prepare('SELECT 1 FROM doctor_patients WHERE doctor_id=? AND patient_id=?');
    $chk->execute([$did, $pid]);
    if (!$chk->fetch()) json_out(['error' => 'مش مصرح'], 403);

    $nv = fn($v) => ($v !== '' && $v !== null) ? (float)$v : null;

    $stmt = $pdo->prepare("
        INSERT INTO goals (user_id, fast_min, fast_max, post_min, post_max, low_alert, high_alert, hba1c, hba1c_last, weight, activity, bp, chol)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          fast_min=VALUES(fast_min), fast_max=VALUES(fast_max),
          post_min=VALUES(post_min), post_max=VALUES(post_max),
          low_alert=VALUES(low_alert), high_alert=VALUES(high_alert),
          hba1c=VALUES(hba1c), hba1c_last=VALUES(hba1c_last),
          weight=VALUES(weight), activity=VALUES(activity),
          bp=VALUES(bp), chol=VALUES(chol), updated_at=CURRENT_TIMESTAMP
    ");
    $stmt->execute([
        $pid,
        $nv($body['fast_min']   ?? 70),  $nv($body['fast_max']   ?? 130),
        $nv($body['post_min']   ?? 70),  $nv($body['post_max']   ?? 180),
        $nv($body['low_alert']  ?? 70),  $nv($body['high_alert'] ?? 250),
        $nv($body['hba1c']      ?? null), $nv($body['hba1c_last'] ?? null),
        $nv($body['weight']     ?? null),
        $body['activity'] ?? null,
        $body['bp']       ?? null,
        $body['chol']     ?? null,
    ]);
    json_out(['ok' => true]);
}

// ══════════════════════════════════════════════
//  ADD NOTE — إضافة توصية
// ══════════════════════════════════════════════
if ($action === 'add_note' && $method === 'POST') {
    $did = getDoctorId();
    $pid  = (int)($body['patient_id'] ?? 0);
    $note = trim($body['note'] ?? '');
    $type = $body['type'] ?? 'general';
    if (!$pid || !$note) json_out(['error' => 'patient_id والملاحظة مطلوبين'], 400);
    $pdo = getDB();
    ensureDoctorTables($pdo);
    $chk = $pdo->prepare('SELECT 1 FROM doctor_patients WHERE doctor_id=? AND patient_id=?');
    $chk->execute([$did, $pid]);
    if (!$chk->fetch()) json_out(['error' => 'مش مصرح'], 403);

    $stmt = $pdo->prepare('INSERT INTO doctor_notes (doctor_id, patient_id, type, note) VALUES (?,?,?,?)');
    $stmt->execute([$did, $pid, $type, $note]);
    json_out(['ok' => true, 'id' => $pdo->lastInsertId()]);
}

// ══════════════════════════════════════════════
//  DELETE NOTE — حذف توصية
// ══════════════════════════════════════════════
if ($action === 'note' && $method === 'DELETE') {
    $did = getDoctorId();
    $nid = (int)($_GET['id'] ?? 0);
    if (!$nid) json_out(['error' => 'id مطلوب'], 400);
    $pdo = getDB();
    $pdo->prepare('DELETE FROM doctor_notes WHERE id=? AND doctor_id=?')->execute([$nid, $did]);
    json_out(['ok' => true]);
}

json_out(['error' => 'action مش معروف: ' . $action], 400);
