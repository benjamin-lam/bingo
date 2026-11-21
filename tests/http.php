<?php
declare(strict_types=1);

function startBingoTestServer(string $directory, string $databasePath): array
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($socket === false) {
        throw new RuntimeException('HTTP-Testport konnte nicht reserviert werden: ' . $errorMessage);
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr((string) strrchr((string) $address, ':'), 1);
    $publicRoot = dirname(__DIR__) . '/backend/public';
    $environment = getenv();
    if (!is_array($environment)) $environment = [];
    $environment['BINGO_APP_PATH'] = dirname(__DIR__) . '/backend';
    $environment['BINGO_DB_PATH'] = $databasePath;
    $environment['BINGO_CORS_ORIGINS'] = 'https://frontend.example.test';
    if (PHP_OS_FAMILY !== 'Windows') $environment['PHP_CLI_SERVER_WORKERS'] = '4';
    $command = [PHP_BINARY, '-d', 'memory_limit=64M', '-S', '127.0.0.1:' . $port, '-t', $publicRoot];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['file', $directory . '/http-server.out', 'a'], 2 => ['file', $directory . '/http-server.err', 'a']],
        $pipes,
        dirname(__DIR__),
        $environment
    );
    if (!is_resource($process)) throw new RuntimeException('PHP-HTTP-Testserver konnte nicht gestartet werden.');
    fclose($pipes[0]);

    $url = 'http://127.0.0.1:' . $port . '/';
    $deadline = microtime(true) + 10;
    do {
        $connection = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.1);
        if (is_resource($connection)) {
            fclose($connection);
            return [$process, $url];
        }
        if (!proc_get_status($process)['running']) {
            $log = @file_get_contents($directory . '/http-server.err');
            proc_close($process);
            throw new RuntimeException('PHP-HTTP-Testserver wurde nicht gestartet: ' . (string) $log);
        }
        usleep(10000);
    } while (microtime(true) < $deadline);

    proc_terminate($process);
    proc_close($process);
    throw new RuntimeException('PHP-HTTP-Testserver wurde nicht rechtzeitig bereit.');
}

function bingoHttpRequest(string $url, string $method, ?string $body = null, ?string $origin = null): array
{
    $headers = ['Content-Type: application/json'];
    if ($origin !== null) $headers[] = 'Origin: ' . $origin;
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $body ?? '',
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);
    $response = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    preg_match('/\s(\d{3})(?:\s|$)/', $responseHeaders[0] ?? '', $matches);
    return [
        'status' => isset($matches[1]) ? (int) $matches[1] : 0,
        'headers' => $responseHeaders,
        'body' => $response === false ? '' : $response,
    ];
}

function bingoHttpHeader(array $headers, string $name): ?string
{
    foreach ($headers as $header) {
        if (stripos($header, $name . ':') === 0) return trim(substr($header, strlen($name) + 1));
    }
    return null;
}

function runBingoHttpTests(string $directory): void
{
    $databaseDirectory = $directory . '/http-db';
    mkdir($databaseDirectory, 0700, true);
    [$server, $url] = startBingoTestServer($directory, $databaseDirectory . '/games.sqlite');
    try {
        $preflight = bingoHttpRequest($url, 'OPTIONS', '', 'https://frontend.example.test');
        check($preflight['status'] === 204, 'CORS-Preflight wird mit 204 beantwortet');
        check(bingoHttpHeader($preflight['headers'], 'Access-Control-Allow-Origin') === 'https://frontend.example.test', 'CORS erlaubt nur den konfigurierten Origin');
        check(str_contains((string) bingoHttpHeader($preflight['headers'], 'Access-Control-Allow-Methods'), 'POST'), 'CORS nennt die erlaubte POST-Methode');

        $deniedOrigin = bingoHttpRequest($url, 'OPTIONS', '', 'https://untrusted.example.test');
        check($deniedOrigin['status'] === 403, 'Fremder CORS-Origin wird abgewiesen');
        check(bingoHttpHeader($deniedOrigin['headers'], 'Access-Control-Allow-Origin') === null, 'Abgewiesener Origin erhält keinen CORS-Freigabeheader');

        $get = bingoHttpRequest($url, 'GET', null, 'https://frontend.example.test');
        check($get['status'] === 405, 'Nicht erlaubte HTTP-Methode erhält 405');
        check(bingoHttpHeader($get['headers'], 'Allow') === 'POST, OPTIONS', '405-Antwort dokumentiert erlaubte Methoden');
        check(bingoHttpRequest($url, 'POST', '{', 'https://frontend.example.test')['status'] === 400, 'Ungültiges JSON erhält HTTP 400');
        check(bingoHttpRequest($url, 'POST', str_repeat('x', 4097), 'https://frontend.example.test')['status'] === 413, 'Übergroße Anfrage erhält HTTP 413');

        $create = bingoHttpRequest($url, 'POST', json_encode(['action' => 'create'], JSON_THROW_ON_ERROR), 'https://frontend.example.test');
        check($create['status'] === 200, 'Spielerstellung funktioniert über HTTP');
        $created = json_decode($create['body'], true, 16, JSON_THROW_ON_ERROR);
        check(bingoHttpHeader($create['headers'], 'Access-Control-Allow-Origin') === 'https://frontend.example.test', 'HTTP-Antwort enthält den erlaubten CORS-Origin');
        $gameId = strtolower($created['game_id']);
        $adminToken = $created['token'];
        for ($index = 0; $index < 4; $index++) {
            check(bingoHttpRequest($url, 'POST', json_encode(['action' => 'create'], JSON_THROW_ON_ERROR), 'https://frontend.example.test')['status'] === 200, 'Spielerstellung bleibt innerhalb des öffentlichen Limits möglich');
        }
        check(bingoHttpRequest($url, 'POST', json_encode(['action' => 'create'], JSON_THROW_ON_ERROR), 'https://frontend.example.test')['status'] === 429, 'Öffentliche Spielerstellung erhält nach dem eigenen Limit HTTP 429');

        $join = bingoHttpRequest($url, 'POST', json_encode([
            'action' => 'join', 'game_id' => $gameId, 'name' => 'HTTP-Spieler',
        ], JSON_THROW_ON_ERROR), 'https://frontend.example.test');
        check($join['status'] === 200, 'Beitritt mit kleingeschriebener Spiel-ID funktioniert über HTTP');
        $playerToken = json_decode($join['body'], true, 16, JSON_THROW_ON_ERROR)['token'];
        $httpPollTokens = [$adminToken, $playerToken];
        for ($index = 1; $index < 30; $index++) {
            $additionalJoin = bingoHttpRequest($url, 'POST', json_encode([
                'action' => 'join', 'game_id' => $gameId, 'name' => 'HTTP-Poller ' . $index,
            ], JSON_THROW_ON_ERROR), 'https://frontend.example.test');
            check($additionalJoin['status'] === 200, 'HTTP-Spiel nimmt die übrigen Poller innerhalb des Beitrittslimits auf');
            $httpPollTokens[] = json_decode($additionalJoin['body'], true, 16, JSON_THROW_ON_ERROR)['token'];
        }
        $httpPollWorkers = array_map(
            static fn (string $token): array => [
                'database' => $url,
                'game_id' => $gameId,
                'token' => $token,
                'action' => 'http-poll',
            ],
            $httpPollTokens
        );
        $httpPollResults = runParallelWorkers($directory, $httpPollWorkers);
        check(count(array_filter($httpPollResults, static fn (array $result): bool => $result['ok'])) === 31, '31 barrier-synchronisierte Clients erhalten gleichzeitig über HTTP ihren Spielstatus');
        $state = bingoHttpRequest($url, 'POST', json_encode([
            'action' => 'state', 'game_id' => $gameId, 'token' => $playerToken,
        ], JSON_THROW_ON_ERROR), 'https://frontend.example.test');
        check($state['status'] === 200, 'Statusabfrage mit kleingeschriebener Spiel-ID funktioniert über HTTP');
        check(json_decode($state['body'], true, 16, JSON_THROW_ON_ERROR)['game']['id'] === strtoupper($gameId), 'API liefert die normalisierte Spiel-ID zurück');

        $invalidToken = bingoHttpRequest($url, 'POST', json_encode([
            'action' => 'state', 'game_id' => $gameId, 'token' => 'ungueltig',
        ], JSON_THROW_ON_ERROR), 'https://frontend.example.test');
        check($invalidToken['status'] === 401, 'Ungültiges Token erhält HTTP 401');
        $wrongRole = bingoHttpRequest($url, 'POST', json_encode([
            'action' => 'start', 'game_id' => $gameId, 'token' => $playerToken,
        ], JSON_THROW_ON_ERROR), 'https://frontend.example.test');
        check($wrongRole['status'] === 403, 'Spieler-Token erhält für Admin-Aktionen HTTP 403');

        $start = bingoHttpRequest($url, 'POST', json_encode([
            'action' => 'start', 'game_id' => $gameId, 'token' => $adminToken,
        ], JSON_THROW_ON_ERROR), 'https://frontend.example.test');
        check($start['status'] === 200, 'Spielleitung kann die Runde über HTTP starten');
        $end = bingoHttpRequest($url, 'POST', json_encode([
            'action' => 'end_round', 'game_id' => $gameId, 'token' => $adminToken,
        ], JSON_THROW_ON_ERROR), 'https://frontend.example.test');
        check($end['status'] === 200 && json_decode($end['body'], true, 16, JSON_THROW_ON_ERROR)['winner'] === null, 'Manuelles Rundenende meldet keinen Gewinner');
        $newRound = bingoHttpRequest($url, 'POST', json_encode([
            'action' => 'new_round', 'game_id' => $gameId, 'token' => $adminToken,
        ], JSON_THROW_ON_ERROR), 'https://frontend.example.test');
        check($newRound['status'] === 200, 'Nach dem manuellen Ende kann eine neue Runde beginnen');
        proc_terminate($server);
        proc_close($server);
        $server = null;
        [$server, $url] = startBingoTestServer($directory, $databaseDirectory . '/games.sqlite');
        $reconnected = bingoHttpRequest($url, 'POST', json_encode([
            'action' => 'state', 'game_id' => $gameId, 'token' => $playerToken,
        ], JSON_THROW_ON_ERROR), 'https://frontend.example.test');
        check($reconnected['status'] === 200, 'Spieler kann sich nach einem Backend-Neustart wieder verbinden');
        check(json_decode($reconnected['body'], true, 16, JSON_THROW_ON_ERROR)['game']['round'] === 2, 'Wiederverbindung stellt den gespeicherten Rundenstand wieder her');
        $restoredJoin = bingoHttpRequest($url, 'POST', json_encode([
            'action' => 'join', 'game_id' => $gameId, 'name' => 'Ignorierter Ersatzname', 'token' => $playerToken,
        ], JSON_THROW_ON_ERROR), 'https://frontend.example.test');
        check(json_decode($restoredJoin['body'], true, 16, JSON_THROW_ON_ERROR)['token'] === $playerToken, 'Wiederbeitritt verwendet das vorhandene Spielertoken');
        $unknown = bingoHttpRequest($url, 'POST', json_encode(['action' => 'unknown'], JSON_THROW_ON_ERROR));
        check($unknown['status'] === 400, 'Unbekannte Aktion erhält HTTP 400');

        $index = file_get_contents(dirname(__DIR__) . '/docs/index.html');
        $config = file_get_contents(dirname(__DIR__) . '/docs/config.js');
        check(preg_match('/connect-src[^;]*https:\/\/benjamin-lam\.de/', $index) === 1, 'Produktiver API-Host ist in der CSP connect-src freigegeben');
        check(str_contains((string) $config, 'https://benjamin-lam.de'), 'Frontend-API-Konfiguration stimmt mit der CSP überein');
        check(str_contains((string) $index, '<table id="bingo-card"') && str_contains((string) $index, '<caption'), 'Bingokarte besitzt eine semantische Tabelle mit Beschriftung');

        for ($index = 0; $index < 55; $index++) {
            check(bingoHttpRequest($url, 'POST', '{')['status'] === 400, 'Ungültige JSON-Anfragen werden gezählt und zunächst mit HTTP 400 beantwortet');
        }
        check(bingoHttpRequest($url, 'POST', '{')['status'] === 429, 'Wiederholte ungültige JSON-Anfragen werden begrenzt');
    } finally {
        if (is_resource($server)) {
            proc_terminate($server);
            proc_close($server);
        }
    }

    $publicDatabase = dirname(__DIR__) . '/backend/public/exposed-test.sqlite';
    [$errorServer, $errorUrl] = startBingoTestServer($directory, $publicDatabase);
    try {
        $internalError = bingoHttpRequest($errorUrl, 'POST', json_encode(['action' => 'create'], JSON_THROW_ON_ERROR));
        check($internalError['status'] === 500, 'Interner Backendfehler erhält HTTP 500');
        check(str_contains($internalError['body'], 'Interner Serverfehler'), 'HTTP-500-Antwort enthält keine internen Dateipfade oder SQL-Details');
        check(!file_exists($publicDatabase), 'Abgewiesener Webroot-Datenbankpfad legt keine öffentliche Datenbank an');
    } finally {
        if (is_resource($errorServer)) {
            proc_terminate($errorServer);
            proc_close($errorServer);
        }
    }
}
