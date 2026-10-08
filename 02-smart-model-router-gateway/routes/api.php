<?php

use App\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas de API
|--------------------------------------------------------------------------
|
| Registradas em bootstrap/app.php. O Laravel aplica o prefixo "/api" e o
| grupo de middleware "api" (sem sessão e sem CSRF, ao contrário de web.php).
|
*/

Route::post('/chat', ChatController::class)->name('chat');
