<?php

// Standalone opt-in test: php tests/integration/answer-concurrency.php
// Only creates/drops a randomly named disposable database on local MySQL.
use App\Domain\Calls\Models\CallAttempt;
use App\Domain\Calls\Models\CallAttemptOperatorAttempt;
use App\Domain\Calls\Models\CallParticipant;
use App\Domain\Calls\Models\CallSession;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Shared\Enums\CallStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use App\Support\Calls\CallRoutingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$worker = ($argv[1] ?? '') === 'worker';
$database = $worker ? ($argv[2] ?? '') : 'hotline_answer_'.bin2hex(random_bytes(8)).'_test';
if (! preg_match('/\Ahotline_answer_[a-f0-9]{16}_test\z/', $database)) {
    throw new RuntimeException('Refusing to use a non-disposable database.');
}
foreach ([
    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306', 'DB_DATABASE' => $database, 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '',
    'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null', 'BCRYPT_ROUNDS' => '4',
] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$check = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$check(config('database.default') === 'mysql'
    && config('database.connections.mysql.database') === $database
    && config('database.connections.mysql.host') === '127.0.0.1', 'Refusing cached or non-disposable database configuration.');
if ($worker) {
    $operator = User::query()->findOrFail((int) $argv[3]);
    $mode = $argv[6] ?? 'incoming';
    if (in_array($mode, ['directed', 'new', 'reconnect', 'route-answer'], true)) {
        $incident = Incident::findOrFail((int) $argv[4]);
        $citizen = User::findOrFail($incident->citizen_id);
        if ($mode === 'new') {
            // Force a stale green preflight: the transactional reservation remains authoritative.
            app()->instance(\App\Support\Sessions\AvailabilityService::class, new class($operator->id) extends \App\Support\Sessions\AvailabilityService {
                public function __construct(private int $operatorId) {}
                public function callerAvailability(): array { return ['status'=>'green']; }
                public function operatorRuntimeState(?\App\Domain\Users\Models\User $user): string { return $user?->id === $this->operatorId ? 'available' : 'offline'; }
            });
        }
        file_put_contents($argv[5], 'ready');
        try {
            $routing = app(CallRoutingService::class);
            if ($mode === 'directed') $routing->startDirectedAttempt($operator, $citizen);
            elseif ($mode === 'new') $routing->startNewAttempt($citizen);
            elseif ($mode === 'reconnect') $routing->startReconnectAttempt($operator, $citizen, $incident);
            else $routing->answerNewAttempt($operator, CallAttemptOperatorAttempt::where('operator_id', $operator->id)->where('status', CallStatus::Calling)->firstOrFail());
            echo json_encode(['status'=>$mode === 'route-answer' ? 'answered' : 'created']);
        } catch (RuntimeException $error) {
            if (!in_array($error->getMessage(), ['A participant already has an active or pending call.', 'This call attempt is no longer answerable.', 'Reconnect is already in progress for this incident.'], true)) throw $error;
            echo json_encode(['status'=>'conflict']);
        }
        exit;
    }
    if ($mode !== 'incoming') {
        $request = \Illuminate\Http\Request::create('/', 'POST');
        $request->setUserResolver(fn () => $operator);
        $controller = app(\App\Http\Controllers\Api\Operator\CallbackCallAttemptController::class);
        // The disposable schema tests state transitions, not workbench serialization.
        app()->instance(\App\Support\Incidents\IncidentPayloadBuilder::class, new class extends \App\Support\Incidents\IncidentPayloadBuilder {
            public function __construct() {}
            public function buildWorkbenchPayload(Incident $incident, ?\App\Domain\Users\Models\User $viewer = null, bool $includeLegacyAliases = true): array { return $incident->toArray(); }
        });
        $context = $mode === 'create' ? Incident::findOrFail((int) $argv[4]) : CallAttempt::findOrFail((int) $argv[4]);
        file_put_contents($argv[5], 'ready');
        try {
            $controller->{$mode === 'create' ? 'store' : $mode}($request, $context);
            echo json_encode(['status' => ['create'=>'created','answer'=>'answered','cancel'=>'cancelled','status'=>'reconciled'][$mode]]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            if ($error->getStatusCode() !== 409) throw $error;
            echo json_encode(['status'=>'conflict']);
        }
        exit;
    }
    $route = CallAttemptOperatorAttempt::query()->with('callAttempt')->findOrFail((int) $argv[4]);
    file_put_contents($argv[5], 'ready');
    try {
        $result = app(CallRoutingService::class)->answerNewAttempt($operator, $route);
        echo json_encode(['status' => 'answered', 'incident' => $result['incident']->id]);
    } catch (RuntimeException $error) {
        if (!in_array($error->getMessage(), ['This call attempt is no longer answerable.', 'A participant already has an active or pending call.'], true)) {
            throw $error;
        }
        echo json_encode(['status' => 'conflict']);
    }
    exit;
}
$admin = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$created = false;
$failure = null;
$processes = [];
$markers = [];
try {
    $admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    // Use the application's relevant schema migrations. The unrelated default-resource
    // migration currently has an overlong MySQL index name; do not alter it here.
    foreach ([
        '0001_01_01_000000_create_users_table.php',
        '2026_04_04_000001_create_settings_table.php',
        '2026_04_04_000009_create_incidents_table.php',
        '2026_04_04_000010_create_call_attempts_table.php',
        '2026_04_04_000011_create_call_attempt_operator_attempts_table.php',
        '2026_04_04_000012_create_call_sessions_table.php',
        '2026_04_04_000013_create_call_participants_table.php',
        '2026_04_18_020000_add_fractional_precision_to_call_session_timestamps.php',
        '2026_04_28_000001_add_live_location_fields_to_incidents_table.php',
        '2026_04_28_000002_create_incident_caller_locations_table.php',
        '2026_05_10_000001_add_citizen_id_columns_for_caller_compatibility.php',
        '2026_05_11_000001_add_citizen_detail_columns_to_incidents_table.php',
        '2026_05_11_000004_drop_caller_storage_columns.php',
        '2026_10_06_000001_add_callback_to_call_attempts.php',
    ] as $migration) {
        if ($migration === '2026_05_11_000004_drop_caller_storage_columns.php') {
            // MySQL needs a separate FK-supporting index before that migration
            // drops its composite caller index. This fixture-only index disappears
            // with caller_id; the application migrations remain untouched.
            DB::statement('CREATE INDEX test_caller_fk ON call_attempts (caller_id)');
        }
        (require dirname(__DIR__, 2).'/database/migrations/'.$migration)->up();
    }
    echo 'Database engine: '.$admin->query('SELECT VERSION()')->fetchColumn().PHP_EOL;
    foreach ([false, true, 'separate'] as $differentOperators) {
        $caller = User::factory()->create(['role' => UserRole::Citizen]);
        $operator = User::factory()->create(['role' => UserRole::Operator]);
        $attempt = CallAttempt::query()->create(['citizen_id' => $caller->id, 'status' => CallStatus::Calling, 'started_at' => now()]);
        $route = $attempt->operatorAttempts()->create(['operator_id' => $operator->id, 'status' => CallStatus::Calling, 'started_at' => now(), 'created_at' => now()]);
        $other = $differentOperators === true ? User::factory()->create(['role' => UserRole::Operator]) : $operator;
        $otherRoute = $differentOperators === true ? $attempt->operatorAttempts()->create(['operator_id' => $other->id, 'status' => CallStatus::Calling, 'started_at' => now(), 'created_at' => now()]) : $route;
        if ($differentOperators === 'separate') {
            $otherAttempt = CallAttempt::create(['citizen_id'=>$caller->id,'status'=>CallStatus::Calling,'started_at'=>now()]);
            $otherRoute = $otherAttempt->operatorAttempts()->create(['operator_id'=>$operator->id,'status'=>CallStatus::Calling,'started_at'=>now(),'created_at'=>now()]);
        }
        $beforeIncidents = Incident::query()->count();
        $beforeSessions = CallSession::query()->count();
        DB::beginTransaction();
        User::query()->whereKey($caller->id)->lockForUpdate()->firstOrFail();
        $processes = [];
        $markers = [];
        foreach ([[$operator, $route], [$other, $otherRoute]] as [$user, $context]) {
            $marker = tempnam(sys_get_temp_dir(), 'hotline-answer-');
            $markers[] = $marker;
            $process = proc_open([PHP_BINARY, __FILE__, 'worker', $database, (string) $user->id, (string) $context->id, $marker], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
            $check(is_resource($process), 'Cannot start independent answer worker.');
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }
        $deadline = microtime(true) + 15;
        while (array_filter($markers, static fn ($marker) => file_get_contents($marker) !== 'ready')) {
            $check(microtime(true) < $deadline, 'Workers failed to preload their answer contexts.');
            usleep(10000);
        }
        // Both workers enter answer transactions while the shared participant remains locked.
        usleep(200000);
        foreach ($processes as [$process]) {
            $check(proc_get_status($process)['running'], 'Worker must still be waiting on the shared participant lock.');
        }
        DB::commit();
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            $check($exit === 0 || $exit === -1, 'Answer worker failed: '.$stderr);
            $results[] = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        }
        $processes = [];
        foreach ($markers as $marker) {
            unlink($marker);
        }
        $markers = [];
        $statuses = array_column($results, 'status');
        sort($statuses);
        $check($statuses === ['answered', 'conflict'], 'Exactly one answer and one conflict required.');
        $check(Incident::query()->count() === $beforeIncidents + 1 && CallSession::query()->count() === $beforeSessions + 1, 'Duplicate incident/session created.');
        $session = CallSession::query()->where('citizen_id', $caller->id)->sole();
        $winner = $differentOperators === 'separate' ? $operator->id : $attempt->fresh()->answered_by_operator_id;
        $participants = CallParticipant::query()->where('call_session_id', $session->id)->get();
        $check($participants->count() === 2 && $participants->contains(fn ($p) => $p->user_id === $caller->id && $p->participant_role === 'citizen') && $participants->contains(fn ($p) => $p->user_id === $winner && $p->participant_role === 'operator'), 'Incorrect participants.');
        echo 'PASS concurrent answers ('.($differentOperators === 'separate' ? 'different attempts, same citizen/operator' : ($differentOperators ? 'different operator routes' : 'same route')).'): one incident/session, correct participants, safe conflict.'.PHP_EOL;
    }
    foreach ([['create','create'], ['answer','answer'], ['answer','cancel'], ['create','directed'], ['create','new'], ['create','reconnect'], ['answer','status'], ['answer','route-answer']] as $modes) {
        $citizen = User::factory()->create(['role'=>UserRole::Citizen]);
        $operator = User::factory()->create(['role'=>UserRole::Operator]);
        $incident = Incident::create(['citizen_id'=>$citizen->id,'operator_id'=>$operator->id,
            'status'=>\App\Domain\Shared\Enums\IncidentStatus::Deferred,
            'alert_level'=>\App\Domain\Shared\Enums\AlertLevel::Normal,'called_at'=>now()]);
        $contextId = $incident->id;
        if ($modes[0] !== 'create') {
            $contextId = CallAttempt::create(['incident_id'=>$incident->id,'citizen_id'=>$citizen->id,
                'callback'=>true,'status'=>CallStatus::Calling,'started_at'=>$modes[1] === 'status' ? now()->subSeconds(61) : now()])->id;
        }
        if ($modes[1] === 'route-answer') {
            $legacy = CallAttempt::create(['citizen_id'=>$citizen->id,'status'=>CallStatus::Calling,'started_at'=>now()]);
            $legacy->operatorAttempts()->create(['operator_id'=>$operator->id,'status'=>CallStatus::Calling,'started_at'=>now(),'created_at'=>now()]);
        }
        DB::beginTransaction();
        Incident::whereKey($incident->id)->lockForUpdate()->firstOrFail();
        $processes = [];
        $markers = [];
        foreach ($modes as $mode) {
            $marker = tempnam(sys_get_temp_dir(), 'hotline-callback-');
            $markers[] = $marker;
            $process = proc_open([PHP_BINARY,__FILE__,'worker',$database,(string)$operator->id,(string)(in_array($mode, ['directed','new','reconnect','route-answer'],true) ? $incident->id : $contextId),$marker,$mode],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__,2));
            $check(is_resource($process),'Cannot start callback worker.');
            fclose($pipes[0]);
            $processes[] = [$process,$pipes];
        }
        $deadline = microtime(true)+15;
        while (array_filter($markers,static fn ($marker)=>file_get_contents($marker)!=='ready')) {
            $check(microtime(true)<$deadline,'Callback workers did not preload contexts.');
            usleep(10000);
        }
        usleep(200000);
        foreach ($processes as [$process]) $check(proc_get_status($process)['running'],'Callback worker must wait on incident lock.');
        DB::commit();
        $statuses = [];
        foreach ($processes as [$process,$pipes]) {
            $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $exit = proc_close($process);
            $check($exit===0 || $exit===-1,'Callback worker failed: '.$stderr);
            $statuses[] = json_decode($stdout,true,flags:JSON_THROW_ON_ERROR)['status'];
        }
        $processes = [];
        foreach ($markers as $marker) unlink($marker);
        $markers = [];
        sort($statuses);
        $attempt = CallAttempt::where('citizen_id',$citizen->id)->when($modes[1]==='route-answer',fn($query)=>$query->where('callback',true))->sole();
        $sessions = CallSession::where('incident_id',$incident->id)->count();
        if ($modes[0]==='create') $check($statuses===['conflict','created'] && $sessions===0,'Concurrent callback creates must make one attempt.');
        elseif ($modes[1]==='status') $check($statuses===['conflict','reconciled'] && $sessions===0 && $attempt->outcome->value==='timed_out','Expiry and late answer must commit one timeout without a session.');
        elseif ($modes[1]==='route-answer') $check($statuses===['conflict','conflict'] && $sessions===0,'Legacy conflicting reservations must not both answer.');
        elseif ($modes[1]==='answer') $check($statuses===['answered','conflict'] && $sessions===1,'Concurrent callback answers must make one session.');
        else $check(($statuses===['answered','cancelled'] && $sessions===1 && $attempt->outcome->value==='answered')
            || ($statuses===['cancelled','conflict'] && $sessions===0 && $attempt->outcome->value==='cancelled_by_operator'), 'Answer/cancel must have one consistent winner.');
        $check($incident->fresh()->status===\App\Domain\Shared\Enums\IncidentStatus::Deferred,'Callback race changed incident disposition.');
        echo 'PASS callback race '.implode('/', $modes).': serialized state, no duplicate session, unchanged incident.'.PHP_EOL;
    }
} catch (Throwable $error) {
    $failure = $error;
} finally {
    if (DB::transactionLevel()) {
        DB::rollBack();
    }
    foreach ($processes as [$process, $pipes]) {
        proc_terminate($process);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);
    }
    foreach ($markers as $marker) {
        if (is_file($marker)) {
            unlink($marker);
        }
    }
    DB::disconnect();
    if ($created) {
        $admin->exec("DROP DATABASE `{$database}`");
        echo 'Disposable database removed.'.PHP_EOL;
    }
}
if ($failure) {
    fwrite(STDERR, 'FAIL: '.$failure->getMessage().PHP_EOL);
    exit(1);
}
