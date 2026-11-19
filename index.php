<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$isTeacher = ($_SESSION['role_name'] === 'teacher');
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
                <strong><?= htmlspecialchars($_SESSION['login']) ?></strong> 
                <span class="badge bg-<?= $isTeacher ? 'warning text-dark' : 'info' ?>">
                    <?= htmlspecialchars($_SESSION['role_name']) ?>
                </span>
            </span>
            <a href="logout.php" class="btn btn-outline-light btn-sm">Выйти</a>
        </div>
    </div>
</nav>

<div class="container">
    <div class="row g-4">
        <?php if ($isTeacher): ?>
            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title">1. Управление данными (CRUD)</h5>
                        <p class="card-text">Пользователи (срабатывание триггеров), тесты, вопросы, варианты ответов и попытки.</p>
                        <a href="crud.php" class="btn btn-primary">Открыть справочники</a>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title">2. Аналитика и SQL-запросы</h5>
                        <p class="card-text">Сложные выборки, представления, вызов функции <code>GetGrade</code> и процедуры <code>UpdateLogStatus</code>.</p>
                        <a href="queries.php" class="btn btn-success">Открыть запросы</a>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title">3. Журнал триггеров и отчеты</h5>
                        <p class="card-text">Аудит <code>users_log</code> и выгрузка сводной ведомости в CSV / на печать.</p>
                        <a href="reports.php" class="btn btn-secondary">Открыть отчеты</a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="col-md-6">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title">Пройти тестирование</h5>
                        <p class="card-text">Выбор доступного теста и транзакционная отправка ответов с расчетом баллов.</p>
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
