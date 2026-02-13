<?php

namespace App\Console\Commands;

use App\Jobs\ExtractPdfBody;
use App\Models\Article;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class ArticlesReextractDocuments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'articles:reextract-documents {--city=} {--limit=} {--failed-only}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Queue document extraction for document-like articles';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $query = $this->documentArticlesQuery();
        $limit = $this->option('limit');

        $queued = 0;
        $skipped = 0;

        $dispatchBatch = function ($articles) use (&$queued, &$skipped): void {
            foreach ($articles as $article) {
                $article->loadMissing('sources');

                $documentUrl = $article->primarySourceUrl() ?? $article->canonical_url;

                if (! is_string($documentUrl) || trim($documentUrl) === '') {
                    $skipped++;

                    continue;
                }

                ExtractPdfBody::dispatch($article->id, $documentUrl);
                $queued++;
            }
        };

        if ($limit) {
            $dispatchBatch($query->limit((int) $limit)->get());
        } else {
            $query->chunkById(100, function ($articles) use ($dispatchBatch): void {
                $dispatchBatch($articles);
            });
        }

        $this->info("Queued document extraction for {$queued} article(s). Skipped {$skipped} without a source URL.");

        return self::SUCCESS;
    }

    private function documentArticlesQuery(): Builder
    {
        $cityOption = $this->option('city');
        $failedOnly = (bool) $this->option('failed-only');

        $query = Article::query()
            ->where(function (Builder $query): void {
                $query
                    ->whereIn('content_type', ['pdf', 'doc', 'docx', 'document'])
                    ->orWhereRaw('lower(canonical_url) like ?', ['%archive.aspx?adid=%'])
                    ->orWhereRaw('lower(canonical_url) like ?', ['%.pdf%'])
                    ->orWhereRaw('lower(canonical_url) like ?', ['%.docx%'])
                    ->orWhereRaw('lower(canonical_url) like ?', ['%.doc%'])
                    ->orWhereHas('sources', function (Builder $sourceQuery): void {
                        $sourceQuery
                            ->whereIn('source_type', ['pdf', 'doc', 'docx', 'document'])
                            ->orWhereRaw('lower(source_url) like ?', ['%archive.aspx?adid=%'])
                            ->orWhereRaw('lower(source_url) like ?', ['%.pdf%'])
                            ->orWhereRaw('lower(source_url) like ?', ['%.docx%'])
                            ->orWhereRaw('lower(source_url) like ?', ['%.doc%']);
                    });
            })
            ->orderBy('id');

        if ($failedOnly) {
            $query->whereHas('body', function (Builder $bodyQuery): void {
                $bodyQuery
                    ->where('extraction_status', 'failed')
                    ->where('extraction_error', 'Non-PDF response detected');
            });
        }

        if ($cityOption) {
            if (is_numeric((string) $cityOption)) {
                $query->where('city_id', (int) $cityOption);
            } else {
                $query->whereHas('city', function (Builder $cityQuery) use ($cityOption): void {
                    $cityQuery->where('slug', (string) $cityOption);
                });
            }
        }

        return $query;
    }
}
