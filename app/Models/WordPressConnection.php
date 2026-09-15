<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;

/**
 * One connected WordPress site per tenant. Uses HasApiTokens (same trait
 * User already uses for the mobile app, Api\Mobile\AuthController::login())
 * so the MetaSoft Connector plugin authenticates its API calls with a
 * normal Sanctum personal access token minted at handshake time — no new
 * credential/auth mechanism invented for this integration.
 */
class WordPressConnection extends Model
{
    use BelongsToTenant, HasApiTokens;

    // See WordPressConnectionToken's docblock — Eloquent's default
    // inference would otherwise resolve this to "word_press_connections".
    protected $table = 'wordpress_connections';

    protected $guarded = [];

    protected $casts = [
        'woocommerce_active' => 'boolean',
        'outbound_secret' => 'encrypted',
        'connected_at' => 'datetime',
        'last_verified_at' => 'datetime',
        'disconnected_at' => 'datetime',
    ];

    /**
     * Same purpose as FacebookPage::tablesReady() — lets every call site
     * degrade to "integration not yet available" instead of a raw SQL
     * error if database/sql/chunk59.sql hasn't been imported yet on this
     * environment.
     */
    public static function tablesReady(): bool
    {
        return Schema::hasTable('wordpress_connections')
            && Schema::hasTable('wordpress_connection_tokens');
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }
}
