<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('documents.index'));

Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
// Indexing calls the embedding and vision models; answers call the chat model.
Route::post('/documents', [DocumentController::class, 'store'])->middleware('throttle:10,1')->name('documents.store');
Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');

Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
Route::post('/chat/ask', [ChatController::class, 'ask'])->middleware('throttle:20,1')->name('chat.ask');
