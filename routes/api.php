<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| ONLY approved external-integration endpoints belong here — never internal
| HMS application functionality. All internal HMS routes live in
| routes/web.php and are served through Inertia (see docs/api-standards.md
| for the full routing policy and the rationale for this boundary).
|
| No external integration (NHIF or otherwise) has been built yet, so the
| only route currently registered is a public liveness check for uptime
| monitoring — it isn't application functionality, so it's the one
| deliberate exception to "nothing lives here but integrations."
|
*/

Route::get('health', HealthController::class)->name('health');
