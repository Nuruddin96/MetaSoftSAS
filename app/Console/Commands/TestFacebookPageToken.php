<?php

namespace App\Console\Commands;

use App\Models\FacebookPage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestFacebookPageToken extends Command
{
    protected $signature = 'facebook:test-psid {page_id} {psid}';

    protected $description = 'Test Messenger PSID lookup using stored Page Access Token';

    public function handle(): int
    {
        $page = FacebookPage::withoutGlobalScopes()
            ->where('page_id', $this->argument('page_id'))
            ->where('is_active', 1)
            ->first();

        if (! $page) {
            $this->error('Active Facebook Page not found.');

            return self::FAILURE;
        }

        $token = $page->page_access_token;

        if (! $token) {
            $this->error('Page Access Token is empty.');

            return self::FAILURE;
        }

        $psid = $this->argument('psid');

        $response = Http::get(
            'https://graph.facebook.com/v26.0/'.$psid,
            [
                'fields' => 'first_name,last_name,profile_pic',
                'access_token' => $token,
            ]
        );

        $this->line('HTTP status: '.$response->status());

        $body = $response->json();

        $this->line(json_encode(
            $body,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ));

        return $response->successful()
            ? self::SUCCESS
            : self::FAILURE;
    }
}
