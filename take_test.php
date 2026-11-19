<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$testId = isset($_GET['test_id']) ? (int)$_GET['test_id'] : 0;
$resultMsg = '';
$errorMsg = '';


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_test'])) {
    $testId = (int)$_POST['test_id'];
    $answers = $_POST['answers'] ?? [];
    $typed   = $_POST['typed'] ?? [];

    try {
        $pdo->beginTransaction();

        $stmtAttempt = $pdo->prepare("INSERT INTO attempts (student_id, test_id, score) VALUES (?, ?, 0)");
        $stmtAttempt->execute([$_SESSION['user_id'], $testId]);
        $attemptId = $pdo->lastInsertId();

        $stmtQ = $pdo->prepare("SELECT * FROM questions WHERE test_id = ?");
        $stmtQ->execute([$testId]);
        $questions = $stmtQ->fetchAll();

        if (count($questions) === 0) {
            throw new Exception("В этом тесте пока нет вопросов.");
        }

        $correctCount = 0;
        $stmtInsertAns = $pdo->prepare("
            INSERT INTO student_answers (attempt_id, question_id, selected_option_id, typed_answer) 
            VALUES (?, ?, ?, ?)
        ");

        foreach ($questions as $q) {
            $qid = $q['id'];
            if ($q['question_type'] === 'text_input') {
                $txt = trim($typed[$qid] ?? '');
                $stmtInsertAns->execute([$attemptId, $qid, null, $txt]);
                $stmtChk = $pdo->prepare("SELECT option_text FROM answer_options WHERE question_id = ? AND is_correct = 1 LIMIT 1");
                $stmtChk->execute([$qid]);
                $right = $stmtChk->fetchColumn();
                if ($right !== false && mb_strtolower($txt) === mb_strtolower(trim($right))) {
                    $correctCount++;
                }
            } else {
                $selected = $answers[$qid] ?? [];
                if (!is_array($selected)) $selected = [$selected];

                foreach ($selected as $optId) {
                    $stmtInsertAns->execute([$attemptId, $qid, (int)$optId, null]);
                }

                $stmtRight = $pdo->prepare("SELECT id FROM answer_options WHERE question_id = ? AND is_correct = 1");
                $stmtRight->execute([$qid]);
                $rightIds = $stmtRight->fetchAll(PDO::FETCH_COLUMN);
                sort($rightIds);
                $selInt = array_map('intval', $selected);
                sort($selInt);

                if (!empty($rightIds) && $rightIds === $selInt) {
                    $correctCount++;
                }
            }
        }

        $score = round(($correctCount / count($questions)) * 100, 2);
        $stmtUpd = $pdo->prepare("UPDATE attempts SET score = ? WHERE id = ?");
        $stmtUpd->execute([$score, $attemptId]);

        $pdo->commit();
        $resultMsg = "Тест успешно пройден! Попытка #$attemptId сохранена. Правильных ответов: $correctCount из " . count($questions) . ". Итоговый балл: $score.";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errorMsg = "Ошибка при сохранении (выполнен ROLLBACK): " . $e->getMessage();
    }
}

$tests = $pdo->query("SELECT t.*, u.login AS teacher FROM tests t JOIN users u ON t.teacher_id = u.id")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Прохождение теста</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="index.php">← На главную</a>
        <span class="navbar-text text-white">Тестирование (транзакционная запись)</span>
    </div>
</nav>

<div class="container">
    <?php if ($resultMsg): ?><div class="alert alert-success"><?= htmlspecialchars($resultMsg) ?></div><?php endif; ?>
    <?php if ($errorMsg): ?><div class="alert alert-danger"><?= htmlspecialchars($errorMsg) ?></div><?php endif; ?>

    <?php if ($testId === 0): ?>
        <h4 class="mb-3">Выберите тест для прохождения:</h4>
        <div class="list-group">
            <?php foreach ($tests as $t): ?>
                <a href="?test_id=<?= $t['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                    <div>
                        <strong><?= htmlspecialchars($t['title']) ?></strong>
                        <div class="small text-muted">Преподаватель: <?= htmlspecialchars($t['teacher']) ?></div>
                    </div>
                    <span class="btn btn-sm btn-primary">Начать →</span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <?php
        $stmtTest = $pdo->prepare("SELECT * FROM tests WHERE id = ?");
        $stmtTest->execute([$testId]);
        $currentTest = $stmtTest->fetch();

        $stmtQ = $pdo->prepare("SELECT * FROM questions WHERE test_id = ?");
        $stmtQ->execute([$testId]);
        $questions = $stmtQ->fetchAll();
        ?>
        <h4 class="mb-3">Тест: <?= htmlspecialchars($currentTest['title'] ?? 'Не найден') ?></h4>
        <form method="POST">
            <input type="hidden" name="test_id" value="<?= $testId ?>">
            <?php foreach ($questions as $idx => $q): ?>
                <div class="card mb-3 shadow-sm">
                    <div class="card-body">
                        <h6><?= ($idx + 1) ?>. <?= htmlspecialchars($q['question_text']) ?></h6>
                        <?php
                        $stmtOpt = $pdo->prepare("SELECT * FROM answer_options WHERE question_id = ?");
                        $stmtOpt->execute([$q['id']]);
                        $opts = $stmtOpt->fetchAll();
                        ?>
                        <?php if ($q['question_type'] === 'single_choice'): ?>
                            <?php foreach ($opts as $o): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="answers[<?= $q['id'] ?>]" value="<?= $o['id'] ?>" required>
                                    <label class="form-check-label"><?= htmlspecialchars($o['option_text']) ?></label>
                                </div>
                            <?php endforeach; ?>
                        <?php elseif ($q['question_type'] === 'multiple_choice'): ?>
                            <?php foreach ($opts as $o): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="answers[<?= $q['id'] ?>][]" value="<?= $o['id'] ?>">
                                    <label class="form-check-label"><?= htmlspecialchars($o['option_text']) ?></label>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <input type="text" name="typed[<?= $q['id'] ?>]" class="form-control" placeholder="Введите ответ" required>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <button type="submit" name="submit_test" class="btn btn-success">Завершить тест и сохранить</button>
            <a href="take_test.php" class="btn btn-secondary">К списку тестов</a>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
