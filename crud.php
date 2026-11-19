<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role_name'], ['admin', 'teacher'], true)) {
    die('Доступ запрещен.');
}

$isAdmin     = ($_SESSION['role_name'] === 'admin');
$currentUser = (int)$_SESSION['user_id'];

// Учителю доступны только вкладки tests, questions, options
$allowedTabs = $isAdmin
    ? ['users', 'tests', 'questions', 'options', 'attempts']
    : ['tests', 'questions', 'options'];

$tab = $_GET['tab'] ?? ($isAdmin ? 'users' : 'tests');
if (!in_array($tab, $allowedTabs, true)) {
    die('У вас нет прав для доступа к этому разделу.');
}

$msg = '';
$err = '';


function checkTestOwnership(PDO $pdo, int $testId, int $teacherId): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tests WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$testId, $teacherId]);
    return (int)$stmt->fetchColumn() > 0;
}


function checkQuestionOwnership(PDO $pdo, int $questionId, int $teacherId): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM questions q 
        JOIN tests t ON q.test_id = t.id 
        WHERE q.id = ? AND t.teacher_id = ?
    ");
    $stmt->execute([$questionId, $teacherId]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';


        if ($tab === 'users' && $isAdmin) {

            $adminRoleId = (int)$pdo->query("SELECT id FROM roles WHERE name = 'admin' LIMIT 1")->fetchColumn();

            if ($action === 'create') {
                if ((int)$_POST['role_id'] === $adminRoleId) {
                    throw new Exception("Нельзя создать второго администратора.");
                }
                $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (login, full_name, password_hash, role_id) VALUES (?, ?, ?, ?)");
                $stmt->execute([trim($_POST['login']), trim($_POST['full_name']), $hash, $_POST['role_id']]);
                $msg = "Пользователь добавлен (сработал триггер trg_after_insert_user).";
            } elseif ($action === 'update') {
                if ((int)$_POST['role_id'] === $adminRoleId) {
                    throw new Exception("Нельзя назначить роль администратора.");
                }

                $stmt = $pdo->prepare("UPDATE users SET login = ?, full_name = ?, role_id = ? WHERE id = ? AND role_id != ?");
                $stmt->execute([trim($_POST['login']), trim($_POST['full_name']), $_POST['role_id'], $_POST['id'], $adminRoleId]);
                $msg = "Данные пользователя обновлены.";
            } elseif ($action === 'delete') {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role_id != ?");
                $stmt->execute([$_POST['id'], $adminRoleId]);
                $msg = "Пользователь удален (сработал триггер trg_after_delete_user).";
            }
        }


        if ($tab === 'tests') {
            if ($action === 'create') {
                $authorId = $isAdmin ? (int)$_POST['teacher_id'] : $currentUser;
                $stmt = $pdo->prepare("INSERT INTO tests (title, teacher_id) VALUES (?, ?)");
                $stmt->execute([trim($_POST['title']), $authorId]);
                $msg = "Тест добавлен.";
            } elseif ($action === 'update') {
                $testId = (int)$_POST['id'];
                if ($isAdmin) {
                    $stmt = $pdo->prepare("UPDATE tests SET title = ?, teacher_id = ? WHERE id = ?");
                    $stmt->execute([trim($_POST['title']), (int)$_POST['teacher_id'], $testId]);
                } else {
                    if (!checkTestOwnership($pdo, $testId, $currentUser)) {
                        throw new Exception("Доступ запрещен: это не ваш тест.");
                    }
                    $stmt = $pdo->prepare("UPDATE tests SET title = ? WHERE id = ? AND teacher_id = ?");
                    $stmt->execute([trim($_POST['title']), $testId, $currentUser]);
                }
                $msg = "Тест обновлен.";
            } elseif ($action === 'delete') {
                $testId = (int)$_POST['id'];
                if ($isAdmin) {
                    $stmt = $pdo->prepare("DELETE FROM tests WHERE id = ?");
                    $stmt->execute([$testId]);
                } else {
                    if (!checkTestOwnership($pdo, $testId, $currentUser)) {
                        throw new Exception("Доступ запрещен: нельзя удалить чужой тест.");
                    }
                    $stmt = $pdo->prepare("DELETE FROM tests WHERE id = ? AND teacher_id = ?");
                    $stmt->execute([$testId, $currentUser]);
                }
                $msg = "Тест удален.";
            }
        }


        if ($tab === 'questions') {
            if ($action === 'create') {
                $testId = (int)$_POST['test_id'];
                if (!$isAdmin && !checkTestOwnership($pdo, $testId, $currentUser)) {
                    throw new Exception("Нельзя добавлять вопросы в чужой тест.");
                }
                $stmt = $pdo->prepare("INSERT INTO questions (test_id, question_text, question_type) VALUES (?, ?, ?)");
                $stmt->execute([$testId, trim($_POST['question_text']), $_POST['question_type']]);
                $msg = "Вопрос добавлен.";
            } elseif ($action === 'update') {
                $qId    = (int)$_POST['id'];
                $testId = (int)$_POST['test_id'];
                if (!$isAdmin && (!checkQuestionOwnership($pdo, $qId, $currentUser) || !checkTestOwnership($pdo, $testId, $currentUser))) {
                    throw new Exception("Доступ запрещен: вопрос или тест вам не принадлежат.");
                }
                $stmt = $pdo->prepare("UPDATE questions SET test_id = ?, question_text = ?, question_type = ? WHERE id = ?");
                $stmt->execute([$testId, trim($_POST['question_text']), $_POST['question_type'], $qId]);
                $msg = "Вопрос обновлен.";
            } elseif ($action === 'delete') {
                $qId = (int)$_POST['id'];
                if (!$isAdmin && !checkQuestionOwnership($pdo, $qId, $currentUser)) {
                    throw new Exception("Доступ запрещен: нельзя удалить вопрос из чужого теста.");
                }
                $stmt = $pdo->prepare("DELETE FROM questions WHERE id = ?");
                $stmt->execute([$qId]);
                $msg = "Вопрос удален.";
            }
        }


        if ($tab === 'options') {
            if ($action === 'create') {
                $qId = (int)$_POST['question_id'];
                if (!$isAdmin && !checkQuestionOwnership($pdo, $qId, $currentUser)) {
                    throw new Exception("Нельзя добавлять варианты ответа к чужому вопросу.");
                }
                $isCorrect = isset($_POST['is_correct']) ? 1 : 0;
                $stmt = $pdo->prepare("INSERT INTO answer_options (question_id, option_text, is_correct) VALUES (?, ?, ?)");
                $stmt->execute([$qId, trim($_POST['option_text']), $isCorrect]);
                $msg = "Вариант ответа добавлен.";
            } elseif ($action === 'update') {
                $optId = (int)$_POST['id'];
                $qId   = (int)$_POST['question_id'];
                if (!$isAdmin) {
                    $chk = $pdo->prepare("
                        SELECT COUNT(*) FROM answer_options ao
                        JOIN questions q ON ao.question_id = q.id
                        JOIN tests t ON q.test_id = t.id
                        WHERE ao.id = ? AND t.teacher_id = ?
                    ");
                    $chk->execute([$optId, $currentUser]);
                    if ((int)$chk->fetchColumn() === 0 || !checkQuestionOwnership($pdo, $qId, $currentUser)) {
                        throw new Exception("Доступ запрещен: это не ваш вопрос.");
                    }
                }
                $isCorrect = isset($_POST['is_correct']) ? 1 : 0;
                $stmt = $pdo->prepare("UPDATE answer_options SET question_id = ?, option_text = ?, is_correct = ? WHERE id = ?");
                $stmt->execute([$qId, trim($_POST['option_text']), $isCorrect, $optId]);
                $msg = "Вариант ответа обновлен.";
            } elseif ($action === 'delete') {
                $optId = (int)$_POST['id'];
                if (!$isAdmin) {
                    $chk = $pdo->prepare("
                        SELECT COUNT(*) FROM answer_options ao
                        JOIN questions q ON ao.question_id = q.id
                        JOIN tests t ON q.test_id = t.id
                        WHERE ao.id = ? AND t.teacher_id = ?
                    ");
                    $chk->execute([$optId, $currentUser]);
                    if ((int)$chk->fetchColumn() === 0) {
                        throw new Exception("Доступ запрещен: нельзя удалить чужой вариант ответа.");
                    }
                }
                $stmt = $pdo->prepare("DELETE FROM answer_options WHERE id = ?");
                $stmt->execute([$optId]);
                $msg = "Вариант ответа удален.";
            }
        }


        if ($tab === 'attempts' && $isAdmin) {
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
} catch (Exception $e) {
    $err = $e->getMessage();
}


$roles = $pdo->query("SELECT * FROM roles WHERE name != 'admin'")->fetchAll();
$teachers = $pdo->query("SELECT u.* FROM users u JOIN roles r ON u.role_id = r.id WHERE r.name = 'teacher'")->fetchAll();
$students = $pdo->query("SELECT u.* FROM users u JOIN roles r ON u.role_id = r.id WHERE r.name = 'student'")->fetchAll();

if ($isAdmin) {
    $testsList = $pdo->query("SELECT * FROM tests ORDER BY id")->fetchAll();
    $questList = $pdo->query("SELECT * FROM questions ORDER BY id")->fetchAll();
} else {
    $stmtT = $pdo->prepare("SELECT * FROM tests WHERE teacher_id = ? ORDER BY id");
    $stmtT->execute([$currentUser]);
    $testsList = $stmtT->fetchAll();

    $stmtQ = $pdo->prepare("SELECT q.* FROM questions q JOIN tests t ON q.test_id = t.id WHERE t.teacher_id = ? ORDER BY q.id");
    $stmtQ->execute([$currentUser]);
    $questList = $stmtQ->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Управление данными (CRUD)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="index.php">← На главную</a>
        <span class="navbar-text text-white">
            Управление (<?= $isAdmin ? 'Администратор: полный доступ' : 'Преподаватель: только свои тесты' ?>)
        </span>
    </div>
</nav>

<div class="container">
    <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <ul class="nav nav-tabs mb-4">
        <?php if ($isAdmin): ?>
            <li class="nav-item"><a class="nav-link <?= $tab==='users'?'active':'' ?>" href="?tab=users">Пользователи (users)</a></li>
        <?php endif; ?>
        <li class="nav-item"><a class="nav-link <?= $tab==='tests'?'active':'' ?>" href="?tab=tests">Тесты (tests)</a></li>
        <li class="nav-item"><a class="nav-link <?= $tab==='questions'?'active':'' ?>" href="?tab=questions">Вопросы (questions)</a></li>
        <li class="nav-item"><a class="nav-link <?= $tab==='options'?'active':'' ?>" href="?tab=options">Варианты ответов</a></li>
        <?php if ($isAdmin): ?>
            <li class="nav-item"><a class="nav-link <?= $tab==='attempts'?'active':'' ?>" href="?tab=attempts">Попытки (attempts)</a></li>
        <?php endif; ?>
    </ul>

    <?php if ($tab === 'users' && $isAdmin): ?>
        <form method="POST" class="row g-2 mb-4 bg-white p-3 rounded shadow-sm">
            <input type="hidden" name="action" value="create">
            <div class="col-md-3"><input type="text" name="full_name" class="form-control" placeholder="ФИО" required></div>
            <div class="col-md-3"><input type="text" name="login" class="form-control" placeholder="Логин" required></div>
            <div class="col-md-2"><input type="text" name="password" class="form-control" placeholder="Пароль" required></div>
            <div class="col-md-2">
                <select name="role_id" class="form-select">
                    <?php foreach ($roles as $r): ?><option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2"><button class="btn btn-primary w-100">Добавить</button></div>
        </form>
        <table class="table table-bordered bg-white">
            <tr><th>ID</th><th>ФИО</th><th>Логин</th><th>Роль</th><th>Действия</th></tr>
            <?php
            $rows = $pdo->query("
                SELECT u.*, r.name AS role_name 
                FROM users u 
                JOIN roles r ON u.role_id = r.id 
                WHERE r.name != 'admin' 
                ORDER BY u.id
            ")->fetchAll();
            foreach ($rows as $row): ?>
            <tr>
                <form method="POST">
                    <td><?= $row['id'] ?><input type="hidden" name="id" value="<?= $row['id'] ?>"></td>
                    <td><input type="text" name="full_name" value="<?= htmlspecialchars($row['full_name']) ?>" class="form-control form-control-sm"></td>
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
            <div class="<?= $isAdmin ? 'col-md-6' : 'col-md-10' ?>">
                <input type="text" name="title" class="form-control" placeholder="Название теста" required>
            </div>
            <?php if ($isAdmin): ?>
                <div class="col-md-4">
                    <select name="teacher_id" class="form-select">
                        <?php foreach ($teachers as $t): ?>
                            <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['full_name'] ?: $t['login']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="col-md-2"><button class="btn btn-primary w-100">Создать тест</button></div>
        </form>
        <table class="table table-bordered bg-white">
            <tr><th>ID</th><th>Название теста</th><?php if ($isAdmin): ?><th>Автор (преподаватель)</th><?php endif; ?><th>Действия</th></tr>
            <?php
            if ($isAdmin) {
                $rows = $pdo->query("SELECT t.*, u.login, u.full_name FROM tests t JOIN users u ON t.teacher_id = u.id ORDER BY t.id")->fetchAll();
            } else {
                $stmt = $pdo->prepare("SELECT * FROM tests WHERE teacher_id = ? ORDER BY id");
                $stmt->execute([$currentUser]);
                $rows = $stmt->fetchAll();
            }
            foreach ($rows as $row): ?>
            <tr>
                <form method="POST">
                    <td><?= $row['id'] ?><input type="hidden" name="id" value="<?= $row['id'] ?>"></td>
                    <td><input type="text" name="title" value="<?= htmlspecialchars($row['title']) ?>" class="form-control form-control-sm"></td>
                    <?php if ($isAdmin): ?>
                        <td>
                            <select name="teacher_id" class="form-select form-select-sm">
                                <?php foreach ($teachers as $t): ?>
                                    <option value="<?= $t['id'] ?>" <?= $t['id']==$row['teacher_id']?'selected':'' ?>>
                                        <?= htmlspecialchars($t['full_name'] ?: $t['login']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    <?php endif; ?>
                    <td class="d-flex gap-1">
                        <button name="action" value="update" class="btn btn-sm btn-warning">Сохранить</button>
                        <button name="action" value="delete" class="btn btn-sm btn-danger" onclick="return confirm('Удалить тест?')">Удалить</button>
                    </td>
                </form>
            </tr>
            <?php endforeach; ?>
        </table>

    <?php elseif ($tab === 'questions'): ?>
        <form method="POST" class="row g-2 mb-4 bg-white p-3 rounded shadow-sm">
            <input type="hidden" name="action" value="create">
            <div class="col-md-3">
                <select name="test_id" class="form-select" required>
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
            <?php foreach ($questList as $row): ?>
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
                <select name="question_id" class="form-select" required>
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
            if ($isAdmin) {
                $rows = $pdo->query("SELECT * FROM answer_options ORDER BY question_id, id")->fetchAll();
            } else {
                $stmtO = $pdo->prepare("
                    SELECT ao.* FROM answer_options ao
                    JOIN questions q ON ao.question_id = q.id
                    JOIN tests t ON q.test_id = t.id
                    WHERE t.teacher_id = ?
                    ORDER BY ao.question_id, ao.id
                ");
                $stmtO->execute([$currentUser]);
                $rows = $stmtO->fetchAll();
            }
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

    <?php elseif ($tab === 'attempts' && $isAdmin): ?>
        <form method="POST" class="row g-2 mb-4 bg-white p-3 rounded shadow-sm">
            <input type="hidden" name="action" value="create">
            <div class="col-md-4">
                <select name="student_id" class="form-select">
                    <?php foreach ($students as $s): ?><option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['full_name'] ?: $s['login']) ?></option><?php endforeach; ?>
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
            $rows = $pdo->query("SELECT a.*, u.login, u.full_name, t.title FROM attempts a JOIN users u ON a.student_id=u.id JOIN tests t ON a.test_id=t.id ORDER BY a.id DESC")->fetchAll();
            foreach ($rows as $row): ?>
            <tr>
                <form method="POST">
                    <td><?= $row['id'] ?><input type="hidden" name="id" value="<?= $row['id'] ?>"></td>
                    <td><?= htmlspecialchars($row['full_name'] ?: $row['login']) ?></td>
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
