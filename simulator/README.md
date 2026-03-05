# Simulador de soccer en texto (PHP) con IA CPU basada en el código fuente

Este simulador ya no usa solo probabilidades por minuto: implementa una IA simplificada basada en la lógica de `INTELL.CPP` del juego original.

## Investigación de la IA en el fuente original

### Atributos reales de jugador
Del archivo `EURO_INT.H` (`player_data`):

- `pace`, `power`, `control`, `flair`, `vision`, `accuracy`, `stamina`, `discipline`, `fitness`.

### Flujo de decisión CPU (INTELL.CPP)
El simulador se inspira en estas funciones:

- `got_ball(...)`: prioridad de decisiones con balón (shoot → cross/pass → run).
- `pass_decide(...)`: evalúa candidatos de pase por distancia, visión/flair, rivales cerca, progreso del pase.
- `we_have_ball(...)`: apoyos y desmarques cuando tu equipo tiene la posesión.
- `opp_has_ball(...)`: cerrar espacios, interceptar o tacklear cuando el rival tiene la pelota.
- `intelligence(...)`: bucle principal de decisiones por jugador.

## Qué implementa en PHP

Archivo: `simulator/text_soccer_simulator.php`

- Simulación por *ticks* (`TICKS_PER_MIN=6`) para aproximar decisiones continuas de CPU.
- Estado espacial simplificado (campo 2D, posiciones de jugadores).
- Decisiones de poseedor del balón:
  - `shot`/`goal`
  - `pass_attempt`/`pass_complete`/`interception`
  - `dribble` y progresión por banda
- Respuesta defensiva:
  - `tackle_won`
  - faltas/`yellow_card`
- Salida JSON con `meta`, `result`, `events`.

## Uso

```bash
php simulator/text_soccer_simulator.php simulator/sample_match.json
```

o

```bash
cat simulator/sample_match.json | php simulator/text_soccer_simulator.php
```

## Input JSON

Mantiene atributos alineados a `player_data` (`EURO_INT.H`).
Ver ejemplo: `simulator/sample_match.json`.

## Nota importante

Es una **reimplementación textual** (no el motor exacto), pero la estructura de decisiones CPU se diseñó a partir del comportamiento observado en `INTELL.CPP`.
