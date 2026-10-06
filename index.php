<?php
$dbHost = getenv('AZURE_SQL_SERVER') ?: 'clan-hz-database-server.database.windows.net';
$dbName = getenv('AZURE_SQL_DATABASE') ?: 'clanhz-database';
$dbUser = getenv('AZURE_SQL_USERNAME') ?: '';
$dbPass = getenv('AZURE_SQL_PASSWORD') ?: '';

session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Strict',
]);
session_start();

$dbError = null;
$formError = null;
$pdo = null;
$posts = [];
$totalMinutes = 0;
$authenticated = isset($_SESSION['user_id']);
$csrfToken = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

function formatMinutes(int $totalMinutes): array
{
    $days = intdiv($totalMinutes, 1440);
    $remainingMinutes = $totalMinutes % 1440;
    $hours = intdiv($remainingMinutes, 60);
    $minutes = $remainingMinutes % 60;

    return [
        'days' => $days,
        'hours' => $hours,
        'minutes' => $minutes,
    ];
}

function createSqlConnection(string $dbHost, string $dbName, string $dbUser, string $dbPass): PDO
{
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    $isLocalServer = PHP_SAPI === 'cli-server';
    if ($isLocalServer && ($dbUser === '' || $dbPass === '')) {
        throw new RuntimeException('Lokal körning kräver AZURE_SQL_USERNAME och AZURE_SQL_PASSWORD. Starta om PHP-servern från en terminal där dessa variabler är satta.');
    }

    $dsn = "sqlsrv:Server={$dbHost},1433;Database={$dbName};Encrypt=true;TrustServerCertificate=false;LoginTimeout=8";

    if ($dbUser !== '' && $dbPass !== '') {
        return new PDO($dsn, $dbUser, $dbPass, $options);
    }

    $dsn .= ';Authentication=ActiveDirectoryMSI';
    return new PDO($dsn, null, null, $options);
}

try {
    $pdo = createSqlConnection($dbHost, $dbName, $dbUser, $dbPass);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $submittedToken = (string) ($_POST['csrf_token'] ?? '');
        if (!hash_equals($csrfToken, $submittedToken)) {
            $formError = 'Formuläret har gått ut. Ladda om sidan och försök igen.';
        } elseif (isset($_POST['login'])) {
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $stmt = $pdo->prepare('SELECT id, username, password FROM dbo.users WHERE username = :username AND is_active = 1');
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['username'] = $user['username'];
                header('Location: index.php');
                exit;
            }

            $formError = 'Fel användarnamn eller lösenord.';
        } elseif (isset($_POST['logout']) && $authenticated) {
            $_SESSION = [];
            session_destroy();
            header('Location: index.php');
            exit;
        } elseif ($authenticated && isset($_POST['save_post'])) {
            $title = trim((string) ($_POST['title'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $postDate = trim((string) ($_POST['post_date'] ?? ''));
            $parsedPostDate = DateTimeImmutable::createFromFormat('!Y-m-d', $postDate);
            $dateErrors = DateTimeImmutable::getLastErrors();
            $validPostDate = $parsedPostDate !== false
                && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
                && $parsedPostDate->format('Y-m-d') === $postDate;

            if ($title !== '' && $description !== '' && $validPostDate) {
                $stmt = $pdo->prepare('INSERT INTO dbo.posts (title, description, post_date) VALUES (:title, :description, :post_date)');
                $stmt->execute([
                    ':title' => $title,
                    ':description' => $description,
                    ':post_date' => $postDate,
                ]);
                header('Location: index.php');
                exit;
            } elseif (!$validPostDate) {
                $dbError = 'Välj ett giltigt datum för inlägget.';
            }
        } elseif ($authenticated && isset($_POST['save_time'])) {
            $minutes = min(1440, max(1, (int) ($_POST['minutes'] ?? 0)));
            $postId = (int) ($_POST['post_id'] ?? 0);

            if ($postId > 0) {
                $pdo->beginTransaction();
                $deleteTime = $pdo->prepare('DELETE FROM dbo.study_sessions WHERE post_id = :post_id');
                $deleteTime->execute([':post_id' => $postId]);
                $stmt = $pdo->prepare('INSERT INTO dbo.study_sessions (minutes, post_id) VALUES (:minutes, :post_id)');
                $stmt->execute([
                    ':minutes' => (string) $minutes,
                    ':post_id' => $postId,
                ]);
                $pdo->commit();
                header('Location: index.php');
                exit;
            }
        } elseif ($authenticated && isset($_POST['delete_post'])) {
            $postId = (int) ($_POST['post_id'] ?? 0);
            if ($postId > 0) {
                $pdo->beginTransaction();
                $deleteSessions = $pdo->prepare('DELETE FROM dbo.study_sessions WHERE post_id = :post_id');
                $deleteSessions->execute([':post_id' => $postId]);
                $deletePost = $pdo->prepare('DELETE FROM dbo.posts WHERE id = :post_id');
                $deletePost->execute([':post_id' => $postId]);

                if ($deletePost->rowCount() > 0) {
                    $pdo->commit();
                    header('Location: index.php');
                    exit;
                }

                $pdo->rollBack();
                $dbError = 'Inlägget kunde inte hittas.';
            }
        }
    }

    if ($authenticated) {
        $totalResult = $pdo->query('SELECT SUM(CAST(minutes AS INT)) AS total_minutes FROM dbo.study_sessions');
        $totalMinutes = (int) ($totalResult->fetchColumn() ?? 0);

        $postsStmt = $pdo->query('SELECT TOP 10 p.id, p.title, p.description, p.post_date, COALESCE(s.total_minutes, 0) AS total_minutes FROM dbo.posts p LEFT JOIN (SELECT post_id, SUM(CAST(minutes AS INT)) AS total_minutes FROM dbo.study_sessions GROUP BY post_id) s ON s.post_id = p.id ORDER BY p.post_date DESC, p.id DESC');
        $posts = $postsStmt->fetchAll();
    }
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Azure SQL connection/request failed: ' . $e->getMessage());
    $dbError = $e instanceof RuntimeException && PHP_SAPI === 'cli-server'
        ? $e->getMessage()
        : 'Kunde inte nå Azure SQL just nu. Kontrollera inloggning, nätverksregler och att databasen är tillgänglig. Försök sedan ladda om sidan.';
}

$totalTime = formatMinutes($totalMinutes);
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cloud 365</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="page-shell">
        <?php if (!$authenticated): ?>
        <header class="topbar">
            <div>
                <p class="eyebrow">Knowledge system</p>
                <h1>Cloud 365</h1>
            </div>
        </header>
        <section class="panel login-panel">
            <h2>Logga in</h2>
            <?php if ($formError || $dbError): ?>
                <div class="alert"><?php echo htmlspecialchars($formError ?? $dbError, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <label>
                    <span>Användarnamn</span>
                    <input type="text" name="username" autocomplete="username" required>
                </label>
                <label>
                    <span>Lösenord</span>
                    <input type="password" name="password" autocomplete="current-password" required>
                </label>
                <button type="submit" name="login">Logga in</button>
            </form>
        </section>
        <?php else: ?>
        <header class="topbar">
            <div>
                <p class="eyebrow">Knowledge system</p>
                <h1>Cloud 365</h1>
            </div>
            <div class="status-pill">
                <span class="status-dot"></span>
                <?php echo htmlspecialchars((string) ($_SESSION['username'] ?? 'Learning tracker'), ENT_QUOTES, 'UTF-8'); ?>
                <form method="post" class="logout-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" name="logout" class="logout-button">Logga ut</button>
                </form>
            </div>
        </header>

        <main class="dashboard">
            <section class="panel hero-panel">
                <div>
                    <p class="section-tag">Total pluggad tid</p>
                    <h2>
                        <?php echo $totalTime['days']; ?>d
                        <span><?php echo $totalTime['hours']; ?>h</span>
                        <span><?php echo $totalTime['minutes']; ?>m</span>
                    </h2>
                </div>
                <div class="summary-grid">
                    <div>
                        <span>Dagar</span>
                        <strong><?php echo $totalTime['days']; ?></strong>
                    </div>
                    <div>
                        <span>Timmar</span>
                        <strong><?php echo $totalTime['hours']; ?></strong>
                    </div>
                    <div>
                        <span>Minuter</span>
                        <strong><?php echo $totalTime['minutes']; ?></strong>
                    </div>
                </div>
            </section>

            <section class="panel form-panel">
                <h3>Skapa nytt inlägg</h3>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <label>
                        <span>Rubrik</span>
                        <input type="text" name="title" maxlength="160" required>
                    </label>
                    <label>
                        <span>Vad har du studerat?</span>
                        <textarea name="description" rows="5" maxlength="2000" required></textarea>
                    </label>
                    <label>
                        <span>Datum</span>
                        <input type="date" name="post_date" value="<?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" required>
                    </label>
                    <button type="submit" name="save_post">Spara inlägg</button>
                </form>
            </section>

            <section class="panel form-panel">
                <h3>Spåra studietid</h3>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <label>
                        <span>Inlägg</span>
                        <select name="post_id" required>
                            <option value="">Välj inlägg</option>
                            <?php foreach ($posts as $post): ?>
                                <option value="<?php echo (int) $post['id']; ?>"><?php echo htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span>Minuter</span>
                        <input type="number" name="minutes" min="1" max="1440" value="45" required>
                    </label>
                    <button type="submit" name="save_time">Spara tid</button>
                </form>
            </section>

            <section class="panel list-panel">
                <div class="panel-header">
                    <h3>Senaste inläggen</h3>
                </div>

                <?php if ($dbError || $formError): ?>
                    <div class="alert">
                        <?php echo htmlspecialchars($formError ?? $dbError, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php elseif (empty($posts)): ?>
                    <p class="empty-state">Inga inlägg ännu. Lägg till ditt första studieinlägg.</p>
                <?php else: ?>
                    <div class="post-list">
                        <?php foreach ($posts as $post): ?>
                            <article class="post-item">
                                <form method="post" class="delete-post-form" onsubmit="return confirm('Är du säker på att du vill radera det här inlägget och dess registrerade tid?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="post_id" value="<?php echo (int) $post['id']; ?>">
                                    <button type="submit" name="delete_post" class="delete-post-button" aria-label="Radera inlägg">×</button>
                                </form>
                                <div class="post-meta">
                                    <span><?php echo htmlspecialchars(date('Y-m-d', strtotime($post['post_date'])), ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                                <h4><?php echo htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8'); ?></h4>
                                <p><?php echo nl2br(htmlspecialchars($post['description'], ENT_QUOTES, 'UTF-8')); ?></p>
                                <p class="post-study-time">Studietid: <?php echo (int) $post['total_minutes']; ?> min</p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
        <?php endif; ?>
    </div>
</body>
</html>

