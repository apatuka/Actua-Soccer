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

## Documentación de atributos (entrada)

### 1) Atributos de `match` (raíz del JSON)

- `seed` (int, opcional): semilla aleatoria para reproducibilidad.
  - Si usas el mismo input + misma `seed`, obtienes la misma simulación.
- `minutes` (int, opcional): duración del partido.
  - El script la limita a rango seguro interno (mínimo/máximo) para evitar inputs inválidos.
- `home` (objeto, opcional pero recomendado): definición del equipo local.
- `away` (objeto, opcional pero recomendado): definición del equipo visitante.

### 2) Atributos de `team` (`home` / `away`)

- `name` (string): nombre del equipo.
- `players` (array de objetos jugador): plantilla usada en la simulación.
  - Si faltan jugadores, el script autocompleta hasta 11.
  - Si sobran, recorta a 11 para el modelo espacial actual.

Además, puedes definir atributos base de equipo (todos opcionales, `1..99`), que se usan como valor por defecto para jugadores que no lo especifiquen:

- `pace`: velocidad y capacidad de cubrir metros.
- `power`: potencia física / golpeo.
- `control`: control de balón y primer toque.
- `flair`: creatividad/riesgo en decisiones técnicas.
- `vision`: lectura de juego y calidad de pase.
- `accuracy`: precisión de disparo y ejecución.
- `stamina`: resistencia y capacidad de sostener esfuerzos.
- `discipline`: tendencia a cometer faltas/entradas imprudentes.
- `fitness`: estado físico general (impacta acciones sostenidas y parte de lógica de portero simplificada).

### 3) Atributos de `player` (cada elemento de `team.players`)

- `name` (string): nombre del jugador para trazabilidad en eventos.

- `position` (string, opcional): rol táctico del jugador en el simulador.
  - Valores permitidos: `GK`, `DF`, `MF`, `FW`.
  - Afecta ubicación inicial y decisiones de IA (p.ej. `FW` remata más, `GK` queda de portero).
- `pace` (int `1..99`): influye en desplazamientos y capacidad de llegar antes.
- `power` (int `1..99`): influye en tiros/pases fuertes y duelos.
- `control` (int `1..99`): mejora retención y ejecución bajo presión.
- `flair` (int `1..99`): sesga decisiones de IA hacia soluciones creativas.
- `vision` (int `1..99`): mejora selección de líneas de pase.
- `accuracy` (int `1..99`): eleva probabilidad de remates certeros.
- `stamina` (int `1..99`): influye en recuperación/duelos y presión.
- `discipline` (int `1..99`): reduce probabilidad de falta/tarjeta cuando es alto.
- `fitness` (int `1..99`): factor físico global del jugador.

> Nota: estos nombres vienen del modelo original (`EURO_INT.H`).

### 4) Posiciones y comportamiento táctico

- `GK` (arquero): se ubica en la última línea y se usa como referencia de atajadas.
- `DF` (defensa): inicia más retrasado y tiende a participar más en recuperación/salida.
- `MF` (mediocampo): zona intermedia; balance entre pase, conducción y apoyo.
- `FW` (delantero): más adelantado; mayor sesgo a finalizar jugadas y recibir centros.

Reglas del simulador:
- Si no envías `position`, se asigna automáticamente (`GK` al primero y resto `MF` o plantilla por defecto).
- El motor mantiene 11 jugadores: completa faltantes y recorta excedentes.

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
