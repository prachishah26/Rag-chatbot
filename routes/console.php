<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('rag:health', function () {
    $this->info('Laravel RAG application is running.');
})->purpose('Check that the RAG application is available');
