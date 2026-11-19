<?php
session_start();
require_once 'db.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $login    = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';
    $role     = $_POST['role'] ?? 'student';

    if (!in_array($role, ['student', 'teacher'], true)) {
        $role = 'student';
    }

    if ($fullName === '' || $login === '' || $password === '') {
        $error = 'Заполните все поля.';
    } else {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        try {
            $sql = "INSERT INTO users (full_name, login, password_hash, role_id)
                    VALUES (:full_name, :login, :password_hash, (SELECT id FROM roles WHERE name = :role_name LIMIT 1))";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':full_name'     => $fullName,
                ':login'         => $login,
                ':password_hash' => $hashedPassword,
                ':role_name'     => $role
            ]);

            header('Location: login.php?registered=1');
            exit;
        } catch (PDOException $e) {
            $error = 'Не удалось зарегистрироваться. Возможно, этот логин уже занят.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Регистрация — Система тестирования</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center vh-100">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-4">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white text-center">
                    <h5 class="mb-0">Регистрация в системе</h5>
                </div>
                <div class="card-body pb-2">
                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">ФИО</label>
                            <input type="text" name="full_name" class="form-control" required
                                   value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"
                                   placeholder="Иванов Иван Иванович">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Логин</label>
                            <input type="text" name="login" class="form-control" required
                                   value="<?= htmlspecialchars($_POST['login'] ?? '') ?>"
                                   placeholder="Придумайте логин">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Пароль</label>
                            <input type="password" name="password" class="form-control" required placeholder="Придумайте пароль">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Роль</label>
                            <select name="role" class="form-select" required>
                                <option value="student" <?= (($_POST['role'] ?? '') === 'student') ? 'selected' : '' ?>>Студент</option>
                                <option value="teacher" <?= (($_POST['role'] ?? '') === 'teacher') ? 'selected' : '' ?>>Преподаватель</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Зарегистрироваться</button>

                        <a href="login.php" class="d-block text-end mt-3 text-primary text-decoration-underline small">
                            Уже есть аккаунт? Войти.
                        </a>
                    </form>
                </div>
                <div class="card-footer text-muted small">
                    После регистрации вы сможете войти под своим логином и паролем.
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
