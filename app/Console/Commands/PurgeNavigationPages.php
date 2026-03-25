<?php

namespace App\Console\Commands;

use App\Models\ChatSourcePage;
use App\Services\Chat\Ingestion\NavigationPageClassifier;
use Illuminate\Console\Command;
use Throwable;

class PurgeNavigationPages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'chat:purge-navigation-pages
                            {--dry-run : Report navigation pages without deleting them}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Identify and delete navigation/hub pages from chat source pages.';

    /**
     * Execute the console command.
     */
    public function handle(NavigationPageClassifier $classifier): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('Running in dry-run mode — no pages will be deleted.');
        }

        $total = ChatSourcePage::query()->count();

        if ($total === 0) {
            $this->info('No chat source pages found.');

            return self::SUCCESS;
        }

        $this->info("Scanning {$total} page(s)...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $navigationCount = 0;
        $deletedCount = 0;
        $failures = 0;

        ChatSourcePage::query()
            ->select(['id', 'url', 'title', 'content_text'])
            ->orderBy('id')
            ->chunkById(100, function ($pages) use ($classifier, $dryRun, $bar, &$navigationCount, &$deletedCount, &$failures): bool {
                foreach ($pages as $page) {
                    try {
                        if ($classifier->isNavigationPage($page->content_text ?? '')) {
                            $navigationCount++;

                            $label = $dryRun ? '[DRY-RUN] Would delete' : 'Deleting';
                            $bar->clear();
                            $this->line("  {$label}: {$page->url} — {$page->title}");
                            $bar->display();

                            if (! $dryRun) {
                                $page->chunks()->delete();
                                $page->delete();
                                $deletedCount++;
                            }
                        }
                    } catch (Throwable $exception) {
                        report($exception);
                        $bar->clear();
                        $this->error("  Failed on page {$page->id} ({$page->url}): {$exception->getMessage()}");
                        $bar->display();
                        $failures++;
                    }

                    $bar->advance();
                }

                return true;
            });

        $bar->finish();
        $this->newLine(2);

        $this->info("Pages scanned: {$total}");
        $this->info("Navigation pages found: {$navigationCount}");

        if ($dryRun) {
            $this->info("Pages that would be deleted: {$navigationCount}");
        } else {
            $this->info("Pages deleted: {$deletedCount}");
        }

        if ($failures > 0) {
            $this->warn("Failures: {$failures}");
        }

        return self::SUCCESS;
    }
}
