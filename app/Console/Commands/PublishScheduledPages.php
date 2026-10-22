<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Models\SiteSetting;
use App\Services\PageWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class PublishScheduledPages extends Command
{
    protected $signature = 'fastfind:publish-scheduled';
    protected $description = 'Publish due pages using the normal authorized publishing service';

    public function handle(PageWriter $writer): int
    {
        $published = 0;
        $failed = 0;
        Page::whereNotNull('scheduled_at')->where('scheduled_at', '<=', now())->chunkById(100, function ($pages) use ($writer, &$published, &$failed) {
            foreach ($pages as $page) {
                try {
                    $writer->changeState($page, 'publish', (int) $page->updated_by, $page->version);
                    $published++;
                } catch (\Throwable $error) {
                    $failed++;
                    Log::warning('Scheduled publication failed.', ['page_id' => $page->id, 'exception' => $error::class]);
                    $this->error("Page {$page->id} could not be published; review its content, author permissions and URL.");
                }
            }
        });
        SiteSetting::updateOrCreate(['key' => 'scheduler_last_run'], ['value' => ['at' => now()->toIso8601String(), 'published' => $published, 'failed' => $failed]]);
        $this->info("Published {$published} scheduled page(s).");
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
