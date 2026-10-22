# RAG Chatbot

A Retrieval Augmented Generation application built with Laravel 13, Qdrant and local models, following the techniques in the *Advanced RAG Field Guide*: each step of basic RAG is replaced by a small, specific technique, and every technique is measured.

## What this project does

**Offline, once per document** (`ProcessDocument` → `DocumentChunker`):

1. **Reading order.** Poppler's `pdftotext -layout` keeps table columns. Two-column pages are read left column first, then right (`ColumnSplitter`).
2. **Running headers dropped.** Text repeated on many pages, such as "iMac | Product Environmental Report 6", is removed (`RunningText`).
3. **Text repair.** Ligatures (`ﬂ` → `fl`), a dropped "Th" (`Te` → `The`) and line-break hyphens (`sustainabil- ity`) are fixed. The document itself is the dictionary that keeps real compounds like `low-carbon` (`TextRepairer`).
4. **Structure from fonts.** `pdftohtml -xml` gives every run's font. Four rules recover the outline (`OutlineDetector`):
   - Body text is the font with the most characters; headings are larger.
   - Headings are short and made of words.
   - Running text is not a heading.
   - Captions are not headings.
5. **Sections with metadata.** Each heading opens a section that knows its heading path, chapter ("Chapter 2") and region ("South Asia") (`StructureBuilder`).
6. **Tables.** Each table becomes one parent section, rebuilt as Markdown so every value sits next to its column heading (`TableParser`). Its search children are windows of rows that repeat the column headers, so a row deep in a long table can still be found.
7. **Footnotes travel with the claim.** Raised markers become `[^7]`, and the endnote text is appended to every chunk that cites it (`FootnoteLinker`).
8. **Vision.** Chart and infographic pages get one vision-model description each, cached on disk (`VisualPageDetector`, `VisionDescriber`). In `auto` mode, documents longer than `RAG_VISION_MAX_DOCUMENT_PAGES` (40) skip vision.
9. **Search small, answer from the parent.** Whole sentences are grouped into ~900-token parent sections. Each parent is cut into overlapping ~280-token children for search (`ChunkBuilder`).
10. **Two vectors per chunk.** Each child is indexed in one Qdrant collection with a dense vector (BAAI bge-small) and a BM25 sparse vector, plus metadata. The heading path is embedded in front of the text; the stored text stays unchanged.

**Online, on every question** (`RagService` → `Retriever`):

1. **Query understanding.** A question that names a region, abbreviation or country is filtered to that region (`QueryRouter`; regex rules, or one LLM JSON call). A chapter filter is set only when the question names a chapter. A filter that matches nothing falls back to unfiltered search.
2. **Hybrid search.** Dense and BM25 search each take 20 hits, merged with Reciprocal Rank Fusion (k = 60).
3. **Rerank.** A MiniLM cross-encoder rereads the question with each of the 20 passages (`CrossEncoderReranker`).
4. **Parents.** The best children's parent sections (top 5) go to the model, numbered `[Source n | document | heading path | page]`. A table with a row the question names (e.g. "Pakistan") keeps the last slot even when the cross-encoder, trained on prose, ranked it lower.
5. **Grounded answer.** `gpt-oss-120b` on Groq cites numbered passages, or replies exactly "I could not find this in the document." An answer is refused when it states a figure the sources lack, unless that figure is a rescaled source figure (1,018,063 thousand → 1.018 billion) or the sum or difference of two figures in the same answer.

## Requirements

- PHP 8.3+, Composer 2, Node.js 20+
- Poppler utilities: `pdftotext`, `pdftohtml`, `pdftoppm` (`apt install poppler-utils`)
- Docker (Qdrant and the two model containers)
- An OpenAI-compatible API key (Groq by default) for answers, vision and optional LLM routing

## Local setup (once)

1. Install Poppler, which reads PDF text, fonts and page images:

   ```bash
   sudo apt install poppler-utils
   ```

2. Install dependencies and create the database:

   ```bash
   composer install
   cp .env.example .env
   php artisan key:generate
   touch database/database.sqlite
   php artisan migrate
   npm install && npm run build
   ```

3. Edit `.env`:
   - `OPENAI_COMPAT_API_KEY`: your Groq key. Chart descriptions need it even when answers come from Ollama.
   - `RAG_CHAT_DRIVER=openai` to answer with Groq (`ollama` answers locally).
   - `RAG_MAX_CONTEXT_CHARS=24000` with Groq; keep 16000 with Ollama's 8k context window.

4. Start Qdrant and the local models. The first start downloads bge-small and MiniLM (about 200 MB):

   ```bash
   docker compose up -d qdrant embeddings reranker
   ```

5. Index the documents (or upload them on the documents page):

   ```bash
   php artisan rag:ingest ~/Downloads/GPO-Anual-report.pdf
   php artisan rag:ingest ~/Downloads/GEP-Jun-2026.pdf
   php artisan rag:ingest ~/Downloads/iMac_PER_Oct2024.pdf
   ```

## Running it (every time)

1. Start the containers: `docker compose up -d qdrant embeddings reranker`
2. Start the app: `php artisan serve`
3. In a second terminal, start the worker that indexes uploads: `php artisan queue:work --tries=2 --timeout=900`
4. Create an account at http://127.0.0.1:8000/register (or log in at `/login`). Every page needs a signed-in user.
5. Upload PDF or TXT files at http://127.0.0.1:8000/documents and wait for status **completed**.
6. Ask questions at http://127.0.0.1:8000/chat. `RAG_TEST_QUESTIONS.md` lists questions with expected answers for the three test documents.

### Documents and users

- **Private documents.** A file you upload is visible to and searchable by you only. You can delete it.
- **Shared documents.** Have no owner and are searchable by every user. Documents added with `php artisan rag:ingest` are shared. Admins can also share an upload by ticking the shared option; anyone else gets a 403. Only admins can delete shared documents.
- **Admins.** Make an account an admin with `php artisan user:admin you@example.com` (`--revoke` to undo).
- Another user's private document or conversation returns 404.

Each user's chats are saved as conversations (`conversations` and `messages` tables, with the answer's sources and quotes). The chat sidebar lists your earlier conversations; open one to see its messages and keep asking in it. Users only ever see their own conversations.

A new chat searches all documents you can see, or only the one document you choose. A conversation keeps that choice for all its follow-ups.

Every message first goes to the chat model together with the recent conversation (the last `RAG_HISTORY_MESSAGES` messages, 6 by default), which decides what it is in one short JSON call:

- **Small talk** ("hi", "yes please", "thanks!", "what's my name?") is answered by the chat model from the conversation, without a document search or sources. If that reply states a figure the conversation never mentioned, the model answered a factual question from its own knowledge, so the message is searched as a document question instead.
- **Document questions** are rewritten to stand on their own when they are follow-ups ("what about 2024?" becomes "What was GPO revenue in 2024?"), then searched and answered as usual.

If the model's decision can't be read, the message is searched as a document question.

After editing `.env`, restart the queue worker. If you ever ran `php artisan config:cache`, also run `php artisan config:clear`.

After changing the embedding model, chunk sizes, vision settings or the collection name, rebuild the index:

```bash
php artisan rag:reindex
```

## Testing

| What | Command | Cost |
| --- | --- | --- |
| Unit and feature tests | `php artisan test` | No network calls: HTTP, processes and storage are faked |
| Retrieval quality (MRR) | `php artisan rag:eval --retrieval-only` | Seconds; no Groq tokens |
| Retrieval and answers | `php artisan rag:eval` | About 200,000 Groq tokens (a full free-tier day) |
| One document or pipeline | `php artisan rag:eval --set=gpo-2018-annual-report --pipeline=advanced` | A fraction of the above |
| Manual check | Ask the questions in `RAG_TEST_QUESTIONS.md` | About 6,000 tokens per question |

## Artisan commands

| Command | What it does |
| --- | --- |
| `php artisan rag:ingest path/to/file.pdf` | Index a local file now as a shared document, without the queue (`--replace` re-indexes it) |
| `php artisan rag:reindex` | Re-index every document after changing the embedding model, chunking or collection (`--queue` to queue instead) |
| `php artisan rag:eval` | Score both pipelines on the question sets in `evals/` |
| `php artisan rag:eval --retrieval-only` | MRR only, no LLM calls (seconds instead of minutes) |
| `php artisan user:admin you@example.com` | Let a user upload and delete shared documents (`--revoke` removes it) |

## Evaluation

`evals/*.json` holds hand-checked questions for three test documents:

- the GPO 2018 Annual Report (plain two-column text)
- the World Bank *Global Economic Prospects*, June 2026 (200 pages, six regions)
- the Apple iMac Product Environmental Report (tables, a chart, endnotes)

Each question names an evidence phrase that marks the answer passage and the facts a correct answer must contain. Out-of-scope questions, such as FY 2020 in an FY 2018 report, are correct only when refused.

`rag:eval` reports two numbers per document and pipeline (`naive` = dense search over the same index; `advanced` = the full pipeline):

- **MRR** (mean reciprocal rank): 1.0 means the answer passage always came first.
- **Answers correct**, counted per question.

With 10 to 14 questions per document, treat differences under about 0.1 as noise. A JSON report is saved under `storage/app/private/eval-reports/`.

Measure on your own documents before changing defaults. For example, the cross-encoder was trained on prose and can under-rate table chunks. Try `RAG_RERANKER=lexical` or `none` and compare.

## Configuration

All settings live in `config/rag.php` and can be set in `.env`:

| Setting | Default | Notes |
| --- | --- | --- |
| `RAG_EMBEDDING_DRIVER` | `tei` | `tei` (bge-small, 384 dims) or `ollama` (set `QDRANT_VECTOR_SIZE` to match) |
| `QDRANT_COLLECTION` | `rag_hybrid` | A new name is needed when the vector size changes |
| `RAG_CHILD_CHUNK_CHARS` / `RAG_PARENT_CHUNK_CHARS` | 1100 / 3600 | About 280 / 900 tokens |
| `RAG_CANDIDATE_POOL` | 20 | Hits per search list, and passages the reranker rereads |
| `RAG_RRF_K` | 60 | 60 = original paper, 2 = Qdrant's default |
| `RAG_RERANKER` | `cross_encoder` | or `lexical`, `none` |
| `RAG_ROUTER` | `rules` | or `llm` (one JSON call per question), `none` |
| `RAG_TOP_K` | 5 | Parent sections handed to the model |
| `RAG_VISION_PAGES` | `auto` | `auto` = chart-like pages of documents up to `RAG_VISION_MAX_DOCUMENT_PAGES`; or `all`, `none` |
| `RAG_VISION_MODEL` | `qwen/qwen3.8-27b` | Any vision model on the OpenAI-compatible API |
| `RAG_VISION_BUDGET_SECONDS` | 300 | Vision time per document; pages past it are skipped, logged and picked up by the next re-index (descriptions are cached) |
| `OPENAI_COMPAT_MAX_WAIT` | 15 | Seconds to wait out a rate limit during a chat request |
| `OPENAI_COMPAT_FALLBACK_MODELS` | empty | Comma-separated models tried in order when the one before is rate limited (`.env.example`: `openai/gpt-oss-20b,qwen/qwen3.8-27b`) |
| `RAG_MAX_CONTEXT_CHARS` | 16000 | Context sent to the model; 24000 suits Groq |
| `RAG_HISTORY_MESSAGES` | 6 | Earlier messages used to understand follow-ups and small talk |

Regions and their aliases (abbreviations, member countries) are listed in `config/rag.php` under `regions`.

### Free-tier rate limits

Groq's free tier limits `gpt-oss-120b` to about 8,000 tokens per minute and 200,000 tokens per day. One answer from five parent sections uses about 6,000 tokens, so expect roughly one answer per minute and about 30 per day.

- When a model's limit is hit, the next model in `OPENAI_COMPAT_FALLBACK_MODELS` answers instead (Groq limits each model separately).
- When every model is limited, the request fails. With `APP_DEBUG=true` the chat shows "rate limit reached" with the provider's message, which says when it resets; otherwise it shows "RAG request failed." and the details go to the log.
- `rag:eval` waits out per-minute limits, but not the daily one.
- For more testing, upgrade the Groq tier, or set `RAG_TOP_K=3` to roughly halve the tokens per question.

## Code map

```text
app/Services/Rag/
├── Indexing/      PdfLayoutReader, RunningText, TextRepairer, OutlineDetector, FootnoteLinker,
│                  VisualPageDetector, VisionDescriber, StructureBuilder, ChunkBuilder, DocumentChunker
├── Retrieval/     Embedder (TeiEmbedder, OllamaEmbedder), Bm25Encoder, QueryRouter,
│                  CrossEncoderReranker, Retriever, Passage, RetrievalResult
├── Evaluation/    EvalSet, EvalQuestion, Scorer
├── DocumentParser, ColumnSplitter, TableParser, QdrantService, Reranker (lexical)
├── Conversation/  ChatAssistant, MessageRouter, RoutedMessage, ChatHistory
├── RagService, AnswerPrompt, QuoteExtractor
└── ChatModel (OllamaService, OpenAiCompatibleChatService), OpenAiCompatibleClient, RateLimitException
```

## Not built yet

- Query transformations: multi-query, HyDE, question decomposition
- Agentic or corrective RAG that grades retrieval and searches again
- GraphRAG for questions across many documents
- LLM-as-judge answer evaluation
- OCR for scanned PDFs
