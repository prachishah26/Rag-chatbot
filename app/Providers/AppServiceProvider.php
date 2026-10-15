<?php

namespace App\Providers;

use App\Services\Rag\ChatModel;
use App\Services\Rag\OllamaService;
use App\Services\Rag\OpenAiCompatibleChatService;
use App\Services\Rag\Retrieval\Embedder;
use App\Services\Rag\Retrieval\OllamaEmbedder;
use App\Services\Rag\Retrieval\TeiEmbedder;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ChatModel::class, fn ($app) => match (config('rag.chat.driver')) {
            'openai' => $app->make(OpenAiCompatibleChatService::class),
            default => $app->make(OllamaService::class),
        });

        $this->app->bind(Embedder::class, fn ($app) => match (config('rag.embeddings.driver')) {
            'ollama' => $app->make(OllamaEmbedder::class),
            default => $app->make(TeiEmbedder::class),
        });
    }

    public function boot(): void
    {
        //
    }
}
