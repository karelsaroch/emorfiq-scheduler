# Emorfiq Scheduler & Mutex – návrh

Cíl: plánování úloh (cron) a distribuovaný zámek tak, aby aplikační kód **nezávisel na Laravelu**
a engine šlo kdykoli vyměnit (Laravel Scheduler → crunz → vlastní), a s **vlastním spolehlivým mutexem**
místo Laravel `EventMutex`/`SchedulingMutex`.

## 1. Hlavní myšlenka

Engine (Laravel / crunz) dělá **jen časovač**: v čase podle cronu spustí jeden a ten samý příkaz

```
<console> scheduler:run-task <task-id> --scheduled-at=<ISO 8601 minuta>
```

Vše ostatní – definice úloh, zámky, DI, logování, zpracování chyb – je v naší vrstvě, která o enginu nic neví.
Výměna enginu = výměna jednoho adaptéru (`SchedulerInterface`); úlohy, handlery, mutex ani crontab na serveru se nemění.

```
 systémový cron (* * * * *)
        │  php artisan scheduler:tick          ← řádek v crontabu je pro všechny enginy stejný
        ▼
 ┌──────────────────────┐   register(TaskDefinition…)   ┌──────────────────────┐
 │ SchedulerInterface   │◄──────────────────────────────│ TaskRegistry          │◄── TaskProviderInterface
 │  LaravelScheduler    │                               │  (zdroj pravdy)       │    (moduly aplikace)
 │  CrunzScheduler      │                               └──────────▲───────────┘
 │  NativeScheduler     │                                          │ get(id)
 └─────────┬────────────┘                                          │
           │ ve splatnou minutu spustí proces                      │
           ▼                                                       │
   scheduler:run-task <id> ──► TaskRunnerInterface ────────────────┘
                                 │  1. zámek výskytu  (onOneServer)
                                 │  2. běhový zámek   (withoutOverlapping)      MutexInterface
                                 │  3. handler->handle(TaskContext)  ─────────► └─ LockStoreInterface
                                 ▼                                                 PDO | Redis | flock | memory
                           TaskRunResult (status, čas, chyba) → exit code
```

## 2. Struktura

| Vrstva | Namespace | Závislosti |
|---|---|---|
| Mutex (doména) | `Emorfiq\Mutex` | pouze PHP + PSR-20 (clock) |
| Scheduler (doména) | `Emorfiq\Scheduler` | PSR-3 log, PSR-11 container, PSR-20 clock |
| Adaptér Laravel | `Emorfiq\Bridge\Laravel` | `illuminate/*` – **jediné místo**, kde se Laravel vyskytuje |
| Adaptér crunz | `Emorfiq\Bridge\Crunz` | `crunzphp/crunz` (připraveno, neověřeno) |
| Vlastní engine | `Emorfiq\Bridge\Native` | nic |

PSR rozhraní nejsou vazba na konkrétní knihovnu – implementuje je Laravel i Symfony.

## 3. Mutex

### `MutexInterface`

| Metoda | Parametry | Návrat | Popis |
|---|---|---|---|
| `acquire` | `non-empty-string $key`, `positive-int $ttlSeconds`, `int<0,max> $waitSeconds = 0` | `?LockInterface` | Získá zámek; `null` = drží ho někdo jiný. S `$waitSeconds` > 0 polluje. |
| `synchronized` | `non-empty-string $key`, `callable(LockInterface): T $callback`, `positive-int $ttlSeconds`, `int<0,max> $waitSeconds = 0` | `T` | Provede callback pod zámkem, vždy uvolní. Vyhodí `LockNotAcquiredException`. |
| `isLocked` | `non-empty-string $key` | `bool` | |
| `forceRelease` | `non-empty-string $key` | `void` | Jen pro provozní zásah. |

### `LockInterface` (handle získaného zámku)

| Metoda | Parametry | Návrat | Popis |
|---|---|---|---|
| `key()` / `token()` | – | `non-empty-string` | Token = náhodných 128 bitů, identifikuje vlastníka. |
| `expiresAt()` | – | `DateTimeImmutable` | |
| `refresh` | `?positive-int $ttlSeconds = null` | `bool` | Heartbeat; `false` = zámek jsme ztratili → ukončit práci. |
| `release()` | – | `bool` | Smaže jen **vlastní** zámek; idempotentní. |
| `isHeld()` | – | `bool` | Ověří vlastnictví proti úložišti. |

### `LockStoreInterface` (SPI pro úložiště)

`tryAcquire(string $key, string $token, int $ttlMs): bool`, `release(string $key, string $token): bool`,
`refresh(string $key, string $token, int $ttlMs): bool`, `owner(string $key): ?string`, `forceRelease(string $key): void`.
Každá operace musí být atomická a kontrolovat token. Logiku čekání, prefixů a tokenů řeší společná třída `Mutex`,
takže nové úložiště je cca 50 řádků.

| Úložiště | Použití | Atomicita |
|---|---|---|
| `PdoLockStore` | **výchozí**, cluster, MySQL/PostgreSQL/SQLite | PK na `lock_key`; smazání jen expirovaného + `INSERT` (porušení unikátnosti = obsazeno) |
| `RedisLockStore` | cluster, nízká latence | `SET NX PX` + Lua compare-and-delete / compare-and-pexpire |
| `FlockLockStore` | jen jeden server | jádro OS; zámek zmizí i při `kill -9` |
| `InMemoryLockStore` | testy | – |

### Proč ne Laravel mutex

- `CacheEventMutex` ukládá do cache jen `true` bez identity vlastníka a uvolňuje přes `forget()`: když běh překročí TTL
  a zámek mezitím získá jiný proces, původní proces při dokončení **smaže cizí zámek** → souběh.
- Výchozí expirace `withoutOverlapping()` je 24 h: po pádu procesu (OOM, `kill`, deploy) se úloha **až den nespustí**.
- U úloh na pozadí se zámek maže až v `schedule:finish` callbacku – když proces umře, callback se nezavolá.
- Atomicita `add()` závisí na cache driveru (např. `file` není atomický napříč procesy).
- Neexistuje prodloužení zámku (heartbeat) pro dlouhé úlohy.

Náš mutex: token vlastníka u každé operace, povinné rozumné TTL, `refresh()` pro dlouhé úlohy a úložiště
vybrané explicitně (nesdílí se s aplikační cache, kterou může někdo vyprázdnit).

## 4. Scheduler

### `TaskDefinition` (immutable, čistá data – žádné closure)

| Parametr | Typ | Výchozí | Popis |
|---|---|---|---|
| `id` | `non-empty-string` `[a-z0-9][a-z0-9._-]{0,99}` | – | Stabilní ID, zároveň klíč zámků a argument CLI. |
| `cron` | `CronExpression` | – | 5polový crontab zápis nebo makro (`@daily`…). |
| `handler` | `class-string<TaskHandlerInterface>` | – | Instancuje se přes DI kontejner. |
| `parameters` | `array<string, scalar\|array\|null>` | `[]` | Statické parametry pro handler. |
| `policy` | `ExecutionPolicy` | výchozí | Chování při souběhu. |
| `timezone` | `?DateTimeZone` | `null` (zóna aplikace) | Zóna pro vyhodnocení cronu. |
| `environments` | `list<non-empty-string>` | `[]` = všude | Kde se úloha plánuje. |
| `description` | `?string` | `null` | |
| `enabled` | `bool` | `true` | |

Fluent API: `TaskDefinition::cron(id, expr, handler)`, `withParameters()`, `withPolicy()`, `withTimezone()`,
`onlyIn(...)`, `withDescription()`, `disabled()`; dotazy `isDue(DateTimeImmutable)`, `nextRunAt(DateTimeImmutable)`, `runsIn(string)`.

Proč bez closure: úloha se spouští v **jiném procesu**, než kde byla naplánována, a crunz closure neumí
přenést tak jako Laravel. ID + třída handleru jde přenést kamkoli.

### `ExecutionPolicy`

| Parametr | Typ | Výchozí | Popis |
|---|---|---|---|
| `withoutOverlapping` | `bool` | `true` | Nespustit, když předchozí běh ještě běží. |
| `onOneServer` | `bool` | `false` | Jeden výskyt (minuta) jen na jednom serveru; zákaz souběhu pak platí pro celý cluster. |
| `lockTtlSeconds` | `positive-int` | `3600` | TTL běhového zámku. |
| `lockWaitSeconds` | `int<0,max>` | `0` | Čekání na doběhnutí předchozího běhu. |
| `runInBackground` | `bool` | `true` | Engine nečeká na doběhnutí. |

Továrny: `ExecutionPolicy::default()`, `::clusterExclusive(int $ttl)`, `::allowOverlapping()`.

### `CronExpression`

Vlastní parser/vyhodnocení (bez Laravelu a bez `dragonmantank/cron-expression`): `*`, čísla, `a-b`, `a,b`, `*/n`, `a-b/n`, `a/n`,
názvy `JAN–DEC`, `SUN–SAT`, `0` i `7` = neděle, makra `@yearly @annually @monthly @weekly @daily @midnight @hourly`.
Den v měsíci + den v týdnu se při omezení obou kombinují přes OR (jako Vixie cron).
API: `new CronExpression(string)`, `::isValid(string): bool`, `matches(DateTimeInterface): bool`,
`nextRunAt(DateTimeImmutable $from, bool $inclusive = false): DateTimeImmutable`, `$expression` (normalizovaný řetězec předávaný enginu).

### Rozhraní

```php
interface TaskHandlerInterface {          // implementuje každá úloha
    public function handle(TaskContext $context): void;   // selhání = výjimka
}

final class TaskContext {
    public readonly TaskDefinition $task;
    public readonly DateTimeImmutable $scheduledAt;      // minuta naplánování, ne skutečný start
    public readonly string $runId;                       // korelace v logu
    public function parameter(string $name, mixed $default = null): mixed;
    public function heartbeat(?int $ttlSeconds = null): bool;   // prodlouží zámek; false = ztracen
}

interface TaskProviderInterface {         // modul aplikace dodává své úlohy
    /** @return iterable<TaskDefinition> */
    public function tasks(): iterable;
}

interface TaskRegistryInterface {
    public function add(TaskDefinition $task): void;                 // DuplicateTaskException
    public function get(string $id): TaskDefinition;                 // TaskNotFoundException
    public function has(string $id): bool;
    /** @return list<TaskDefinition> */ public function all(): array;
    /** @return list<TaskDefinition> */ public function due(DateTimeImmutable $at, string $environment): array;
}

interface SchedulerInterface {            // PORT NA ENGINE – jediná věc, která se při výměně mění
    public function register(TaskDefinition ...$tasks): void;
    public function tick(): int;          // jeden tick enginu, exit code
}

interface TaskRunnerInterface {           // engine-agnostické provedení jednoho běhu
    public function run(string $taskId, ?DateTimeImmutable $scheduledAt = null): TaskRunResult;
}

interface HandlerResolverInterface {
    /** @param class-string<TaskHandlerInterface> $handlerClass */
    public function resolve(string $handlerClass): TaskHandlerInterface;
}
```

`TaskRunResult`: `taskId`, `runId`, `status: TaskRunStatus`, `scheduledAt`, `?startedAt`, `?finishedAt`, `?Throwable error`,
`durationMs(): ?int`, `exitCode(): int`.
`TaskRunStatus`: `Succeeded`, `Failed`, `SkippedOverlapping`, `SkippedAlreadyHandled`, `SkippedDisabled`.

### Algoritmus `TaskRunner::run()`

1. Najde definici v registru, `scheduledAt` zaokrouhlí na minutu.
2. `onOneServer` → `acquire("scheduler:occurrence:<id>:<YmdHi>", 3600)`. Neuvolňuje se, nechá se vypršet, takže
   i rychle doběhnutá úloha se na serveru s opožděnými hodinami nespustí podruhé. Neúspěch → `SkippedAlreadyHandled`.
3. `withoutOverlapping` → `acquire("scheduler:run:<id>[:<hostname>]", lockTtlSeconds, lockWaitSeconds)`;
   hostname se přidá jen bez `onOneServer` (pak se zákaz souběhu týká jen daného serveru). Neúspěch → `SkippedOverlapping`.
4. Resolve handleru z DI, `handle(TaskContext)`, zachycení výjimky → `Failed` + log (PSR-3).
5. `finally`: uvolnění běhového zámku; pokud už nebyl náš, zaloguje varování (TTL je moc krátké).

## 5. Laravel integrace (aktuální stav)

- `SchedulerServiceProvider` – bindingy (`MutexInterface`, `TaskRegistryInterface`, `TaskRunnerInterface`, `SchedulerInterface`),
  příkazy, konfigurace, migrace; přes `callAfterResolving(Schedule::class, …)` předá úlohy Laravelu při `schedule:run`/`schedule:list`.
- `LaravelScheduler::register()` mapuje každou úlohu na
  `$schedule->command('scheduler:run-task', [$id, '--scheduled-at=…'])->cron(...)->timezone(...)->runInBackground()`.
  **Nepoužívá** `withoutOverlapping()`, `onOneServer()`, `call()` ani `job()`.
- `scheduler:tick` → `SchedulerInterface::tick()` → `schedule:run`.
- Konfigurace `config/scheduler.php` (`SCHEDULER_MUTEX_STORE=database|redis|flock`), migrace tabulky `scheduler_locks`.
- Schéma zámků je **jen v migracích**, `PdoLockStore` DDL nespouští:
  - `database/sql/scheduler_locks.sql` (+ `.down.sql`) – přenositelné SQL (MySQL, PostgreSQL, SQLite) pro libovolný migrační nástroj nebo ruční spuštění,
  - `database/migrations/…_create_scheduler_locks_table.php` – Laravel varianta, respektuje `scheduler.mutex.database.connection` a `.table`.
  - Testy zakládají tabulku ze SQL souboru, takže ho ověřují na všech backendech.

Crontab:

```
* * * * * cd /var/www/app && php artisan scheduler:tick >> /dev/null 2>&1
```

Registrace úloh v modulu:

```php
final class BillingTasks implements TaskProviderInterface
{
    public function tasks(): iterable
    {
        yield TaskDefinition::cron('billing.invoice-export', '*/15 8-18 * * MON-FRI', InvoiceExportHandler::class)
            ->withParameters(['batch' => 500])
            ->withPolicy(ExecutionPolicy::clusterExclusive(lockTtlSeconds: 900))
            ->withTimezone('Europe/Prague')
            ->onlyIn('production', 'staging')
            ->withDescription('Export faktur do účetnictví');
    }
}

// BillingServiceProvider::register()
$this->app->tag([BillingTasks::class], SchedulerServiceProvider::TASK_PROVIDER_TAG);

final class InvoiceExportHandler implements TaskHandlerInterface
{
    public function __construct(private readonly InvoiceExporter $exporter) {}

    public function handle(TaskContext $context): void
    {
        foreach ($this->exporter->pendingBatches($context->parameter('batch', 100)) as $batch) {
            $this->exporter->export($batch);
            if (!$context->heartbeat()) {
                return; // zámek ztracen – bezpečně končíme
            }
        }
    }
}
```

## 6. Přechod na crunz

1. `composer require crunzphp/crunz`.
2. Pro crunz musí jít aplikaci nastartovat (DI kontejner) mimo Laravel HTTP/console kernel –
   `tasks/EmorfiqTasks.php` sestaví registr a zavolá `CrunzScheduler::register()` (viz docblock v `src/Bridge/Crunz/CrunzScheduler.php`).
3. Přepnout binding `SchedulerInterface` na `CrunzScheduler`.
4. Crontab, `TaskDefinition`, handlery, `TaskRunner` i mutex zůstávají beze změny. `scheduler:run-task` je potřeba jen jako
   příkaz v libovolné konzoli (artisan nebo Symfony Console).

Jako zálohu bez knihoven obsahuje návrh i `NativeScheduler` (~60 řádků): při ticku vybere `registry->due()` a pro každou
úlohu spustí `scheduler:run-task` jako samostatný proces.

## 7. Testování (Docker)

Požadavky: Docker + Docker Compose v2, `make`. Vše běží v kontejnerech (PHP 8.4 CLI s pdo_mysql, pdo_pgsql, redis, pcntl;
MySQL 8.0, PostgreSQL 17, Redis 7; databáze v tmpfs).

```bash
make help              # přehled příkazů
make test              # build, composer install, všechny PHPUnit testy
make test-unit         # cron, definice úloh, runner, mutex nad pamětí/SQLite
make test-integration  # kontrakt mutexu proti MySQL / PostgreSQL / Redis / flock / SQLite + souběžné procesy
make test-laravel      # Laravel bridge přes Orchestra Testbench
make e2e               # 2 uzly, reálné minutové ticky, ověření invariantů (E2E_MINUTES=4 → ~5 min)
make down
```

### Co se testuje

| Sada | Obsah |
|---|---|
| `unit` | Parser a vyhodnocení cronu (match, next run, makra, OR den/týden, neplatné výrazy), `TaskDefinition`, `TaskRunner` (overlap na serveru i v clusteru, onOneServer, heartbeat, chyby, prostředí). |
| `integration` | **Jeden kontrakt** (`tests/Support/LockStoreContractTestCase.php`) spuštěný proti každému úložišti: získání/uvolnění, expirace, *starý vlastník nesmí uvolnit ani prodloužit cizí zámek*, refresh, dlouhé klíče, čekání. `ConcurrencyTest`: 12 samostatných procesů současně → vyhraje právě jeden; 12 procesů × 15 ne-atomických read-modify-write pod zámkem → žádný ztracený zápis. |
| `laravel` | Bindingy provideru, registrace do Laravel `Schedule` (cron, timezone, background, **nepoužívá** Laravel mutex), `dueEvents()`, `scheduler:run-task` (exit codes, zámek v DB během běhu, zámek výskytu). |
| e2e | Minimální **skutečná Laravel 12 aplikace** (`e2e/app`) na dvou uzlech (`node1`, `node2`) se sdílenou MySQL; každý uzel každou minutu volá `php artisan scheduler:tick` (jako crontab). Výsledky všech běhů zapisuje dekorátor `RecordingTaskRunner`, `e2e:assert` pak ověří invarianty. |

E2E invarianty (`e2e/app/src/Console/AssertCommand.php`):

- `e2e.per-server` – každou minutu na **každém** uzlu,
- `e2e.cluster-once` (`onOneServer`) – každou minutu **právě jednou** v clusteru, druhý uzel `skipped_already_handled`,
- `e2e.long-exclusive` – běží 80 s s TTL zámku 30 s (drží ho heartbeat): **nikdy se nepřekryje**, mezitím `skipped_overlapping`, zámek se neztratí,
- `e2e.even-minutes` (`*/2`) – jen sudé minuty, jednou,
- `e2e.failing` – selhání se zaznamená, ostatní úlohy neovlivní,
- `e2e.prod-only` – v prostředí `e2e` se vůbec nenaplánuje,
- po doběhnutí nezůstanou běhové zámky.

Průběžné sledování: `make e2e-up`, `make e2e-logs`, `make e2e-report`, `make e2e-assert`, `make e2e-down`.

### Chyby nalezené testy (opraveno)

- **MySQL deadlock při souběhu** – InnoDB při souběžném `INSERT` (duplicitní klíč) a `DELETE` téhož řádku hlásí deadlock 1213
  i v autocommitu. `PdoLockStore::tryAcquire()` ho teď bere jako „nezískáno“ (příkaz byl vrácen) a `release()`/`refresh()`
  krátce opakují. SQLite smoke test to odhalit nemohl.
- **Dlouhé klíče** – zkrácený klíč měl 215 znaků místo max. 191 (MySQL/PostgreSQL odmítly, SQLite délku nekontroluje).

### Stav ověření

- Jádro, všechna úložiště zámků (MySQL, PostgreSQL, Redis, flock, SQLite) a Laravel bridge (Laravel 12) jsou pokryté testy výše.
- `CrunzScheduler` je stále jen syntakticky ověřený – crunz není nainstalovaný.

## 8. Otevřené body

- Monitoring: výsledky běhů (`TaskRunResult`) zatím jen do logu – případně přidat PSR-14 eventy nebo tabulku historie běhů.
- Timeout úlohy (kill po N sekundách) – Laravel ani crunz ho spolehlivě neřeší; lze doplnit do `NativeScheduler` / runneru přes `pcntl_alarm`.
- Úklid tabulky zámků: `PdoLockStore::purgeExpired()` naplánovat jako vlastní denní úlohu (zámky výskytu se nemažou, jen vyprší).
- Hodiny serverů musí být synchronizované (NTP) – expirace v DB se počítá z času PHP.
