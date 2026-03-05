<?php

declare(strict_types=1);

/**
 * Simulador textual con IA CPU inspirada en INTELL.CPP (Actua/Euro).
 *
 * Ideas fuente trasladadas:
 * - got_ball(): shoot -> cross -> pass -> punt/run
 * - pass_decide(): ranking de receptores por distancia, rivales cerca y progreso
 * - we_have_ball(): apoyo y desmarque
 * - opp_has_ball(): close down, tackle/intercept
 */

const SOURCE_PLAYER_ATTRS = ['pace','power','control','flair','vision','accuracy','stamina','discipline','fitness'];
const PITCH_X = 100.0;
const PITCH_Y = 64.0;
const TICKS_PER_MIN = 6;

function readInput(array $argv): string {
    if (isset($argv[1])) {
        if (!is_file($argv[1])) {
            fwrite(STDERR, "Input file not found: {$argv[1]}\n");
            exit(1);
        }
        return (string) file_get_contents($argv[1]);
    }
    $stdin = stream_get_contents(STDIN);
    if ($stdin === false || trim($stdin) === '') {
        fwrite(STDERR, "Provide JSON via file path or stdin.\n");
        exit(1);
    }
    return $stdin;
}

function clamp(int $value, int $min, int $max): int { return max($min, min($max, $value)); }
function randFloat(): float { return mt_rand() / mt_getrandmax(); }
function dist(array $a, array $b): float { return hypot($a['x'] - $b['x'], $a['y'] - $b['y']); }

function normalizeTeam(array $team, string $fallbackName): array {
    $name = (string)($team['name'] ?? $fallbackName);
    $base = ['name' => $name, 'players' => []];
    foreach (SOURCE_PLAYER_ATTRS as $attr) {
        $base[$attr] = clamp((int)($team[$attr] ?? 55), 1, 99);
    }

    $players = $team['players'] ?? [];
    if (!is_array($players) || count($players) === 0) {
        for ($i = 1; $i <= 11; $i++) {
            $p = ['name' => "{$name} Player {$i}"];
            foreach (SOURCE_PLAYER_ATTRS as $attr) { $p[$attr] = $base[$attr]; }
            $players[] = $p;
        }
    }

    foreach ($players as $i => $p) {
        $n = ['name' => (string)($p['name'] ?? "{$name} Player " . ($i+1))];
        foreach (SOURCE_PLAYER_ATTRS as $attr) {
            $n[$attr] = clamp((int)($p[$attr] ?? $base[$attr]), 1, 99);
        }
        $base['players'][] = $n;
    }

    // Asegurar 11 jugadores para la simulación espacial.
    while (count($base['players']) < 11) {
        $i = count($base['players']) + 1;
        $n = ['name' => "{$name} Player {$i}"];
        foreach (SOURCE_PLAYER_ATTRS as $attr) {
            $n[$attr] = $base[$attr];
        }
        $base['players'][] = $n;
    }

    if (count($base['players']) > 11) {
        $base['players'] = array_slice($base['players'], 0, 11);
    }

    return $base;
}

function initPositions(array $team, bool $home): array {
    $out = [];
    $baseX = $home ? 30.0 : 70.0;
    $dir = $home ? 1.0 : -1.0;
    $shape = [
        [0,0],[10,-20],[10,20],[18,-10],[18,10],[30,-22],[30,0],[30,22],[43,-12],[43,12],[50,0]
    ];
    foreach ($team['players'] as $i => $p) {
        $sx = $baseX + $shape[$i][0] * $dir;
        $sy = 32 + $shape[$i][1] * 0.7;
        $out[] = [
            'name' => $p['name'],
            'attrs' => $p,
            'x' => max(1, min(PITCH_X-1, $sx)),
            'y' => max(1, min(PITCH_Y-1, $sy)),
            'team' => $home ? 'home' : 'away',
            'role' => $i === 0 ? 'GK' : 'FP',
        ];
    }
    return $out;
}

function moveTowards(array &$player, array $target, float $factor = 1.0): void {
    $dx = $target['x'] - $player['x'];
    $dy = $target['y'] - $player['y'];
    $d = hypot($dx, $dy);
    if ($d < 0.01) return;
    $step = (0.45 + ($player['attrs']['pace'] / 140.0)) * $factor;
    $player['x'] += ($dx / $d) * min($step, $d);
    $player['y'] += ($dy / $d) * min($step, $d);
    $player['x'] = max(0.5, min(PITCH_X-0.5, $player['x']));
    $player['y'] = max(0.5, min(PITCH_Y-0.5, $player['y']));
}

function nearestOpponentDistance(array $holder, array $opponents): float {
    $m = 999;
    foreach ($opponents as $o) $m = min($m, dist($holder, $o));
    return $m;
}

function choosePassTarget(int $holderIdx, array $teamPlayers, array $oppPlayers, bool $homeAttacksRight): ?int {
    $holder = $teamPlayers[$holderIdx];
    $bestIdx = null;
    $bestScore = PHP_FLOAT_MAX; // en INTELL.CPP: menor = mejor preferencia

    foreach ($teamPlayers as $i => $mate) {
        if ($i === $holderIdx) continue;
        $d = dist($holder, $mate);
        if ($d < 4 || $d > 45) continue;

        $oppNear = 0;
        foreach ($oppPlayers as $opp) {
            if (dist($mate, $opp) < 10) $oppNear++;
        }

        $forward = $homeAttacksRight ? ($mate['x'] - $holder['x']) : ($holder['x'] - $mate['x']);
        $optDistPenalty = abs(12 - $d) * 1.5;
        $pressurePenalty = $oppNear * (6 + ($holder['attrs']['flair'] / 20));
        $backPassPenalty = $forward < 0 ? abs($forward) * 1.2 : 0;
        $visionBonus = ($holder['attrs']['vision'] + $holder['attrs']['flair']) / 10;

        $score = 40 + $optDistPenalty + $pressurePenalty + $backPassPenalty - $visionBonus;
        if ($score < $bestScore) {
            $bestScore = $score;
            $bestIdx = $i;
        }
    }

    return $bestIdx;
}

function attemptPass(array &$state, string $teamKey, int $holderIdx, int $targetIdx, int $minute): void {
    $oppKey = $teamKey === 'home' ? 'away' : 'home';
    $holder = $state[$teamKey]['players'][$holderIdx];
    $target = $state[$teamKey]['players'][$targetIdx];
    $interceptors = $state[$oppKey]['players'];

    $d = dist($holder, $target);
    $skill = ($holder['attrs']['vision'] * 0.40 + $holder['attrs']['control'] * 0.35 + $holder['attrs']['flair'] * 0.25);
    $pressure = max(0, 12 - nearestOpponentDistance($holder, $interceptors));
    $successProb = 0.55 + (($skill - 55) / 120) - ($d / 100) - ($pressure / 25);
    $successProb = max(0.10, min(0.93, $successProb));

    $state['events'][] = [
        'minute' => $minute,
        'type' => 'pass_attempt',
        'team' => $state[$teamKey]['name'],
        'player' => $holder['name'],
        'target' => $target['name'],
        'description' => "{$holder['name']} intenta pase a {$target['name']}",
    ];

    if (randFloat() < $successProb) {
        $state['ball']['team'] = $teamKey;
        $state['ball']['holder'] = $targetIdx;
        $state['events'][] = [
            'minute' => $minute,
            'type' => 'pass_complete',
            'team' => $state[$teamKey]['name'],
            'player' => $target['name'],
            'description' => "Pase completado para {$target['name']}",
        ];
    } else {
        $winner = array_rand($state[$oppKey]['players']);
        $state['ball']['team'] = $oppKey;
        $state['ball']['holder'] = (int)$winner;
        $state['events'][] = [
            'minute' => $minute,
            'type' => 'interception',
            'team' => $state[$oppKey]['name'],
            'player' => $state[$oppKey]['players'][$winner]['name'],
            'description' => "Intercepción de {$state[$oppKey]['players'][$winner]['name']}",
        ];
    }
}

function attemptShot(array &$state, string $teamKey, int $holderIdx, int $minute): void {
    $oppKey = $teamKey === 'home' ? 'away' : 'home';
    $shooter = $state[$teamKey]['players'][$holderIdx];
    $gk = $state[$oppKey]['players'][0];
    $goalX = $teamKey === 'home' ? PITCH_X : 0.0;
    $distance = abs($goalX - $shooter['x']);

    $finishing = $shooter['attrs']['accuracy'] * 0.5 + $shooter['attrs']['power'] * 0.3 + $shooter['attrs']['flair'] * 0.2;
    $gkSkill = $gk['attrs']['control'] * 0.35 + $gk['attrs']['vision'] * 0.30 + $gk['attrs']['fitness'] * 0.35;
    $prob = 0.22 + (($finishing - $gkSkill) / 180) - ($distance / 120);
    $prob = max(0.04, min(0.75, $prob));

    $state['events'][] = [
        'minute' => $minute,
        'type' => 'shot',
        'team' => $state[$teamKey]['name'],
        'player' => $shooter['name'],
        'description' => "Remate de {$shooter['name']}",
    ];

    if (randFloat() < $prob) {
        $state[$teamKey]['goals']++;
        $state['events'][] = [
            'minute' => $minute,
            'type' => 'goal',
            'team' => $state[$teamKey]['name'],
            'player' => $shooter['name'],
            'score' => "{$state['home']['name']} {$state['home']['goals']} - {$state['away']['goals']} {$state['away']['name']}",
            'description' => "GOAL de {$shooter['name']}",
        ];
        $state['ball']['team'] = $oppKey;
        $state['ball']['holder'] = 10;
    } else {
        if (randFloat() < 0.6) {
            $state['events'][] = [
                'minute' => $minute,
                'type' => 'shot_saved',
                'team' => $state[$oppKey]['name'],
                'player' => $gk['name'],
                'description' => "Atajada de {$gk['name']}",
            ];
            $state['ball']['team'] = $oppKey;
            $state['ball']['holder'] = 0;
        } else {
            $state['events'][] = [
                'minute' => $minute,
                'type' => 'shot_off_target',
                'team' => $state[$teamKey]['name'],
                'player' => $shooter['name'],
                'description' => "Remate desviado de {$shooter['name']}",
            ];
            $state['ball']['team'] = $oppKey;
            $state['ball']['holder'] = 0;
        }
    }
}

function cpuDecision(array &$state, int $minute): void {
    $teamKey = $state['ball']['team'];
    $oppKey = $teamKey === 'home' ? 'away' : 'home';
    $holderIdx = $state['ball']['holder'];

    $holder = $state[$teamKey]['players'][$holderIdx];
    $oppPlayers = $state[$oppKey]['players'];
    $homeAttacksRight = $teamKey === 'home';

    $pressureDist = nearestOpponentDistance($holder, $oppPlayers);
    $inCrossArea = $homeAttacksRight
        ? ($holder['x'] > 82 && ($holder['y'] < 18 || $holder['y'] > 46))
        : ($holder['x'] < 18 && ($holder['y'] < 18 || $holder['y'] > 46));

    // got_ball(): prioridad shoot -> cross/pass -> run
    $distanceToGoal = $homeAttacksRight ? (PITCH_X - $holder['x']) : $holder['x'];
    $shootChance = 0.08 + (($holder['attrs']['accuracy'] + $holder['attrs']['power']) / 260) - ($distanceToGoal / 140);
    if ($pressureDist > 4) $shootChance += 0.05;
    if ($distanceToGoal < 22 && randFloat() < max(0.03, min(0.65, $shootChance))) {
        attemptShot($state, $teamKey, $holderIdx, $minute);
        return;
    }

    $targetIdx = choosePassTarget($holderIdx, $state[$teamKey]['players'], $oppPlayers, $homeAttacksRight);
    if ($targetIdx !== null) {
        // en zona de centro, favorece pase rápido (cross-like)
        attemptPass($state, $teamKey, $holderIdx, $targetIdx, $minute);
        return;
    }

    // we_have_ball(): conducir si no hay pase/tiro
    $forward = $homeAttacksRight ? 1 : -1;
    $holderRef =& $state[$teamKey]['players'][$holderIdx];
    moveTowards($holderRef, [
        'x' => $holderRef['x'] + (6 * $forward),
        'y' => $holderRef['y'] + ((randFloat() - 0.5) * 6),
    ], 1.0);

    $state['events'][] = [
        'minute' => $minute,
        'type' => $inCrossArea ? 'carry_wide' : 'dribble',
        'team' => $state[$teamKey]['name'],
        'player' => $holderRef['name'],
        'description' => $inCrossArea
            ? "{$holderRef['name']} progresa por banda"
            : "Conducción de {$holderRef['name']}",
    ];

    // opp_has_ball(): presión/tackle si rival está muy cerca
    $closestOppIdx = 0;
    $closestD = 999;
    foreach ($state[$oppKey]['players'] as $i => $opp) {
        $d = dist($holderRef, $opp);
        if ($d < $closestD) { $closestD = $d; $closestOppIdx = $i; }
    }

    if ($closestD < 2.4) {
        $tackler = $state[$oppKey]['players'][$closestOppIdx];
        $tackleProb = 0.25 + (($tackler['attrs']['stamina'] + $tackler['attrs']['discipline']) / 260)
            - (($holderRef['attrs']['control'] + $holderRef['attrs']['flair']) / 320);
        $tackleProb = max(0.08, min(0.62, $tackleProb));

        if (randFloat() < $tackleProb) {
            $state['ball']['team'] = $oppKey;
            $state['ball']['holder'] = $closestOppIdx;
            $state['events'][] = [
                'minute' => $minute,
                'type' => 'tackle_won',
                'team' => $state[$oppKey]['name'],
                'player' => $tackler['name'],
                'description' => "Robo de {$tackler['name']}",
            ];
        } else {
            $foulProb = (100 - $tackler['attrs']['discipline']) / 220;
            if (randFloat() < $foulProb) {
                $state[$oppKey]['yellow']++;
                $state['events'][] = [
                    'minute' => $minute,
                    'type' => 'yellow_card',
                    'team' => $state[$oppKey]['name'],
                    'player' => $tackler['name'],
                    'description' => "Falta y amarilla para {$tackler['name']}",
                ];
            }
        }
    }
}

function simulateMatch(array $payload): array {
    $minutes = isset($payload['minutes']) ? max(10, min(130, (int)$payload['minutes'])) : 90;
    $seed = isset($payload['seed']) ? (int)$payload['seed'] : random_int(1, PHP_INT_MAX);
    mt_srand($seed);

    $home = normalizeTeam($payload['home'] ?? [], 'Home');
    $away = normalizeTeam($payload['away'] ?? [], 'Away');

    $state = [
        'home' => ['name' => $home['name'], 'players' => initPositions($home, true), 'goals' => 0, 'yellow' => 0, 'red' => 0],
        'away' => ['name' => $away['name'], 'players' => initPositions($away, false), 'goals' => 0, 'yellow' => 0, 'red' => 0],
        'ball' => ['team' => 'home', 'holder' => 10],
        'events' => [[
            'minute' => 0,
            'type' => 'kickoff',
            'description' => "Kickoff: {$home['name']} vs {$away['name']}",
        ]],
    ];

    $totalTicks = $minutes * TICKS_PER_MIN;
    for ($tick = 1; $tick <= $totalTicks; $tick++) {
        if (randFloat() > 0.58) continue; // no cada tick genera evento textual
        $minute = (int)ceil($tick / TICKS_PER_MIN);
        cpuDecision($state, $minute);
    }

    $state['events'][] = [
        'minute' => $minutes,
        'type' => 'full_time',
        'description' => "Full time: {$state['home']['name']} {$state['home']['goals']} - {$state['away']['goals']} {$state['away']['name']}",
    ];

    usort($state['events'], static fn(array $a, array $b): int => $a['minute'] <=> $b['minute']);

    return [
        'meta' => [
            'engine' => 'actua-cpu-ai-text-sim',
            'seed' => $seed,
            'minutes' => $minutes,
            'source_reference' => [
                'EURO_INT.H player_data attrs',
                'INTELL.CPP: got_ball, pass_decide, we_have_ball, opp_has_ball, intelligence',
            ],
            'home' => $state['home']['name'],
            'away' => $state['away']['name'],
        ],
        'result' => [
            'home' => ['team' => $state['home']['name'], 'goals' => $state['home']['goals'], 'yellow_cards' => $state['home']['yellow'], 'red_cards' => $state['home']['red']],
            'away' => ['team' => $state['away']['name'], 'goals' => $state['away']['goals'], 'yellow_cards' => $state['away']['yellow'], 'red_cards' => $state['away']['red']],
        ],
        'events' => $state['events'],
    ];
}

$payload = json_decode(readInput($argv), true);
if (!is_array($payload)) {
    fwrite(STDERR, "Invalid JSON input.\n");
    exit(1);
}

echo json_encode(simulateMatch($payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
