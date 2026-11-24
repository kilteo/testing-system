<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$role      = $_SESSION['role_name'] ?? 'student';
$isAdmin   = ($role === 'admin');
$canManage = in_array($role, ['admin', 'teacher'], true);

$badgeClass = 'bg-info';
if ($role === 'admin') {
    $badgeClass = 'bg-danger';
} elseif ($role === 'teacher') {
    $badgeClass = 'bg-warning text-dark';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Главная — Система тестирования</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="index.php">TestingSystem</a>
        <div class="d-flex align-items-center text-white">
            <span class="me-3">
                <strong><?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['login']) ?></strong> 
                <span class="badge <?= $badgeClass ?>">
                    <?= htmlspecialchars($role) ?>
                </span>
            </span>
            <a href="logout.php" class="btn btn-outline-light btn-sm">Выйти</a>
        </div>
    </div>
</nav>

<div class="container">
    <div class="row g-4">
        <?php if ($canManage): ?>
            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title">1. Управление данными (CRUD)</h5>
                        <p class="card-text">
                            <?= $isAdmin 
                                ? 'Полный доступ: управление пользователями, тестами, вопросами и попытками.' 
                                : 'Создание и редактирование ваших тестов, вопросов, вариантов и просмотр попыток.' ?>
                        </p>
                        <a href="crud.php" class="btn btn-primary">Открыть справочники</a>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title">2. Аналитика и SQL-запросы</h5>
                        <p class="card-text">Статические и динамические запросы, VIEW, журнал триггеров, вызов GetGrade и UpdateLogStatus.</p>
                        <a href="queries.php" class="btn btn-success">Открыть запросы</a>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title">3. Результаты и ведомость</h5>
                        <p class="card-text">Просмотр результатов тестирования, статистика по тестам, печать и выгрузка в CSV.</p>
                        <a href="reports.php" class="btn btn-secondary">Открыть отчеты</a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="col-md-6">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title">Пройти тестирование</h5>
                        <p class="card-text">Выбор доступного теста, динамическое перемешивание и автопроверка.</p>
                        <a href="take_test.php" class="btn btn-primary">К списку тестов</a>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title">Мои результаты</h5>
                        <p class="card-text">История пройденных попыток, оценки и выгрузка справки.</p>
                        <a href="reports.php" class="btn btn-success">Смотреть результаты</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
