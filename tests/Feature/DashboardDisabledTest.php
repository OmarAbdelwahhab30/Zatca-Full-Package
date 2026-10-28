<?php

namespace Zatca\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Zatca\Tests\TestCase;

class DashboardDisabledTest extends TestCase
{
    public function test_dashboard_is_not_registered_by_default(): void
    {
        $this->assertFalse((bool) config('zatca.dashboard.enabled'));
        $this->assertFalse(Route::has('zatca.dashboard.overview'));
        $this->get('/zatca-dashboard')->assertNotFound();
    }
}
