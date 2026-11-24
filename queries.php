<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role_name'], ['admin', 'teacher'], true)) {
    die('Доступ запрещен.');
}

$studentTab = ($_GET['student'] ?? '1') === '2' ? '2' : '1';
$msg = '';
$err = '';

$staticResult = null;
$staticTitle  = '';
$staticSql    = '';

$dynamicResult = null;
$defaultDynSql = $studentTab === '1'
    ? 'SELECT id, login, full_name FROM users LIMIT 10;'
    : 'SELECT t.title, COUNT(a.id) AS attempts_cnt FROM tests t LEFT JOIN attempts a ON t.id = a.test_id GROUP BY t.id;';
$dynamicSql = trim($_POST['dynamic_sql'] ?? $defaultDynSql);

// Наборы из 5 статических запросов для каждого студента
$queriesByStudent = [
    '1' => [
        'simple1' => [
            'label' => '1. Простой: Список всех тестов',
            'sql'   => 'SELECT id, title, teacher_id FROM tests ORDER BY id;'
        ],
        'simple2' => [
            'label' => '2. Простой: Успешные попытки (балл >= 70)',
            'sql'   => 'SELECT id, student_id, test_id, score FROM attempts WHERE score >= 70 ORDER BY score DESC;'
        ],
        'subquery' => [
            'label' => '3. Подзапрос: Попытки выше среднего балла',
            'sql'   => 'SELECT id, student_id, test_id, score FROM attempts WHERE score > (SELECT AVG(score) FROM attempts) ORDER BY score DESC;'
        ],
        'join' => [
            'label' => '4. JOIN: Вопросы с названием теста и автором',
            'sql'   => "SELECT q.id AS question_id, t.title AS test_title, COALESCE(NULLIF(u.full_name, ''), u.login) AS author, q.question_text, q.question_type
                        FROM questions q
                        JOIN tests t ON q.test_id = t.id
                        JOIN users u ON t.teacher_id = u.id
                        ORDER BY t.id, q.id;"
        ],
        'view' => [
            'label' => '5. VIEW: Список студентов (v_only_students)',
            'sql'   => 'SELECT * FROM v_only_students;'
        ]
    ],
    '2' => [
        'simple1' => [
            'label' => '1. Простой: Список пользователей системы',
            'sql'   => 'SELECT id, login, full_name, role_id FROM users ORDER BY id;'
        ],
        'simple2' => [
            'label' => '2. Простой: Вопросы с множественным выбором',
            'sql'   => "SELECT id, test_id, question_text FROM questions WHERE question_type = 'multiple_choice' ORDER BY id;"
        ],
        'subquery' => [
            'label' => '3. Подзапрос: Тесты без единой попытки',
            'sql'   => 'SELECT id, title FROM tests WHERE id NOT IN (SELECT DISTINCT test_id FROM attempts) ORDER BY id;'
        ],
        'join' => [
            'label' => '4. JOIN: Попытки с ФИО студента и названием теста',
            'sql'   => "SELECT a.id AS attempt_id, COALESCE(NULLIF(u.full_name, ''), u.login) AS student, t.title AS test_title, a.score
                        FROM attempts a
                        JOIN users u ON a.student_id = u.id
                        JOIN tests t ON a.test_id = t.id
                        ORDER BY a.id DESC;"
        ],
        'view' => [
            'label' => '5. VIEW: История пользователей (v_users_history)',
            'sql'   => 'SELECT * FROM v_users_history;'
        ]
    ]
];

$staticQueries = $queriesByStudent[$studentTab];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'run_static') {
            $key = $_POST['query_key'] ?? '';
            if (isset($staticQueries[$key])) {
                $staticTitle  = $staticQueries[$key]['label'];
                $staticSql    = $staticQueries[$key]['sql'];
                $staticResult = $pdo->query($staticSql)->fetchAll();
            }
        }

        if ($action === 'run_dynamic') {
            if ($dynamicSql === '') {
                throw new Exception('Введите SQL-запрос.');
            }
            if (!preg_match('/^\s*SELECT\b/i', $dynamicSql) || preg_match('/\b(DROP|DELETE|UPDATE|INSERT|ALTER|TRUNCATE|GRANT|REVOKE|CREATE)\b/i', $dynamicSql)) {
                throw new Exception('Разрешены только запросы выборки (SELECT).');
            }
            $dynamicResult = $pdo->query($dynamicSql)->fetchAll();
        }

        // Процедура Студента 1
        if ($action === 'call_proc_1') {
            $stmt = $pdo->query("CALL UpdateLogStatus()");
            $stmt->closeCursor();
            $msg = "Процедура UpdateLogStatus() выполнена: необработанные записи переведены в status = 1.";
        }

        // Процедура Студента 2
        if ($action === 'call_proc_2') {
            $stmt = $pdo->query("CALL CleanZeroAttempts()");
            $stmt->closeCursor();
            $msg = "Процедура CleanZeroAttempts() выполнена: нулевые/пустые попытки очищены.";
        }

        // Транзакция Студента 2: транзакционное добавление тестовой попытки с проверкой балла (ROLLBACK при ошибке)
        if ($action === 'run_tx_2') {
            $txStudentId = (int)$_POST['tx_student_id'];
            $txTestId    = (int)$_POST['tx_test_id'];
            $txScore     = (float)$_POST['tx_score'];

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO attempts (student_id, test_id, score) VALUES (?, ?, ?)");
                $stmt->execute([$txStudentId, $txTestId, $txScore]);

                if ($txScore < 0 || $txScore > 100) {
                    throw new Exception("Некорректный балл ($txScore). Сработал ROLLBACK — запись отменена!");
                }

                $pdo->commit();
                $msg = "Транзакция зафиксирована (COMMIT): попытка добавлена, а триггер trg_after_insert_attempt записал событие в attempts_log.";
            } catch (Exception $txEx) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $txEx;
            }
        }
    }
} catch (Exception $e) {
    $err = $e->getMessage();
}

function renderDynamicTable(?array $rows): void {
    if ($rows === null) return;
    if (empty($rows)) {
        echo '<div class="alert alert-secondary mb-0">Запрос выполнен успешно, но вернул 0 строк.</div>';
        return;
    }
    $headers = array_keys($rows[0]);
    echo '<div class="table-responsive"><table class="table table-sm table-bordered table-striped mb-0">';
    echo '<thead class="table-dark"><tr>';
    foreach ($headers as $h) {
        echo '<th>' . htmlspecialchars((string)$h) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $val) {
            echo '<td>' . htmlspecialchars((string)($val ?? 'NULL')) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Аналитика и SQL-запросы — TestingSystem</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light pb-5">
<nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="index.php">← На главную</a>
        <span class="navbar-text text-white">Индивидуальные SQL-запросы, триггеры, процедуры, функции и транзакции</span>
    </div>
</nav>

<div class="container">
    <ul class="nav nav-pills mb-4">
        <li class="nav-item">
            <a class="nav-link <?= $studentTab === '1' ? 'active' : '' ?>" href="?student=1">Комплект №1 (Студент 1)</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $studentTab === '2' ? 'active' : '' ?>" href="?student=2">Комплект №2 (Студент 2)</a>
        </li>
    </ul>

    <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <!-- БЛОК 1: 5 СТАТИЧЕСКИХ ЗАПРОСОВ -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white fw-bold">
            1. Статические запросы Студента <?= $studentTab ?> (2 простых, подзапрос, JOIN, VIEW)
        </div>
        <div class="card-body">
            <form method="POST" action="?student=<?= $studentTab ?>" class="d-flex flex-wrap gap-2 mb-3">
                <input type="hidden" name="action" value="run_static">
                <?php foreach ($staticQueries as $key => $q): ?>
                    <button type="submit" name="query_key" value="<?= $key ?>" class="btn btn-outline-primary btn-sm">
                        <?= htmlspecialchars($q['label']) ?>
                    </button>
                <?php endforeach; ?>
            </form>

            <?php if ($staticResult !== null): ?>
                <div class="mb-2">
                    <strong><?= htmlspecialchars($staticTitle) ?></strong>
                    <pre class="bg-dark text-light p-2 rounded small mt-1 mb-2"><?= htmlspecialchars($staticSql) ?></pre>
                </div>
                <?php renderDynamicTable($staticResult); ?>
            <?php else: ?>
                <p class="text-muted small mb-0">Выберите запрос выше для выполнения.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- БЛОК 2: ДИНАМИЧЕСКИЙ ЗАПРОС -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-success text-white fw-bold">
            2. Динамический SQL-запрос Студента <?= $studentTab ?>
        </div>
        <div class="card-body">
            <form method="POST" action="?student=<?= $studentTab ?>">
                <input type="hidden" name="action" value="run_dynamic">
                <div class="mb-2">
                    <textarea name="dynamic_sql" rows="3" class="form-control font-monospace" required><?= htmlspecialchars($dynamicSql) ?></textarea>
                </div>
                <button type="submit" class="btn btn-success btn-sm">Выполнить SELECT-запрос</button>
            </form>
            <?php if ($dynamicResult !== null): ?>
                <hr>
                <?php renderDynamicTable($dynamicResult); ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($studentTab === '1'): ?>
        <!-- БЛОКИ СТУДЕНТА 1 -->
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-dark text-white fw-bold">
                        3. Триггер (users_log) и процедура UpdateLogStatus()
                    </div>
                    <div class="card-body">
                        <p class="small text-muted">
                            Транзакция Студента 1 реализована при прохождении теста в <a href="take_test.php">take_test.php</a>. Триггер срабатывает при создании/удалении пользователя в <a href="crud.php">CRUD</a>.
                        </p>
                        <form method="POST" action="?student=1" class="mb-3">
                            <input type="hidden" name="action" value="call_proc_1">
                            <button type="submit" class="btn btn-warning btn-sm">Вызвать CALL UpdateLogStatus()</button>
                        </form>
                        <?php
                        $logs1 = $pdo->query("SELECT * FROM users_log ORDER BY log_id DESC LIMIT 10")->fetchAll();
                        renderDynamicTable($logs1);
                        ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-info text-dark fw-bold">
                        4. Хранимая функция GetGrade(score)
                    </div>
                    <div class="card-body">
                        <?php
                        $grades1 = $pdo->query("
                            SELECT a.id, COALESCE(NULLIF(u.full_name, ''), u.login) AS student, a.score, GetGrade(a.score) AS grade
                            FROM attempts a JOIN users u ON a.student_id = u.id ORDER BY a.id DESC LIMIT 10
                        ")->fetchAll();
                        renderDynamicTable($grades1);
                        ?>
                    </div>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- БЛОКИ СТУДЕНТА 2 -->
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-dark text-white fw-bold">
                        3. Транзакция + Триггер (attempts_log) + Процедура CleanZeroAttempts()
                    </div>
                    <div class="card-body">
                        <p class="small text-muted">
                            Транзакция добавляет попытку (если балл вне диапазона 0–100, выполняется <code>ROLLBACK</code>). При <code>COMMIT</code> срабатывает триггер <code>trg_after_insert_attempt</code>.
                        </p>
                        <?php
                        $stList = $pdo->query("SELECT u.id, COALESCE(NULLIF(u.full_name, ''), u.login) AS name FROM users u JOIN roles r ON u.role_id = r.id WHERE r.name = 'student'")->fetchAll();
                        $tsList = $pdo->query("SELECT id, title FROM tests")->fetchAll();
                        ?>
                        <form method="POST" action="?student=2" class="row g-2 mb-3">
                            <input type="hidden" name="action" value="run_tx_2">
                            <div class="col-md-4">
                                <select name="tx_student_id" class="form-select form-select-sm">
                                    <?php foreach ($stList as $st): ?><option value="<?= $st['id'] ?>"><?= htmlspecialchars($st['name']) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <select name="tx_test_id" class="form-select form-select-sm">
                                    <?php foreach ($tsList as $ts): ?><option value="<?= $ts['id'] ?>"><?= htmlspecialchars($ts['title']) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <input type="number" step="0.01" name="tx_score" value="85" class="form-control form-control-sm" placeholder="Балл">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary btn-sm w-100">Транзакция</button>
                            </div>
                        </form>

                        <form method="POST" action="?student=2" class="mb-3">
                            <input type="hidden" name="action" value="call_proc_2">
                            <button type="submit" class="btn btn-warning btn-sm">Вызвать CALL CleanZeroAttempts()</button>
                        </form>

                        <?php
                        $logs2 = $pdo->query("SELECT * FROM attempts_log ORDER BY log_id DESC LIMIT 10")->fetchAll();
                        renderDynamicTable($logs2);
                        ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-info text-dark fw-bold">
                        4. Хранимая функция GetTestAvgScore(test_id)
                    </div>
                    <div class="card-body">
                        <?php
                        $avg2 = $pdo->query("SELECT id, title, GetTestAvgScore(id) AS avg_score FROM tests ORDER BY id")->fetchAll();
                        renderDynamicTable($avg2);
                        ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
