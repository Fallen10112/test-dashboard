<?php
require_once __DIR__ . '/includes/sql_helpers.php';

function isLocalRequest() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? trim((string)$_SERVER['REMOTE_ADDR']) : '';
    return $ip === '127.0.0.1' || $ip === '::1';
}

if (!isLocalRequest()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden: This dev SQL tool is only available from localhost.';
    exit;
}

$connectionOk = false;
$connectionMessage = '';
$feedback = '';
$error = '';

try {
    $pdo = getDashboardPdo();
    $connectionOk = true;
    $connectionMessage = 'Connected to ' . DB_CONNECTION . '://' . DB_HOST . ':' . DB_PORT . '/' . DB_DATABASE;
} catch (Throwable $e) {
    $connectionOk = false;
    $connectionMessage = 'Connection failed: ' . $e->getMessage();
}

if ($connectionOk && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';

    if ($action === 'run_sql') {
        $sql = trim((string)($_POST['sql_query'] ?? ''));
        $normalized = rtrim($sql, "; \t\n\r\0\x0B");

        if ($sql === '') {
            $error = 'SQL query cannot be empty.';
        } elseif (strpos($normalized, ';') !== false) {
            $error = 'Only one SQL statement is allowed.';
        } elseif (!preg_match('/^(INSERT|UPDATE|DELETE|TRUNCATE|ALTER|CREATE|DROP)\b/i', $normalized)) {
            $error = 'Only non-SELECT write/DDL statements are allowed in this tool.';
        } else {
            try {
                $affected = $pdo->exec($sql);
                $feedback = 'SQL executed successfully. Affected rows: ' . (int)$affected;
            } catch (Throwable $e) {
                $error = 'SQL execution failed: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Local Dev SQL Tool</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; max-width: 980px; }
        .ok { color: #0a7d2e; }
        .error { color: #a1260d; }
        .warn { color: #8a6d00; }
        .card { border: 1px solid #ddd; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
        input[type="text"], textarea { width: 100%; box-sizing: border-box; padding: 8px; margin-top: 6px; }
        textarea { min-height: 120px; font-family: Consolas, monospace; }
        button { margin-top: 10px; padding: 8px 12px; cursor: pointer; }
        code { background: #f4f4f4; padding: 2px 4px; border-radius: 4px; }
    </style>
</head>
<body>
    <h1>Local Dev SQL Tool</h1>
    <p class="warn">
        Temporary development utility. Remove this file before production release.
    </p>

    <div class="card">
        <h2>Connection Status</h2>
        <p class="<?php echo $connectionOk ? 'ok' : 'error'; ?>"><?php echo htmlspecialchars($connectionMessage, ENT_QUOTES, 'UTF-8'); ?></p>
    </div>

    <?php if ($feedback !== ''): ?>
        <p class="ok"><?php echo htmlspecialchars($feedback, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <div class="card">
        <h2>Run Custom SQL (single non-SELECT statement)</h2>
        <p>Allowed starts: <code>INSERT</code>, <code>UPDATE</code>, <code>DELETE</code>, <code>TRUNCATE</code>, <code>ALTER</code>, <code>CREATE</code>, <code>DROP</code></p>
        <form method="post">
            <input type="hidden" name="action" value="run_sql">
            <label>
                SQL Query
                <textarea name="sql_query" placeholder="INSERT INTO records (title, description, created_by_user_id, updated_by_user_id, created_at, updated_at) VALUES ('Sample', 'From dev tool', NULL, NULL, NOW(), NOW());"></textarea>
            </label>
            <button type="submit">Run SQL</button>
        </form>
    </div>
</body>
</html>
