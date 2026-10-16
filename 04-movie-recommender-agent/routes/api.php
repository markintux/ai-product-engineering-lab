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
| auth:sanctum exige o header "Authorization: Bearer <token>". É o token
| que diz quem é o usuário e, portanto, de quem é a memória. Sem token
| válido, a resposta é 401 e o grafo nem roda.
|
*/

Route::post('/chat', ChatController::class)->middleware('auth:sanctum')->name('chat');
