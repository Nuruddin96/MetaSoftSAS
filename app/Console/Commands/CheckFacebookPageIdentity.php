<?php

namespace App\Console\Commands;

use App\Models\FacebookPage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CheckFacebookPageIdentity extends Command
{
    protected $signature = 'facebook:check-page-identity {page_id}';

    protected $description = 'Compare stored Facebook Page ID with the Page identity returned by its token';

    public function handle(): int
    {
        $pageId = $this->argument('page_id');

        $page = FacebookPage::withoutGlobalScopes()
            ->where('page_id', $pageId)
            ->where('is_active', 1)
            ->first();

        if (! $page) {
            $this->error('Facebook page row not found.');

            return self::FAILURE;
        }

        $this->info('DB row:');
        $this->line('  DB id:       '.$page->id);
        $this->line('  DB tenant:   '.$page->tenant_id);
        $this->line('  DB page_id:   '.$page->page_id);
        $this->line('  Token length: '.strlen((string) $page->page_access_token));

        $response = Http::get(
            'https://graph.facebook.com/v26.0/me',
            [
                'fields' => 'id,name',
                'access_token' => $page->page_access_token,
            ]
        );

        $this->line('');
        $this->line('Graph /me response:');
        $this->line($response->body());

        if (! $response->successful()) {
            return self::FAILURE;
        }

        $data = $response->json();

        $this->line('');
        $this->line('Comparison:');

        $graphId = (string) ($data['id'] ?? '');

        $this->line('  DB page_id: '.$page->page_id);
        $this->line('  Token Page: '.$graphId);
        $this->line('  Page name:   '.($data['name'] ?? 'unknown'));

        if ($graphId !== (string) $page->page_id) {
            $this->error('MISMATCH: stored token does NOT belong to the DB page.');

            return self::FAILURE;
        }

        $this->info('MATCH: stored token belongs to the DB page.');

        return self::SUCCESS;
    }
}
