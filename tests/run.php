<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/backend/src/Game.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    global $assertions;
    $assertions++;
}

$directory = sys_get_temp_dir() . '/bingo-tests-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$game = new BingoGame($directory . '/games.sqlite');
$assertions = 0;

try {
    $games = [];
    for ($index = 0; $index < 12; $index++) {
        $created = $game->create();
        check(preg_match('/^[A-HJ-NP-Z2-9]{6}$/', $created['game_id']) === 1, 'Spiel-ID-Format');
        check(!isset($games[$created['game_id']]), 'Spiel-IDs müssen eindeutig sein');
        $games[$created['game_id']] = true;
    }
    $created = $game->create();
    $gameId = $created['game_id'];
    $adminToken = $created['token'];
    try {
        $game->join($gameId, 'Spielleitung', $adminToken);
        throw new RuntimeException('Die Spielleitung durfte als Spieler beitreten.');
    } catch (ApiError $exception) {
        check($exception->httpStatus === 403, 'Admin-Token kann nicht als Spieler verwendet werden');
    }
    $joined = $game->join($gameId, '<script>alert(1)</script>');
    $playerToken = $joined['token'];
    $state = $game->state($gameId, $playerToken);
    check($state['game']['role'] === 'player', 'Spielertoken wird erkannt');
    check(!isset($state['token']), 'Spielstand enthält keine Tokens');
    check($state['players'][0]['name'] === '<script>alert(1)</script>', 'Namen bleiben sicher als Text serialisiert');
    check(count($state['card']) === 5, 'Karte hat fünf Spalten');
    foreach ($state['card'] as $column => $numbers) {
        check(count($numbers) === 5, 'Spalte hat fünf Felder');
        $expectedCount = $column === 2 ? 4 : 5;
        check(count(array_filter($numbers, static fn (mixed $number): bool => $number !== null)) === $expectedCount, 'Freifeld liegt nur in der mittleren Spalte');
        $values = array_filter($numbers, static fn (mixed $number): bool => $number !== null);
        check(count($values) === count(array_unique($values)), 'Zahlen einer Spalte sind eindeutig');
        foreach ($values as $number) {
            check($number >= $column * 15 + 1 && $number <= ($column + 1) * 15, 'Zahl liegt im Spaltenbereich');
        }
    }
    check($state['card'][2][2] === null, 'Mittelfeld ist frei');

    $rejoined = $game->join($gameId, 'anderer Name', $playerToken);
    check($rejoined['token'] === $playerToken, 'Bestehende Spielende können sich erneut verbinden');
    try {
        $game->start($gameId, $playerToken);
        throw new RuntimeException('Spielertoken durfte die Runde nicht starten.');
    } catch (ApiError $exception) {
        check($exception->httpStatus === 403, 'Spieler dürfen nicht administrieren');
    }
    check($game->start($gameId, $adminToken)['ok'], 'Spielleitung startet Runde');
    try {
        $game->join($gameId, 'Zu spät');
        throw new RuntimeException('Beitritt während einer Runde wurde akzeptiert.');
    } catch (ApiError $exception) {
        check($exception->httpStatus === 409, 'Beitritt während einer laufenden Runde wird abgewiesen');
    }
    try {
        $game->mark($gameId, $playerToken, 1, true);
        throw new RuntimeException('Eine nicht gezogene Zahl wurde markiert.');
    } catch (ApiError $exception) {
        check(str_contains($exception->getMessage(), 'gezogene Zahlen'), 'Nicht gezogene Zahl wird zurückgewiesen');
    }
    try {
        $game->bingo($gameId, $playerToken);
        throw new RuntimeException('Ungültiges Bingo wurde akzeptiert.');
    } catch (ApiError $exception) {
        check($exception->httpStatus === 400, 'Ungültiges Bingo wird zurückgewiesen');
    }

    $targetLine = array_slice($state['card'][0], 0, 5);
    $allDrawn = [];
    if (function_exists('pcntl_fork')) {
        $children = [];
        for ($index = 0; $index < 2; $index++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Paralleler Testprozess konnte nicht gestartet werden.');
            }
            if ($pid === 0) {
                try {
                    $parallelGame = new BingoGame($directory . '/games.sqlite');
                    $draw = $parallelGame->draw($gameId, $adminToken);
                    file_put_contents($directory . '/draw-' . getmypid(), (string) $draw['number']);
                    exit(0);
                } catch (Throwable) {
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Parallele Zahlenziehung wird verarbeitet');
        }
        $allDrawn = $game->state($gameId, $adminToken)['game']['drawn'];
        check(count($allDrawn) === 2 && count(array_unique($allDrawn)) === 2, 'Gleichzeitige Ziehungen erzeugen zwei eindeutige Zahlen');
        foreach (glob($directory . '/draw-*') ?: [] as $file) {
            unlink($file);
        }
        foreach ($allDrawn as $number) {
            if (in_array($number, $targetLine, true)) {
                $game->mark($gameId, $playerToken, $number, true);
            }
        }
    }
    for ($index = count($allDrawn); $index < 75; $index++) {
        $draw = $game->draw($gameId, $adminToken);
        check(!in_array($draw['number'], $allDrawn, true), 'Zahlenziehung ohne Wiederholung');
        check($draw['number'] >= 1 && $draw['number'] <= 75, 'Gezogene Zahl liegt im erlaubten Bereich');
        $allDrawn[] = $draw['number'];
        if (in_array($draw['number'], $targetLine, true)) {
            $game->mark($gameId, $playerToken, $draw['number'], true);
        }
    }
    check(count(array_unique($allDrawn)) === 75, 'Alle 75 Zahlen werden genau einmal gezogen');
    $cardNumbers = array_merge(...$state['card']);
    $numberNotOnCard = current(array_diff(range(1, 75), $cardNumbers));
    try {
        $game->mark($gameId, $playerToken, $numberNotOnCard, true);
        throw new RuntimeException('Eine nicht auf der eigenen Karte stehende Zahl wurde markiert.');
    } catch (ApiError $exception) {
        check(str_contains($exception->getMessage(), 'nicht auf deiner Karte'), 'Zahl außerhalb der Karte wird zurückgewiesen');
    }
    try {
        $game->draw($gameId, $adminToken);
        throw new RuntimeException('Eine 76. Zahl wurde gezogen.');
    } catch (ApiError $exception) {
        check($exception->httpStatus === 409, 'Ziehen nach 75 Zahlen wird abgewiesen');
    }
    $bingo = $game->bingo($gameId, $playerToken);
    check($bingo['valid'] && $bingo['winner'] === '<script>alert(1)</script>', 'Vollständige Reihe beendet Runde');
    check($game->state($gameId, $adminToken)['game']['status'] === 'finished', 'Runde endet serverseitig');

    check($game->newRound($gameId, $adminToken)['ok'], 'Spielleitung kann neue Runde vorbereiten');
    $newState = $game->state($gameId, $playerToken);
    check($newState['game']['status'] === 'lobby' && $newState['game']['round'] === 2, 'Neue Runde kehrt in Lobby zurück');
    check($newState['game']['drawn'] === [] && $newState['marks'] === [], 'Neue Runde setzt Ziehungen und Markierungen zurück');
    check($newState['card'][2][2] === null, 'Neue Karte hat weiterhin ein Freifeld');

    echo "OK: $assertions Assertions\n";
} finally {
    foreach (glob($directory . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($directory);
}
