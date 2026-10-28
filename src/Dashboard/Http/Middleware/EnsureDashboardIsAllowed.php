<?php

namespace Zatca\Dashboard\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Zatca\Enums\ZatcaEnvironment;
use Zatca\State\EgsRegistry;

/**
 * The dashboard has no authentication, so it is a local/testing tool only: it answers 404
 * unless explicitly enabled, and 403 whenever the app or any configured EGS is in production.
 */
class EnsureDashboardIsAllowed
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('zatca.dashboard.enabled'), 404);

        abort_if(
            app()->environment('production') || $this->anyEgsInProduction(),
            403,
            'The ZATCA dashboard has no authentication and is disabled for production. Use sandbox or simulation on a non-production app.'
        );

        return $next($request);
    }

    private function anyEgsInProduction(): bool
    {
        $registry = app(EgsRegistry::class);

        foreach ($registry->identifiers() as $egs) {
            if ($registry->for($egs)->environment === ZatcaEnvironment::Production) {
                return true;
            }
        }

        return false;
    }
}
