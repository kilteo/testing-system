<?php
session_start();
require_once 'db.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("
    SELECT u.id, u.login, u.full_name, u.password_hash, u.role_id, r.name AS role_name 
    FROM users u 
    JOIN roles r ON u.role_id = r.id 
    WHERE u.login = ?
    ");
    $stmt->execute([$login]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['login']     = $user['login'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role_id']   = $user['role_id'];
        $_SESSION['role_name'] = $user['role_name'];
        header('Location: index.php');
        exit;
    } else {
        $error = 'Неверный логин или пароль!';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Вход — Система тестирования</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center vh-100">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-4">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white text-center">
                    <h5 class="mb-0">Система тестирования</h5>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Логин</label>
                            <input type="text" name="login" class="form-control" required placeholder="Логин">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Пароль</label>
                            <input type="password" name="password" class="form-control" required placeholder="Пароль">
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Войти</button>
                        <a href="register.php" class="d-block text-end mt-3 text-primary text-decoration-underline small">Нет аккаунта? Регистрация.</a>
                    </form>
                </div>
                <div class="card-footer text-muted small">
                    Пароль для всех: <b>12345</b><br>
                    Преподаватель: <code>ivanov_teacher</code><br>
                    Студент: <code>petrov_student</code>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
