<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role_name'] !== 'teacher') {
    die('Доступ запрещен. Только для преподавателей.');
}

$tab = $_GET['tab'] ?? 'users';
$msg = '';
$err = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        // --- 1. USERS (тут срабатывают триггеры INSERT/DELETE -> users_log) ---
        if ($tab === 'users') {
            if ($action === 'create') {
                $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (login, password_hash, role_id) VALUES (?, ?, ?)");
                $stmt->execute([trim($_POST['login']), $hash, $_POST['role_id']]);
                $msg = "Пользователь добавлен (триггер trg_after_insert_user записал событие в users_log).";
            } elseif ($action === 'update') {
                $stmt = $pdo->prepare("UPDATE users SET login = ?, role_id = ? WHERE id = ?");
                $stmt->execute([trim($_POST['login']), $_POST['role_id'], $_POST['id']]);
                $msg = "Пользователь обновлен.";
            } elseif ($action === 'delete') {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                $msg = "Пользователь удален (триггер trg_after_delete_user записал событие в users_log).";
            }
        }

        // --- 2. TESTS ---
        if ($tab === 'tests') {
            if ($action === 'create') {
                $stmt = $pdo->prepare("INSERT INTO tests (title, teacher_id) VALUES (?, ?)");
                $stmt->execute([trim($_POST['title']), $_POST['teacher_id']]);
                $msg = "Тест добавлен.";
            } elseif ($action === 'update') {
                $stmt = $pdo->prepare("UPDATE tests SET title = ?, teacher_id = ? WHERE id = ?");
                $stmt->execute([trim($_POST['title']), $_POST['teacher_id'], $_POST['id']]);
                $msg = "Тест обновлен.";
            } elseif ($action === 'delete') {
                $stmt = $pdo->prepare("DELETE FROM tests WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                $msg = "Тест удален.";
            }
        }

        // --- 3. QUESTIONS ---
        if ($tab === 'questions') {
            if ($action === 'create') {
                $stmt = $pdo->prepare("INSERT INTO questions (test_id, question_text, question_type) VALUES (?, ?, ?)");
                $stmt->execute([$_POST['test_id'], trim($_POST['question_text']), $_POST['question_type']]);
                $msg = "Вопрос добавлен.";
            } elseif ($action === 'update') {
                $stmt = $pdo->prepare("UPDATE questions SET test_id = ?, question_text = ?, question_type = ? WHERE id = ?");
                $stmt->execute([$_POST['test_id'], trim($_POST['question_text']), $_POST['question_type'], $_POST['id']]);
                $msg = "Вопрос обновлен.";
            } elseif ($action === 'delete') {
                $stmt = $pdo->prepare("DELETE FROM questions WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                $msg = "Вопрос удален.";
            }
        }

        // --- 4. ANSWER_OPTIONS ---
        if ($tab === 'options') {
            if ($action === 'create') {
                $isCorrect = isset($_POST['is_correct']) ? 1 : 0;
                $stmt = $pdo->prepare("INSERT INTO answer_options (question_id, option_text, is_correct) VALUES (?, ?, ?)");
                $stmt->execute([$_POST['question_id'], trim($_POST['option_text']), $isCorrect]);
                $msg = "Вариант ответа добавлен.";
            } elseif ($action === 'update') {
                $isCorrect = isset($_POST['is_correct']) ? 1 : 0;
                $stmt = $pdo->prepare("UPDATE answer_options SET question_id = ?, option_text = ?, is_correct = ? WHERE id = ?");
                $stmt->execute([$_POST['question_id'], trim($_POST['option_text']), $isCorrect, $_POST['id']]);
                $msg = "Вариант ответа обновлен.";
            } elseif ($action === 'delete') {
                $stmt = $pdo->prepare("DELETE FROM answer_options WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                $msg = "Вариант ответа удален.";
            }
        }

        // --- 5. ATTEMPTS ---
        if ($tab === 'attempts') {
            if ($action === 'create') {
                $stmt = $pdo->prepare("INSERT INTO attempts (student_id, test_id, score) VALUES (?, ?, ?)");
                $stmt->execute([$_POST['student_id'], $_POST['test_id'], $_POST['score']]);
                $msg = "Попытка добавлена.";
            } elseif ($action === 'update') {
                $stmt = $pdo->prepare("UPDATE attempts SET score = ? WHERE id = ?");
                $stmt->execute([$_POST['score'], $_POST['id']]);
                $msg = "Балл попытки обновлен.";
            } elseif ($action === 'delete') {
                $stmt = $pdo->prepare("DELETE FROM attempts WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                $msg = "Попытка удалена.";
            }
        }
    }
} catch (PDOException $e) {
    $err = "Ошибка БД (возможно, нарушение целостности внешних ключей): " . $e->getMessage();
}

$roles     = $pdo->query("SELECT * FROM roles")->fetchAll();
$teachers  = $pdo->query("SELECT * FROM users WHERE role_id = 1")->fetchAll();
$students  = $pdo->query("SELECT * FROM users WHERE role_id = 2")->fetchAll();
$testsList = $pdo->query("SELECT * FROM tests")->fetchAll();
$questList = $pdo->query("SELECT * FROM questions")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>CRUD Управление</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="index.php">← На главную</a>
        <span class="navbar-text text-white">Управление сущностями (CRUD)</span>
    </div>
</nav>

<div class="container">
    <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <ul class="nav nav-tabs mb-4">
        <li class="nav-item"><a class="nav-link <?= $tab==='users'?'active':'' ?>" href="?tab=users">1. Пользователи (users)</a></li>
        <li class="nav-item"><a class="nav-link <?= $tab==='tests'?'active':'' ?>" href="?tab=tests">2. Тесты (tests)</a></li>
        <li class="nav-item"><a class="nav-link <?= $tab==='questions'?'active':'' ?>" href="?tab=questions">3. Вопросы (questions)</a></li>
        <li class="nav-item"><a class="nav-link <?= $tab==='options'?'active':'' ?>" href="?tab=options">4. Варианты ответов</a></li>
        <li class="nav-item"><a class="nav-link <?= $tab==='attempts'?'active':'' ?>" href="?tab=attempts">5. Попытки (attempts)</a></li>
    </ul>

    <?php if ($tab === 'users'): ?>
        <form method="POST" class="row g-2 mb-4 bg-white p-3 rounded shadow-sm">
            <input type="hidden" name="action" value="create">
            <div class="col-md-4"><input type="text" name="login" class="form-control" placeholder="Новый логин" required></div>
            <div class="col-md-3"><input type="text" name="password" class="form-control" placeholder="Пароль" required></div>
            <div class="col-md-3">
                <select name="role_id" class="form-select">
                    <?php foreach ($roles as $r): ?><option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2"><button class="btn btn-primary w-100">Добавить</button></div>
        </form>
        <table class="table table-bordered bg-white">
            <tr><th>ID</th><th>Логин</th><th>Роль</th><th>Действия</th></tr>
            <?php
            $rows = $pdo->query("SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON u.role_id = r.id ORDER BY u.id")->fetchAll();
            foreach ($rows as $row): ?>
            <tr>
                <form method="POST">
                    <td><?= $row['id'] ?><input type="hidden" name="id" value="<?= $row['id'] ?>"></td>
                    <td><input type="text" name="login" value="<?= htmlspecialchars($row['login']) ?>" class="form-control form-control-sm"></td>
                    <td>
                        <select name="role_id" class="form-select form-select-sm">
                            <?php foreach ($roles as $r): ?>
                                <option value="<?= $r['id'] ?>" <?= $r['id']==$row['role_id']?'selected':'' ?>><?= htmlspecialchars($r['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td class="d-flex gap-1">
                        <button name="action" value="update" class="btn btn-sm btn-warning">Сохранить</button>
                        <button name="action" value="delete" class="btn btn-sm btn-danger" onclick="return confirm('Удалить?')">Удалить</button>
                    </td>
                </form>
            </tr>
            <?php endforeach; ?>
        </table>

    <?php elseif ($tab === 'tests'): ?>
        <form method="POST" class="row g-2 mb-4 bg-white p-3 rounded shadow-sm">
            <input type="hidden" name="action" value="create">
            <div class="col-md-6"><input type="text" name="title" class="form-control" placeholder="Название теста" required></div>
            <div class="col-md-4">
                <select name="teacher_id" class="form-select">
                    <?php foreach ($teachers as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['login']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2"><button class="btn btn-primary w-100">Добавить</button></div>
        </form>
        <table class="table table-bordered bg-white">
            <tr><th>ID</th><th>Название теста</th><th>Преподаватель</th><th>Действия</th></tr>
            <?php
            $rows = $pdo->query("SELECT t.*, u.login FROM tests t JOIN users u ON t.teacher_id = u.id ORDER BY t.id")->fetchAll();
            foreach ($rows as $row): ?>
            <tr>
                <form method="POST">
                    <td><?= $row['id'] ?><input type="hidden" name="id" value="<?= $row['id'] ?>"></td>
                    <td><input type="text" name="title" value="<?= htmlspecialchars($row['title']) ?>" class="form-control form-control-sm"></td>
                    <td>
                        <select name="teacher_id" class="form-select form-select-sm">
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?= $t['id'] ?>" <?= $t['id']==$row['teacher_id']?'selected':'' ?>><?= htmlspecialchars($t['login']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td class="d-flex gap-1">
                        <button name="action" value="update" class="btn btn-sm btn-warning">Сохранить</button>
                        <button name="action" value="delete" class="btn btn-sm btn-danger" onclick="return confirm('Удалить?')">Удалить</button>
                    </td>
                </form>
            </tr>
            <?php endforeach; ?>
        </table>

    <?php elseif ($tab === 'questions'): ?>
        <form method="POST" class="row g-2 mb-4 bg-white p-3 rounded shadow-sm">
            <input type="hidden" name="action" value="create">
            <div class="col-md-3">
                <select name="test_id" class="form-select">
                    <?php foreach ($testsList as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['title']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5"><input type="text" name="question_text" class="form-control" placeholder="Текст вопроса" required></div>
            <div class="col-md-2">
                <select name="question_type" class="form-select">
                    <option value="single_choice">single_choice</option>
                    <option value="multiple_choice">multiple_choice</option>
                    <option value="text_input">text_input</option>
                </select>
            </div>
            <div class="col-md-2"><button class="btn btn-primary w-100">Добавить</button></div>
        </form>
        <table class="table table-bordered bg-white">
            <tr><th>ID</th><th>Тест</th><th>Вопрос</th><th>Тип</th><th>Действия</th></tr>
            <?php
            $rows = $pdo->query("SELECT * FROM questions ORDER BY id")->fetchAll();
            foreach ($rows as $row): ?>
            <tr>
                <form method="POST">
                    <td><?= $row['id'] ?><input type="hidden" name="id" value="<?= $row['id'] ?>"></td>
                    <td>
                        <select name="test_id" class="form-select form-select-sm">
                            <?php foreach ($testsList as $t): ?>
                                <option value="<?= $t['id'] ?>" <?= $t['id']==$row['test_id']?'selected':'' ?>><?= htmlspecialchars($t['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="text" name="question_text" value="<?= htmlspecialchars($row['question_text']) ?>" class="form-control form-control-sm"></td>
                    <td>
                        <select name="question_type" class="form-select form-select-sm">
                            <?php foreach (['single_choice','multiple_choice','text_input'] as $qt): ?>
                                <option value="<?= $qt ?>" <?= $qt===$row['question_type']?'selected':'' ?>><?= $qt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td class="d-flex gap-1">
                        <button name="action" value="update" class="btn btn-sm btn-warning">Сохранить</button>
                        <button name="action" value="delete" class="btn btn-sm btn-danger" onclick="return confirm('Удалить?')">Удалить</button>
                    </td>
                </form>
            </tr>
            <?php endforeach; ?>
        </table>

    <?php elseif ($tab === 'options'): ?>
        <form method="POST" class="row g-2 mb-4 bg-white p-3 rounded shadow-sm">
            <input type="hidden" name="action" value="create">
            <div class="col-md-4">
                <select name="question_id" class="form-select">
                    <?php foreach ($questList as $q): ?><option value="<?= $q['id'] ?>">#<?= $q['id'] ?> <?= htmlspecialchars(mb_substr($q['question_text'],0,40)) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4"><input type="text" name="option_text" class="form-control" placeholder="Текст ответа" required></div>
            <div class="col-md-2 d-flex align-items-center">
                <div class="form-check"><input type="checkbox" name="is_correct" class="form-check-input" id="ic"><label class="form-check-label" for="ic">Верный</label></div>
            </div>
            <div class="col-md-2"><button class="btn btn-primary w-100">Добавить</button></div>
        </form>
        <table class="table table-bordered bg-white">
            <tr><th>ID</th><th>Вопрос</th><th>Вариант</th><th>Верный?</th><th>Действия</th></tr>
            <?php
            $rows = $pdo->query("SELECT * FROM answer_options ORDER BY question_id, id")->fetchAll();
            foreach ($rows as $row): ?>
            <tr>
                <form method="POST">
                    <td><?= $row['id'] ?><input type="hidden" name="id" value="<?= $row['id'] ?>"></td>
                    <td>
                        <select name="question_id" class="form-select form-select-sm">
                            <?php foreach ($questList as $q): ?>
                                <option value="<?= $q['id'] ?>" <?= $q['id']==$row['question_id']?'selected':'' ?>>#<?= $q['id'] ?> <?= htmlspecialchars(mb_substr($q['question_text'],0,30)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="text" name="option_text" value="<?= htmlspecialchars($row['option_text']) ?>" class="form-control form-control-sm"></td>
                    <td class="text-center"><input type="checkbox" name="is_correct" <?= $row['is_correct']?'checked':'' ?>></td>
                    <td class="d-flex gap-1">
                        <button name="action" value="update" class="btn btn-sm btn-warning">Сохранить</button>
                        <button name="action" value="delete" class="btn btn-sm btn-danger" onclick="return confirm('Удалить?')">Удалить</button>
                    </td>
                </form>
            </tr>
            <?php endforeach; ?>
        </table>

    <?php elseif ($tab === 'attempts'): ?>
        <form method="POST" class="row g-2 mb-4 bg-white p-3 rounded shadow-sm">
            <input type="hidden" name="action" value="create">
            <div class="col-md-4">
                <select name="student_id" class="form-select">
                    <?php foreach ($students as $s): ?><option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['login']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <select name="test_id" class="form-select">
                    <?php foreach ($testsList as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['title']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2"><input type="number" step="0.01" name="score" class="form-control" placeholder="Балл (0-100)" required></div>
            <div class="col-md-2"><button class="btn btn-primary w-100">Добавить</button></div>
        </form>
        <table class="table table-bordered bg-white">
            <tr><th>ID</th><th>Студент</th><th>Тест</th><th>Балл</th><th>Действия</th></tr>
            <?php
            $rows = $pdo->query("SELECT a.*, u.login, t.title FROM attempts a JOIN users u ON a.student_id=u.id JOIN tests t ON a.test_id=t.id ORDER BY a.id DESC")->fetchAll();
            foreach ($rows as $row): ?>
            <tr>
                <form method="POST">
                    <td><?= $row['id'] ?><input type="hidden" name="id" value="<?= $row['id'] ?>"></td>
                    <td><?= htmlspecialchars($row['login']) ?></td>
                    <td><?= htmlspecialchars($row['title']) ?></td>
                    <td><input type="number" step="0.01" name="score" value="<?= $row['score'] ?>" class="form-control form-control-sm"></td>
                    <td class="d-flex gap-1">
                        <button name="action" value="update" class="btn btn-sm btn-warning">Сохранить</button>
                        <button name="action" value="delete" class="btn btn-sm btn-danger" onclick="return confirm('Удалить?')">Удалить</button>
                    </td>
                </form>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</div>
</body>
</html>
