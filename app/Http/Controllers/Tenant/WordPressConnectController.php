<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\WordPressConnection;
use App\Services\WordPress\WordPressConnectorService;
use ZipArchive;

/**
 * "Connect WordPress" (Phase 2 of the WordPress integration plan — see
 * docs/wordpress-integration-architecture.md). Unlike FacebookConnectController/
 * WhatsAppConnectController there is no browser OAuth redirect: the tenant
 * generates a short-lived Connection Key here, pastes it into the MetaSoft
 * Connector plugin on their own WordPress site, and the plugin completes
 * the handshake against Api\WordPress\WordPressConnectionController::handshake()
 * — a central API route, not this one.
 */
class WordPressConnectController extends Controller
{
    protected const NOT_READY_MESSAGE = 'WordPress ইন্টিগ্রেশন এখনো প্রস্তুত হয়নি — একটু পর আবার চেষ্টা করুন।';

    public function index()
    {
        if (! WordPressConnection::tablesReady()) {
            return view('tenant.wordpress.index', ['connection' => null, 'notReady' => true]);
        }

        $connection = WordPressConnection::first();

        return view('tenant.wordpress.index', [
            'connection' => $connection,
            'notReady' => false,
            'pluginDownloadUrl' => route('tenant.wordpress.plugin-download'),
        ]);
    }

    /** Tenant clicks "Generate Connection Key" — mint a fresh short-lived key to paste into the plugin. */
    public function generateKey(WordPressConnectorService $wp)
    {
        if (! WordPressConnection::tablesReady()) {
            return redirect()->route('tenant.wordpress.index')->with('error', self::NOT_READY_MESSAGE);
        }

        $tenant = app('currentTenant');
        $user = auth('tenant')->user();

        $state = $wp->createConnectionToken($tenant, $user);

        return redirect()->route('tenant.wordpress.index')->with([
            'success' => 'নতুন কানেকশন কী তৈরি হয়েছে — এটি ৩০ মিনিটের জন্য বৈধ।',
            'connection_key' => $state->token,
            'connection_key_expires_at' => $state->expires_at,
        ]);
    }

    /** "Verify Connection" — live-pings the plugin's health route. */
    public function verify(WordPressConnectorService $wp)
    {
        if (! WordPressConnection::tablesReady()) {
            return redirect()->route('tenant.wordpress.index')->with('error', self::NOT_READY_MESSAGE);
        }

        $connection = WordPressConnection::firstOrFail();

        $ok = $wp->verify($connection);

        return redirect()->route('tenant.wordpress.index')->with(
            $ok ? 'success' : 'error',
            $ok ? 'সংযোগ সক্রিয় ও কার্যকর আছে।' : 'সংযোগ যাচাই করা যায়নি — সাইটটি রিচেবল নয় অথবা প্লাগইন নিষ্ক্রিয়। আবার কানেক্ট করুন।'
        );
    }

    public function disconnect(WordPressConnectorService $wp)
    {
        if (! WordPressConnection::tablesReady()) {
            return redirect()->route('tenant.wordpress.index')->with('error', self::NOT_READY_MESSAGE);
        }

        $connection = WordPressConnection::firstOrFail();

        $wp->disconnect($connection);

        return redirect()->route('tenant.wordpress.index')->with('success', 'WordPress সাইট ডিসকানেক্ট করা হয়েছে।');
    }

    /**
     * Zips wordpress-plugin/metasoft-connector on the fly so the tenant
     * panel never has to ship a pre-built binary artifact in the repo —
     * the plugin's PHP source is the single source of truth, this just
     * packages whatever is currently on disk.
     */
    public function downloadPlugin()
    {
        $source = base_path('wordpress-plugin/metasoft-connector');
        abort_unless(is_dir($source), 404);

        $zipPath = storage_path('app/tmp/metasoft-connector.zip');
        if (! is_dir(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source)) as $file) {
            if ($file->isDir()) {
                continue;
            }

            $relative = 'metasoft-connector/'.substr($file->getPathname(), strlen($source) + 1);
            $zip->addFile($file->getPathname(), str_replace('\\', '/', $relative));
        }

        $zip->close();

        return response()->download($zipPath, 'metasoft-connector.zip')->deleteFileAfterSend(true);
    }
}
