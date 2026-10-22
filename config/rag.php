<?php

return [
    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
        'chat_model' => env('OLLAMA_CHAT_MODEL', 'llama3.2:3b'),
        'embedding_model' => env('OLLAMA_EMBEDDING_MODEL', 'nomic-embed-text'),
        'timeout' => (int) env('OLLAMA_TIMEOUT', 120),
        // Ollama silently drops the start of prompts longer than this.
        'num_ctx' => (int) env('OLLAMA_NUM_CTX', 8192),
    ],

    // Which model writes answers: "ollama" (local) or "openai" (any OpenAI-compatible API).
    'chat' => [
        'driver' => env('RAG_CHAT_DRIVER', 'ollama'),
        // Earlier messages of a conversation used to understand follow-ups and small talk (0 = none).
        'history_messages' => (int) env('RAG_HISTORY_MESSAGES', 6),
    ],

    'openai_compat' => [
        'base_url' => env('OPENAI_COMPAT_BASE_URL', 'https://api.groq.com/openai/v1'),
        'api_key' => env('OPENAI_COMPAT_API_KEY'),
        'model' => env('OPENAI_COMPAT_MODEL', 'openai/gpt-oss-120b'),
        // Comma-separated models tried in order when the one before is rate limited.
        'fallback_models' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('OPENAI_COMPAT_FALLBACK_MODELS', ''))
        ))),
        'timeout' => (int) env('OPENAI_COMPAT_TIMEOUT', 60),
        // Seconds to wait in total for a rate limit to clear before giving up.
        'max_wait' => (int) env('OPENAI_COMPAT_MAX_WAIT', 15),
    ],

    // Dense embeddings: "tei" (BAAI bge-small in the embeddings container) or "ollama".
    'embeddings' => [
        'driver' => env('RAG_EMBEDDING_DRIVER', 'tei'),
        'tei_url' => env('TEI_EMBEDDINGS_URL', 'http://127.0.0.1:8081'),
        'batch_size' => (int) env('RAG_EMBEDDING_BATCH', 16),
        'timeout' => (int) env('TEI_TIMEOUT', 120),
    ],

    'qdrant' => [
        'url' => env('QDRANT_URL', 'http://127.0.0.1:6333'),
        'api_key' => env('QDRANT_API_KEY'),
        // One collection, two vectors per chunk: "dense" (meaning) and "bm25" (exact words).
        'collection' => env('QDRANT_COLLECTION', 'rag_hybrid'),
        'vector_size' => (int) env('QDRANT_VECTOR_SIZE', 384),
        'distance' => env('QDRANT_DISTANCE', 'Cosine'),
    ],

    // Poppler's pdftotext (keeps table layout); falls back to smalot/pdfparser when missing.
    'pdftotext_binary' => env('PDFTOTEXT_BINARY', 'pdftotext'),
    // Poppler's pdftohtml -xml gives font names and sizes, used to recover headings.
    'pdftohtml_binary' => env('PDFTOHTML_BINARY', 'pdftohtml'),
    'pdftoppm_binary' => env('PDFTOPPM_BINARY', 'pdftoppm'),

    // Sizes are in characters (about 4 characters per token).
    'chunking' => [
        // Children are searched; ~280 tokens.
        'child_size' => (int) env('RAG_CHILD_CHUNK_CHARS', 1100),
        // Sentences repeated at the start of the next child.
        'overlap_sentences' => (int) env('RAG_CHUNK_OVERLAP_SENTENCES', 1),
        // Parents are what the model reads; ~900 tokens.
        'parent_size' => (int) env('RAG_PARENT_CHUNK_CHARS', 3600),
    ],

    'retrieval' => [
        // Parent sections handed to the model.
        'top_k' => (int) env('RAG_TOP_K', 5),
        // Hits fetched from each of dense and BM25 search, then fused.
        'candidate_pool' => (int) env('RAG_CANDIDATE_POOL', 20),
        // Reciprocal Rank Fusion constant: 60 in the original paper, 2 in Qdrant's default.
        'rrf_k' => (int) env('RAG_RRF_K', 60),
        // Keeps retrieved context (plus prompt) inside the chat model's window.
        'max_context_chars' => (int) env('RAG_MAX_CONTEXT_CHARS', 16000),
        // Reranking of fused hits: "cross_encoder" (MiniLM container), "lexical" or "none".
        'reranker' => env('RAG_RERANKER', 'cross_encoder'),
        'reranker_url' => env('TEI_RERANKER_URL', 'http://127.0.0.1:8082'),
        // Used by the lexical reranker only.
        'lexical_weight' => (float) env('RAG_LEXICAL_WEIGHT', 0.3),
    ],

    // Turns a question into a region or chapter filter: "rules" (regex), "llm" (one JSON call) or "none".
    'routing' => [
        'driver' => env('RAG_ROUTER', 'rules'),
    ],

    // Region names found in headings, with the aliases a question or passage may use instead.
    // A country listed under a region routes questions about it to that region's sections.
    'regions' => [
        'East Asia and Pacific' => ['EAP', 'East Asia', 'China', 'Indonesia', 'Philippines', 'Thailand', 'Vietnam', 'Malaysia'],
        'Europe and Central Asia' => ['ECA', 'Europe and Central Asia', 'Türkiye', 'Turkey', 'Russia', 'Poland', 'Ukraine', 'Kazakhstan'],
        'Latin America and the Caribbean' => ['LAC', 'Latin America', 'Caribbean', 'Brazil', 'Mexico', 'Argentina', 'Colombia', 'Chile'],
        'Middle East, North Africa, Afghanistan, and Pakistan' => ['MNA', 'MENAAP', 'Middle East', 'North Africa', 'Pakistan', 'Afghanistan', 'Egypt', 'Iran', 'Saudi Arabia'],
        'South Asia' => ['SAR', 'South Asia', 'India', 'Bangladesh', 'Sri Lanka', 'Nepal', 'Bhutan', 'Maldives'],
        'Sub-Saharan Africa' => ['SSA', 'Sub-Saharan Africa', 'Nigeria', 'South Africa', 'Kenya', 'Ethiopia', 'Angola'],
    ],

    // Pages whose meaning is in a chart or picture get one vision-model description each, cached.
    'vision' => [
        // "auto" (pages that look like charts), "all", or "none".
        'pages' => env('RAG_VISION_PAGES', 'auto'),
        'model' => env('RAG_VISION_MODEL', 'qwen/qwen3.8-27b'),
        // Documents longer than this skip vision in "auto" mode.
        'max_document_pages' => (int) env('RAG_VISION_MAX_DOCUMENT_PAGES', 40),
        'dpi' => (int) env('RAG_VISION_DPI', 110),
        // Total seconds of vision calls per document, so indexing stays inside the job's 900-second timeout.
        'budget_seconds' => (int) env('RAG_VISION_BUDGET_SECONDS', 300),
    ],

    // Hand-checked question sets used by `php artisan rag:eval`.
    'eval_path' => env('RAG_EVAL_PATH', base_path('evals')),
];
