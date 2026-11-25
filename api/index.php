<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function failRequest(int $status, string $message, ?string $allow = null): never
{
    if ($allow !== null) {
        header('Allow: ' . $allow);
    }
    respond($status, ['error' => $message]);
}

function requireJsonContentType(): void
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (!preg_match('/^\s*application\/json(?:\s*;|$)/i', $contentType)) {
        failRequest(415, 'A kérés Content-Type fejlécének application/json értékűnek kell lennie.');
    }
}

function readJsonObject(): array
{
    requireJsonContentType();
    try {
        $decoded = json_decode((string) file_get_contents('php://input'), false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        failRequest(400, 'A kérés törzse hibás JSON.');
    }

    if (!is_object($decoded)) {
        failRequest(400, 'A kérés törzsének JSON-objektumnak kell lennie.');
    }

    return get_object_vars($decoded);
}

function validateAllowedFields(array $data): void
{
    $unknown = array_diff(array_keys($data), ['type', 'joke', 'setup', 'delivery']);
    if ($unknown !== []) {
        failRequest(400, 'A kérés nem megengedett mezőt tartalmaz.');
    }
}

function validateJoke(array $data, ?array $existing = null, bool $isPatch = false): array
{
    validateAllowedFields($data);

    if (!$isPatch && !array_key_exists('type', $data)) {
        failRequest(400, 'A type mező megadása kötelező.');
    }
    if ($isPatch && $data === []) {
        failRequest(400, 'A PATCH kérésnek legalább egy megengedett mezőt tartalmaznia kell.');
    }

    $type = array_key_exists('type', $data) ? $data['type'] : ($existing['type'] ?? null);
    if (!is_string($type) || !in_array($type, ['single', 'twopart'], true)) {
        failRequest(400, 'A type értéke csak single vagy twopart lehet.');
    }

    $inactiveKeys = $type === 'single' ? ['setup', 'delivery'] : ['joke'];
    foreach ($inactiveKeys as $key) {
        if (array_key_exists($key, $data) && $data[$key] !== null) {
            failRequest(400, 'A kiválasztott típushoz nem használt mezők csak null értékkel küldhetők.');
        }
    }

    $changingType = $existing !== null && $type !== $existing['type'];
    if ($isPatch && $changingType) {
        $requiredKeys = $type === 'single' ? ['joke'] : ['setup', 'delivery'];
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $data)) {
                failRequest(400, 'Típusváltáskor az új típus minden kötelező szöveges mezőjét meg kell adni.');
            }
        }
    }

    $result = ['type' => $type, 'joke' => null, 'setup' => null, 'delivery' => null];
    $activeKeys = $type === 'single' ? ['joke'] : ['setup', 'delivery'];

    foreach ($activeKeys as $key) {
        if (array_key_exists($key, $data)) {
            $value = $data[$key];
        } elseif ($isPatch && !$changingType && $existing !== null) {
            $value = $existing[$key];
        } else {
            failRequest(400, 'A kiválasztott típus kötelező szöveges mezője hiányzik.');
        }

        if (!is_string($value) || trim($value) === '') {
            failRequest(400, 'A kötelező szöveges mezők nem lehetnek üresek.');
        }
        $result[$key] = $value;
    }

    return $result;
}

function fetchJoke(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, type, joke, setup, delivery, created_at FROM jokes WHERE id = :id'
    );
    $statement->execute(['id' => $id]);
    $joke = $statement->fetch();
    return $joke === false ? null : $joke;
}

try {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (!preg_match('~^(.*?)/api/jokes(?:/([^/]+))?/?$~D', $path, $matches)) {
        failRequest(404, 'A kért végpont nem található.');
    }

    $collectionPath = $matches[1] . '/api/jokes';
    $rawId = $matches[2] ?? null;
    $id = null;
    if ($rawId !== null) {
        if (!preg_match('/^[1-9][0-9]*$/', $rawId) || filter_var($rawId, FILTER_VALIDATE_INT) === false) {
            failRequest(400, 'Az azonosítónak pozitív egész számnak kell lennie.');
        }
        $id = (int) $rawId;
    }

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($id === null) {
        if ($method === 'GET') {
            $type = $_GET['type'] ?? null;
            if ($type !== null && !in_array($type, ['single', 'twopart'], true)) {
                failRequest(400, 'A type szűrő csak single vagy twopart lehet.');
            }

            $pdo = database();
            if ($type === null) {
                $rows = $pdo->query(
                    'SELECT id, type, joke, setup, delivery, created_at FROM jokes ORDER BY id DESC'
                )->fetchAll();
            } else {
                $statement = $pdo->prepare(
                    'SELECT id, type, joke, setup, delivery, created_at FROM jokes WHERE type = :type ORDER BY id DESC'
                );
                $statement->execute(['type' => $type]);
                $rows = $statement->fetchAll();
            }
            echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($method === 'POST') {
            $joke = validateJoke(readJsonObject());
            $pdo = database();
            $statement = $pdo->prepare(
                'INSERT INTO jokes (type, joke, setup, delivery) VALUES (:type, :joke, :setup, :delivery)'
            );
            $statement->execute($joke);
            $newId = (int) $pdo->lastInsertId();
            header('Location: ' . $collectionPath . '/' . $newId);
            respond(201, ['message' => 'A vicc sikeresen létrejött.', 'data' => fetchJoke($pdo, $newId)]);
        }

        failRequest(405, 'A végpont csak GET és POST metódust fogad.', 'GET, POST');
    }

    if (!in_array($method, ['GET', 'PUT', 'PATCH', 'DELETE'], true)) {
        failRequest(405, 'A vicc végpontja csak GET, PUT, PATCH és DELETE metódust fogad.', 'GET, PUT, PATCH, DELETE');
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        requireJsonContentType();
    }

    $requestData = ($method === 'PUT' || $method === 'PATCH') ? readJsonObject() : null;
    $pdo = database();
    $existing = fetchJoke($pdo, $id);
    if ($existing === null) {
        failRequest(404, 'A megadott azonosítójú vicc nem található.');
    }

    if ($method === 'GET') {
        echo json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($method === 'DELETE') {
        $statement = $pdo->prepare('DELETE FROM jokes WHERE id = :id');
        $statement->execute(['id' => $id]);
        respond(200, ['message' => 'A vicc sikeresen törölve.']);
    }

    $data = validateJoke($requestData, $existing, $method === 'PATCH');
    $statement = $pdo->prepare(
        'UPDATE jokes SET type = :type, joke = :joke, setup = :setup, delivery = :delivery WHERE id = :id'
    );
    $statement->execute($data + ['id' => $id]);
    respond(200, [
        'message' => $method === 'PUT' ? 'A vicc sikeresen lecserélve.' : 'A vicc sikeresen módosítva.',
        'data' => fetchJoke($pdo, $id),
    ]);
} catch (Throwable $exception) {
    error_log('API error: ' . $exception->getMessage());
    respond(500, ['error' => 'Váratlan szerverhiba történt.']);
}
