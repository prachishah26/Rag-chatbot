<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('rag:health', function () {
    $this->info('RAG Chatbot application is running.');
})->purpose('Check that the RAG application is available');
