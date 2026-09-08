<?php

use App\Livewire\PublicChat;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Демонстраційний публічний чат (без токена, для показу замовнику) — FR-014:
// той самий RAG-конвеєр, що й API, лише інший канал доступу. Rate limit по
// IP, щоб сторінку не можна було зловживати без обмежень.
Route::get('/chat', PublicChat::class)
    ->middleware('throttle:'.config('rag.rate_limit_per_minute').',1')
    ->name('chat');
