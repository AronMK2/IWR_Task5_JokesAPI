<?php
declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

$errorMessage = null;
$jokes = [];

try {
    $pdo = database();
    $lastLog = $pdo->query('SELECT date_time FROM logs ORDER BY id DESC LIMIT 1')->fetchColumn();
    $shouldFetch = $lastLog === false
        || (time() - (new DateTimeImmutable((string) $lastLog))->getTimestamp()) > 300;

    if ($shouldFetch) {
        $url = 'https://v2.jokeapi.dev/joke/Programming?amount=10';
        $log = $pdo->prepare('INSERT INTO logs (url, date_time) VALUES (:url, :date_time)');
        $log->execute([
            'url' => $url,
            'date_time' => date('Y-m-d H:i:s'),
        ]);

        try {
            $client = new GuzzleHttp\Client([
                'timeout' => 15,
                'connect_timeout' => 5,
                'http_errors' => true,
                'headers' => ['Accept' => 'application/json'],
            ]);
            $response = $client->get($url);
            $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

            if (($payload['error'] ?? true) !== false || !isset($payload['jokes']) || !is_array($payload['jokes'])) {
                throw new RuntimeException('A JokeAPI válasza nem a várt formátumú.');
            }

            $normalized = [];
            foreach ($payload['jokes'] as $item) {
                if (!is_array($item) || !isset($item['type'])) {
                    throw new RuntimeException('A JokeAPI egy érvénytelen viccet adott vissza.');
                }

                if ($item['type'] === 'single' && isset($item['joke']) && trim((string) $item['joke']) !== '') {
                    $normalized[] = [
                        'type' => 'single',
                        'joke' => (string) $item['joke'],
                        'setup' => null,
                        'delivery' => null,
                    ];
                } elseif (
                    $item['type'] === 'twopart'
                    && isset($item['setup'], $item['delivery'])
                    && trim((string) $item['setup']) !== ''
                    && trim((string) $item['delivery']) !== ''
                ) {
                    $normalized[] = [
                        'type' => 'twopart',
                        'joke' => null,
                        'setup' => (string) $item['setup'],
                        'delivery' => (string) $item['delivery'],
                    ];
                } else {
                    throw new RuntimeException('A JokeAPI egy érvénytelen viccet adott vissza.');
                }
            }

            if (count($normalized) !== 10) {
                throw new RuntimeException('A JokeAPI nem pontosan 10 viccet adott vissza.');
            }

            $pdo->beginTransaction();
            $insert = $pdo->prepare(
                'INSERT INTO jokes (type, joke, setup, delivery) VALUES (:type, :joke, :setup, :delivery)'
            );
            foreach ($normalized as $joke) {
                $insert->execute($joke);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('JokeAPI request failed: ' . $exception->getMessage());
            $errorMessage = 'Nem sikerült frissíteni a vicceket. A korábban elmentett viccek továbbra is láthatók.';
        }
    }

    $jokes = $pdo->query(
        'SELECT id, type, joke, setup, delivery, created_at FROM jokes ORDER BY id DESC LIMIT 10'
    )->fetchAll();
} catch (Throwable $exception) {
    error_log('Application error: ' . $exception->getMessage());
    $errorMessage = 'Az alkalmazás nem tudott csatlakozni az adatbázishoz. Ellenőrizd a .env fájlt és az adatbázist.';
}

function escapeHtml(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="hu">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Programozói viccek</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; background: #f3f5f8; color: #172033; }
        body { max-width: 850px; margin: 3rem auto; padding: 0 1rem; }
        h1 { margin-bottom: .35rem; }
        .intro { color: #526078; margin-top: 0; }
        .joke, .notice { background: white; border: 1px solid #dfe4ec; border-radius: 10px; padding: 1rem 1.25rem; margin: 1rem 0; }
        .joke h2 { font-size: 1rem; color: #526078; margin: 0 0 .6rem; }
        .joke p { white-space: pre-wrap; margin: .35rem 0; }
        .notice { border-color: #e7b6a8; background: #fff3ef; }
        .empty { color: #526078; }
        code { color: #284a87; }
    </style>
</head>
<body>
    <h1>Programozói viccek</h1>
    <p class="intro">A JokeAPI-ból érkező, adatbázisban tárolt legutóbbi viccek.</p>
    <?php if ($errorMessage !== null): ?>
        <p class="notice"><?= escapeHtml($errorMessage) ?></p>
    <?php endif; ?>
    <?php if ($jokes === []): ?>
        <p class="empty">Még nincs megjeleníthető vicc. Ellenőrizd az adatbázis beállításait, majd próbáld újra.</p>
    <?php endif; ?>
    <?php foreach ($jokes as $joke): ?>
        <article class="joke">
            <h2>#<?= (int) $joke['id'] ?> · <?= escapeHtml($joke['type']) ?></h2>
            <?php if ($joke['type'] === 'single'): ?>
                <p><?= escapeHtml($joke['joke']) ?></p>
            <?php else: ?>
                <p><?= escapeHtml($joke['setup']) ?></p>
                <p><?= escapeHtml($joke['delivery']) ?></p>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
    <p class="intro">REST API: <code>/api/jokes</code></p>
</body>
</html>
