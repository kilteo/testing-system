<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId    = (int)$_SESSION['user_id'];
$role      = $_SESSION['role_name'] ?? 'student';
$isAdmin   = ($role === 'admin');
$isTeacher = ($role === 'teacher');
$canManage = ($isAdmin || $isTeacher);

$selectedTestId = isset($_GET['test_id']) && $_GET['test_id'] !== '' ? (int)$_GET['test_id'] : null;


function formatGrade(float $score): array {
    if ($score >= 85) return ['Отлично (5)', 'bg-success'];
    if ($score >= 70) return ['Хорошо (4)', 'bg-primary'];
    if ($score >= 50) return ['Удовлетворительно (3)', 'bg-warning text-dark'];
    return ['Неудовлетворительно (2)', 'bg-danger'];
}


$availableTests = [];
if ($canManage) {
    if ($isAdmin) {
        $availableTests = $pdo->query("SELECT id, title FROM tests ORDER BY title")->fetchAll();
    } else {
        $stmt = $pdo->prepare("SELECT id, title FROM tests WHERE teacher_id = ? ORDER BY title");
        $stmt->execute([$userId]);
        $availableTests = $stmt->fetchAll();
    }
}


$params = [];
$where  = [];

if ($isTeacher) {
    $where[]  = "t.teacher_id = ?";
    $params[] = $userId;
} elseif (!$isAdmin) {

    $where[]  = "a.student_id = ?";
    $params[] = $userId;
}

if ($canManage && $selectedTestId !== null) {
    $where[]  = "t.id = ?";
    $params[] = $selectedTestId;
}

$whereSql = count($where) > 0 ? ('WHERE ' . implode(' AND ', $where)) : '';

$sqlAttempts = "
    SELECT 
        a.id,
        a.score,
        a.completed_at,
        COALESCE(NULLIF(u.full_name, ''), u.login) AS student_name,
        u.login AS student_login,
        t.title AS test_title,
        COALESCE(NULLIF(tch.full_name, ''), tch.login) AS teacher_name
    FROM attempts a
    JOIN users u ON a.student_id = u.id
    JOIN tests t ON a.test_id = t.id
    JOIN users tch ON t.teacher_id = tch.id
    $whereSql
    ORDER BY a.completed_at DESC, a.id DESC
";

$stmtAttempts = $pdo->prepare($sqlAttempts);
$stmtAttempts->execute($params);
$attempts = $stmtAttempts->fetchAll();


if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="testing_report_' . date('Y-m-d_His') . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, ['ID попытки', 'Студент', 'Логин', 'Тест', 'Преподаватель', 'Балл (%)', 'Оценка', 'Дата прохождения'], ';');

    foreach ($attempts as $row) {
        [$gradeText] = formatGrade((float)$row['score']);
        fputcsv($out, [
            $row['id'],
            $row['student_name'],
            $row['student_login'],
            $row['test_title'],
            $row['teacher_name'],
            number_format((float)$row['score'], 2, ',', ''),
            $gradeText,
            $row['completed_at'] ?? '-'
        ], ';');
    }
    fclose($out);
    exit;
}

$stats = [];
if ($canManage) {
    $statsWhere = $isTeacher ? "WHERE t.teacher_id = ?" : "";
    $sqlStats = "
        SELECT 
            t.id,
            t.title,
            COUNT(a.id) AS total_attempts,
            ROUND(AVG(a.score), 2) AS avg_score,
            MAX(a.score) AS max_score
        FROM tests t
        LEFT JOIN attempts a ON t.id = a.test_id
        $statsWhere
        GROUP BY t.id, t.title
        ORDER BY t.id
    ";
    $stmtStats = $pdo->prepare($sqlStats);
    $stmtStats->execute($isTeacher ? [$userId] : []);
    $stats = $stmtStats->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title><?= $canManage ? 'Ведомость и отчеты' : 'Мои результаты' ?> — TestingSystem</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print {
            @page { size: landscape; margin: 10mm; }
            .no-print { display: none !important; }
            body { background: #fff !important; font-size: 12px; }
            .container { max-width: 100% !important; width: 100% !important; padding: 0 !important; }
            .card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
            table { width: 100% !important; font-size: 12px; }
            th, td { padding: 4px 6px !important; }
            .badge {
                color: #000 !important;
                background: transparent !important;
                border: 1px solid #666 !important;
                font-weight: 600;
            }
        }
    </style>
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4 no-print">
    <div class="container">
        <a class="navbar-brand" href="index.php">← На главную</a>
        <span class="navbar-text text-white">
            <?= $canManage ? 'Сводная ведомость результатов' : 'Мои результаты тестирования' ?>
        </span>
    </div>
</nav>

<div class="container">
    <?php if ($canManage): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-bold">Сводная статистика по тестам</div>
            <div class="card-body p-0">
                <table class="table table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Тест</th>
                            <th class="text-center">Всего попыток</th>
                            <th class="text-center">Средний балл</th>
                            <th class="text-center">Максимальный балл</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($stats)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">Тестов пока нет.</td></tr>
                        <?php else: ?>
                            <?php foreach ($stats as $s): ?>
                                <tr>
                                    <td><?= htmlspecialchars($s['title']) ?></td>
                                    <td class="text-center"><?= (int)$s['total_attempts'] ?></td>
                                    <td class="text-center"><?= $s['avg_score'] !== null ? $s['avg_score'] . '%' : '—' ?></td>
                                    <td class="text-center"><?= $s['max_score'] !== null ? $s['max_score'] . '%' : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
        <?php if ($canManage): ?>
            <form method="GET" class="d-flex gap-2">
                <select name="test_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Все доступные тесты</option>
                    <?php foreach ($availableTests as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= $selectedTestId === (int)$t['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($selectedTestId !== null): ?>
                    <a href="reports.php" class="btn btn-sm btn-outline-secondary">Сбросить</a>
                <?php endif; ?>
            </form>
        <?php else: ?>
            <h5 class="mb-0">История ваших попыток</h5>
        <?php endif; ?>

        <div class="d-flex gap-2">
            <a href="?export=csv<?= $selectedTestId !== null ? '&test_id=' . $selectedTestId : '' ?>" class="btn btn-sm btn-success">
                Скачать ведомость (CSV)
            </a>
            <button onclick="window.print()" class="btn btn-sm btn-outline-dark">Печать</button>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <table class="table table-bordered table-hover bg-white mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width: 60px;">ID</th>
                        <?php if ($canManage): ?>
                            <th>Студент</th>
                        <?php endif; ?>
                        <th>Тест</th>
                        <th>Преподаватель</th>
                        <th class="text-center" style="width: 120px;">Балл</th>
                        <th class="text-center" style="width: 180px;">Оценка</th>
                        <th style="width: 170px;">Дата</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($attempts)): ?>
                        <tr>
                            <td colspan="<?= $canManage ? 7 : 6 ?>" class="text-center text-muted py-4">
                                Записей о прохождении тестов пока нет.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($attempts as $row): 
                            [$gradeLabel, $gradeBadge] = formatGrade((float)$row['score']);
                        ?>
                            <tr>
                                <td><?= $row['id'] ?></td>
                                <?php if ($canManage): ?>
                                    <td>
                                        <?= htmlspecialchars($row['student_name']) ?>
                                        <small class="text-muted d-block"><?= htmlspecialchars($row['student_login']) ?></small>
                                    </td>
                                <?php endif; ?>
                                <td><?= htmlspecialchars($row['test_title']) ?></td>
                                <td><?= htmlspecialchars($row['teacher_name']) ?></td>
                                <td class="text-center fw-bold"><?= number_format((float)$row['score'], 2) ?>%</td>
                                <td class="text-center">
                                    <span class="badge <?= $gradeBadge ?>"><?= $gradeLabel ?></span>
                                </td>
                                <td><?= htmlspecialchars($row['completed_at'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
