<?php
require_once __DIR__ . '/includes/sql_helpers.php';

function isLocalRequest() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? trim((string)$_SERVER['REMOTE_ADDR']) : '';
    return $ip === '127.0.0.1' || $ip === '::1';
}

    function splitSqlStatements($sql) {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;

        for ($index = 0; $index < $length; $index++) {
            $char = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : '';

            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                }
                continue;
            }

            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $inBlockComment = false;
                    $index++;
                }
                continue;
            }

            if (!$inSingleQuote && !$inDoubleQuote && !$inBacktick) {
                if ($char === '-' && $next === '-') {
                    $previous = $index > 0 ? $sql[$index - 1] : '';
                    if ($previous === '' || ctype_space($previous)) {
                        $inLineComment = true;
                        $index++;
                        continue;
                    }
                }
                if ($char === '#') {
                    $inLineComment = true;
                    continue;
                }
                if ($char === '/' && $next === '*') {
                    $inBlockComment = true;
                    $index++;
                    continue;
                }
            }

            if ($char === "'" && !$inDoubleQuote && !$inBacktick) {
                $escaped = $index > 0 && $sql[$index - 1] === '\\';
                if (!$escaped) {
                    $inSingleQuote = !$inSingleQuote;
                }
            } elseif ($char === '"' && !$inSingleQuote && !$inBacktick) {
                $escaped = $index > 0 && $sql[$index - 1] === '\\';
                if (!$escaped) {
                    $inDoubleQuote = !$inDoubleQuote;
                }
            } elseif ($char === '`' && !$inSingleQuote && !$inDoubleQuote) {
                $inBacktick = !$inBacktick;
            }

            if ($char === ';' && !$inSingleQuote && !$inDoubleQuote && !$inBacktick) {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $statement = trim($buffer);
        if ($statement !== '') {
            $statements[] = $statement;
        }

        return $statements;
    }

    function isAllowedDevSqlStatement($statement) {
        return (bool) preg_match('/^(INSERT|UPDATE|DELETE|TRUNCATE|ALTER|CREATE|DROP|SET)\b/i', $statement);
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
        } else {
            try {
                    $statements = splitSqlStatements($sql);

                    if (count($statements) === 0) {
                        $error = 'SQL query cannot be empty.';
                    } else {
                        $affectedTotal = 0;
                        foreach ($statements as $statement) {
                            if (!isAllowedDevSqlStatement($statement)) {
                                throw new RuntimeException('Only non-SELECT write/DDL statements are allowed in this tool.');
                            }
                            $affected = $pdo->exec($statement);
                            if ($affected !== false) {
                                $affectedTotal += (int)$affected;
                            }
                        }
                        $feedback = 'SQL executed successfully. Statements run: ' . count($statements) . '; affected rows: ' . $affectedTotal;
                    }
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
        <h2>Run Custom SQL (write/DDL statements only)</h2>
        <p>Allowed starts: <code>INSERT</code>, <code>UPDATE</code>, <code>DELETE</code>, <code>TRUNCATE</code>, <code>ALTER</code>, <code>CREATE</code>, <code>DROP</code>, <code>SET</code></p>
        <p>You can paste multiple statements separated by semicolons. Use <code>SET FOREIGN_KEY_CHECKS=0</code> and <code>SET FOREIGN_KEY_CHECKS=1</code> for reset scripts when needed.</p>
        <form method="post">
            <input type="hidden" name="action" value="run_sql">
            <label>
                SQL Query
                <textarea name="sql_query" placeholder="SET FOREIGN_KEY_CHECKS=0; TRUNCATE TABLE users; SET FOREIGN_KEY_CHECKS=1;"></textarea>
            </label>
            <button type="submit">Run SQL</button>
        </form>
    </div>
</body>
</html>
