<?php
$dbHost = getenv('AZURE_SQL_SERVER') ?: 'clan-hz-database-server.database.windows.net';
$dbName = getenv('AZURE_SQL_DATABASE') ?: 'clanhz-database';
$dbUser = getenv('AZURE_SQL_USERNAME') ?: '';
$dbPass = getenv('AZURE_SQL_PASSWORD') ?: '';

$dbError = null;
$pdo = null;
$posts = [];
$totalMinutes = 0;

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
        if (isset($_POST['save_post'])) {
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
            } elseif (!$validPostDate) {
                $dbError = 'Välj ett giltigt datum för inlägget.';
            }
        }

        if (isset($_POST['save_time'])) {
            $minutes = max(1, (int) ($_POST['minutes'] ?? 0));
            $postId = (int) ($_POST['post_id'] ?? 0);

            if ($postId > 0) {
                $stmt = $pdo->prepare('INSERT INTO dbo.study_sessions (minutes, post_id) VALUES (:minutes, :post_id)');
                $stmt->execute([
                    ':minutes' => (string) $minutes,
                    ':post_id' => $postId,
                ]);
            }
        }
    }

    $totalResult = $pdo->query('SELECT SUM(CAST(minutes AS INT)) AS total_minutes FROM dbo.study_sessions');
    $totalMinutes = (int) ($totalResult->fetchColumn() ?? 0);

    $postsStmt = $pdo->query('SELECT TOP 10 id, title, description, post_date FROM dbo.posts ORDER BY post_date DESC, id DESC');
    $posts = $postsStmt->fetchAll();
} catch (Throwable $e) {
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
        <header class="topbar">
            <div>
                <p class="eyebrow">Knowledge system</p>
                <h1>Cloud 365</h1>
            </div>
            <div class="status-pill">
                <span class="status-dot"></span>
                Learning tracker
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

                <?php if ($dbError): ?>
                    <div class="alert">
                        <?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php elseif (empty($posts)): ?>
                    <p class="empty-state">Inga inlägg ännu. Lägg till ditt första studieinlägg.</p>
                <?php else: ?>
                    <div class="post-list">
                        <?php foreach ($posts as $post): ?>
                            <article class="post-item">
                                <div class="post-meta">
                                    <span><?php echo htmlspecialchars(date('Y-m-d', strtotime($post['post_date'])), ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                                <h4><?php echo htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8'); ?></h4>
                                <p><?php echo nl2br(htmlspecialchars($post['description'], ENT_QUOTES, 'UTF-8')); ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</body>
</html>

