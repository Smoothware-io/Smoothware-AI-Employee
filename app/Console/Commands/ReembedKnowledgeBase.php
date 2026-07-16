<?php

namespace App\Console\Commands;

use App\Jobs\EmbedKnowledgeEntry;
use App\Models\KnowledgeEntry;
use App\Services\KnowledgeRetriever;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-embeds the knowledge base with the currently configured provider.
 *
 * Required whenever EMBEDDINGS_DRIVER, VOYAGE_MODEL or VOYAGE_DIMENSIONS
 * changes: vectors from different models share no geometry, so old chunks become
 * invisible to retrieval (the retriever refuses to score them — deliberately).
 * Until this has run, part of the KB is dark.
 */
class ReembedKnowledgeBase extends Command
{
    protected $signature = 'kb:reembed
                            {--status : Show embedding coverage and exit}
                            {--sync : Run inline instead of queueing (no worker needed)}';

    protected $description = 'Re-embed published knowledge entries with the active embeddings provider';

    public function handle(KnowledgeRetriever $retriever): int
    {
        $active = $retriever->activeModel();

        $this->components->info("Active embeddings model: {$active}");
        $this->table(
            ['Embedding model', 'Chunks', 'Retrievable?'],
            $this->coverage($active),
        );

        $stale = $retriever->staleChunkCount();

        if ($this->option('status')) {
            if ($stale > 0) {
                $this->components->warn("{$stale} published chunk(s) are invisible to retrieval. Run: php artisan kb:reembed");
            } else {
                $this->components->info('All published chunks are retrievable.');
            }

            return self::SUCCESS;
        }

        $entries = KnowledgeEntry::query()->published()->get();

        if ($entries->isEmpty()) {
            $this->components->warn('No published knowledge entries to embed.');

            return self::SUCCESS;
        }

        $sync = (bool) $this->option('sync');

        $this->components->info(sprintf(
            'Re-embedding %d published entr%s %s…',
            $entries->count(),
            $entries->count() === 1 ? 'y' : 'ies',
            $sync ? 'inline' : 'via the queue',
        ));

        $bar = $this->output->createProgressBar($entries->count());
        $bar->start();

        foreach ($entries as $entry) {
            $sync
                ? EmbedKnowledgeEntry::dispatchSync($entry->getKey())
                : EmbedKnowledgeEntry::dispatch($entry->getKey());

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if (! $sync) {
            $this->components->warn('Queued. Embeddings are NOT live until a worker drains them: php artisan queue:work');

            return self::SUCCESS;
        }

        $remaining = $retriever->staleChunkCount();

        $remaining > 0
            ? $this->components->error("{$remaining} chunk(s) still stale — check the logs.")
            : $this->components->info('Done. Every published chunk is retrievable.');

        return $remaining > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<int, array<int, string|int>> */
    private function coverage(string $active): array
    {
        return DB::table('knowledge_chunks')
            ->select('embedding_model', DB::raw('count(*) as total'))
            ->whereNotNull('embedding')
            ->groupBy('embedding_model')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row): array => [
                $row->embedding_model ?? '(none)',
                $row->total,
                $row->embedding_model === $active ? 'yes' : 'NO — stale',
            ])
            ->all();
    }
}
