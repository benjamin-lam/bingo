<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/backend/src/Game.php';

if ($argc < 8) {
    fwrite(STDERR, "Erwartet: Datenbank, Spiel-ID, Token, Aktion, Sperrverzeichnis, Worker-ID und optionalen Namen.\n");
    exit(2);
}

[, $databasePath, $gameId, $token, $action, $barrierDirectory, $workerId, $name] = array_pad($argv, 8, '');

try {
    $game = $action === 'http-poll' ? null : new BingoGame($databasePath);
    file_put_contents($barrierDirectory . DIRECTORY_SEPARATOR . $workerId . '.ready', 'ready', LOCK_EX);
    $deadline = microtime(true) + 20;
    while (!is_file($barrierDirectory . DIRECTORY_SEPARATOR . 'go')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Die Testbarriere wurde nicht freigegeben.');
        }
        usleep(1000);
    }

    if ($action === 'http-poll') {
        $body = json_encode(['action' => 'state', 'game_id' => $gameId, 'token' => $token], JSON_THROW_ON_ERROR);
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 15,
        ]]);
        @file_get_contents($databasePath, false, $context);
        preg_match('/\s(\d{3})(?:\s|$)/', $http_response_header[0] ?? '', $matches);
        $httpStatus = isset($matches[1]) ? (int) $matches[1] : 0;
        echo json_encode(['ok' => $httpStatus === 200, 'http_status' => $httpStatus], JSON_THROW_ON_ERROR);
        exit(0);
    }

    $result = match ($action) {
        'create' => $game->create(),
        'join' => $game->join($gameId, $name),
        'draw' => $game->draw($gameId, $token),
        'bingo' => $game->bingo($gameId, $token),
        'poll' => (static function () use ($game, $gameId, $token): array {
            $game->rateLimit('203.0.113.21', 'state', $gameId, $token);
            return $game->state($gameId, $token);
        })(),
        default => throw new RuntimeException('Unbekannte Testaktion.'),
    };
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR);
} catch (ApiError $exception) {
    echo json_encode(['ok' => false, 'http_status' => $exception->httpStatus], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage());
    exit(1);
}
