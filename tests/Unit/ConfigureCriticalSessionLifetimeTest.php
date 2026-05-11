<?php

namespace Tests\Unit;

use App\Http\Middleware\ConfigureCriticalSessionLifetime;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class ConfigureCriticalSessionLifetimeTest extends TestCase
{
    public function test_realtime_admission_routes_use_critical_session_lifetime(): void
    {
        config([
            'session.lifetime' => 15,
            'session.critical_lifetime' => 43200,
        ]);

        $request = Request::create('/api/realtime/admission/citizen', 'POST');

        (new ConfigureCriticalSessionLifetime())->handle(
            $request,
            fn () => new Response('ok'),
        );

        $this->assertSame(43200, (int) config('session.lifetime'));
    }
}
