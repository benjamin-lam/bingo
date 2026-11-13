<?php
declare(strict_types=1);

$appPath = (string) (getenv('BINGO_APP_PATH') ?: dirname(__DIR__));
require_once rtrim($appPath, DIRECTORY_SEPARATOR) . '/src/Game.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$origins = array_filter(array_map('trim', explode(',', (string) getenv('BINGO_CORS_ORIGINS'))));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    if (!in_array($origin, $origins, true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Diese Herkunft ist nicht erlaubt.']);
        exit;
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST, OPTIONS');
    echo json_encode(['error' => 'Nur POST ist erlaubt.']);
    exit;
}

try {
    $databasePath = (string) getenv('BINGO_DB_PATH');
    if ($databasePath === '') {
        throw new RuntimeException('BINGO_DB_PATH ist nicht konfiguriert.');
    }
    $game = new BingoGame($databasePath);
    $game->rateLimit($_SERVER['REMOTE_ADDR'] ?? '');
    $body = file_get_contents('php://input');
    if ($body === false || strlen($body) > 4096) {
        throw new ApiError('Die Anfrage ist zu groß.');
    }
    $input = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        throw new ApiError('Ungültige JSON-Anfrage.');
    }
    $action = $input['action'] ?? '';
    $gameId = is_string($input['game_id'] ?? null) ? $input['game_id'] : '';
    $token = is_string($input['token'] ?? null) ? $input['token'] : '';

    $result = match ($action) {
        'create' => $game->create(),
        'join' => $game->join(
            $gameId,
            is_string($input['name'] ?? null) ? $input['name'] : '',
            is_string($input['token'] ?? null) ? $input['token'] : null
        ),
        'state' => $game->state($gameId, $token),
        'start' => $game->start($gameId, $token),
        'draw' => $game->draw($gameId, $token),
        'mark' => $game->mark(
            $gameId,
            $token,
            filter_var($input['number'] ?? null, FILTER_VALIDATE_INT) ?: 0,
            ($input['marked'] ?? true) === true
        ),
        'bingo' => $game->bingo($gameId, $token),
        'new_round' => $game->newRound($gameId, $token),
        default => throw new ApiError('Unbekannte Aktion.'),
    };
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (ApiError $exception) {
    http_response_code($exception->httpStatus);
    echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (JsonException) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige JSON-Anfrage.']);
} catch (Throwable $exception) {
    error_log('Bingo-API: interner Fehler (' . get_class($exception) . ')');
    http_response_code(500);
    echo json_encode(['error' => 'Interner Serverfehler.']);
}
