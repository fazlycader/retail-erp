<?php

use App\Http\Controllers\System\HealthController;
use App\Models\User;
use Database\Seeders\CompanySettingSeeder;
use Database\Seeders\LocationSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed([LocationSeeder::class, CompanySettingSeeder::class]);
});

// ---- Headers & caching ---------------------------------------------------------------------

test('every page gets security headers, a CSP nonce that matches the scripts, and no-store', function () {
    $response = $this->get('/login')->assertOk();

    preg_match("/script-src 'self' 'nonce-([^']+)'/", $response->headers->get('Content-Security-Policy'), $m);
    $nonce = $m[1] ?? null;

    expect($nonce)->not->toBeNull()
        ->and($response->getContent())->toContain('nonce="'.$nonce.'"')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('Vary'))->toContain('Cookie')
        ->and($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'self'")->toContain("object-src 'none'");
});

test('JSON, print views and public document links are never cacheable', function () {
    $admin = User::factory()->admin()->create();

    foreach ([
        $this->actingAs($admin)->getJson(route('customers.lookup', ['q' => '077'])),
        $this->actingAs($admin)->get(route('reports.print', ['report' => 'sales', 'location' => 'all'])),
        $this->get('/doc/'.str_repeat('a', 40)),
    ] as $response) {
        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    }
});

test('print views carry the CSP nonce on their only script', function () {
    $admin = User::factory()->admin()->create();
    $response = $this->actingAs($admin)->get(route('reports.print', ['report' => 'sales', 'location' => 'all']))->assertOk();
    preg_match("/'nonce-([^']+)'/", $response->headers->get('Content-Security-Policy'), $m);

    expect($response->getContent())->toContain('<script nonce="'.$m[1].'">')->not->toContain('onclick=');
});

test('HSTS over HTTPS; HTTP is redirected when FORCE_HTTPS is on', function () {
    expect($this->get('https://localhost/login')->headers->get('Strict-Transport-Security'))->toContain('max-age=31536000');

    config(['security.force_https' => true]);
    $this->get('http://localhost/login')->assertRedirect('https://localhost/login')->assertStatus(301);
    $this->post('http://localhost/login')->assertStatus(426);
});

test('the storage fallback serves public images with long caching, nothing else', function () {
    Storage::fake('public');
    Storage::disk('public')->put('products/abc.webp', 'RIFF....WEBP');
    Storage::disk('public')->put('products/evil.php', '<?php echo 1;');
    Storage::disk('local')->put('exports/secret.csv', 'secret');

    $ok = $this->get('/storage/products/abc.webp')->assertOk();
    expect($ok->headers->get('Cache-Control'))->toContain('public')->toContain('immutable')
        ->and($ok->headers->get('Content-Type'))->toBe('image/webp')
        ->and($ok->headers->has('Set-Cookie'))->toBeFalse();

    $this->get('/storage/products/evil.php')->assertNotFound();
    $this->get('/storage/../app/private/exports/secret.csv')->assertNotFound();
    $this->get('/storage/products/missing.webp')->assertNotFound();
});

test('private storage is never served over the web', function () {
    expect(config('filesystems.disks.local.serve'))->toBeFalse();
});

// ---- Health & OPcache -----------------------------------------------------------------------

test('/health needs the token and reports each check', function () {
    config(['security.health_token' => 'secret-token']);

    Storage::fake('backups');
    $this->getJson('/health')->assertNotFound();
    $this->getJson('/health?token=wrong')->assertNotFound();

    // Fresh install: cron not running yet → down (503) on the full check, fine for the deploy scope.
    $this->getJson('/health?token=secret-token')->assertStatus(503)->assertJsonPath('checks.scheduler.status', 'fail');
    $this->withHeader('X-Health-Token', 'secret-token')->getJson('/health?scope=deploy')->assertOk()
        ->assertJsonPath('status', 'ok')->assertJsonPath('checks.database.status', 'ok')->assertJsonPath('checks.storage.status', 'ok')
        ->assertJsonMissingPath('checks.scheduler');

    Cache::forever(HealthController::HEARTBEAT_KEY, now()->timestamp);
    $this->getJson('/health?token=secret-token')->assertOk()
        ->assertJsonPath('checks.scheduler.status', 'ok')
        ->assertJsonPath('checks.queue.status', 'ok')
        ->assertJsonPath('checks.backups.status', 'warn'); // no backup yet → degraded

    // A stuck queue (oldest job 20 minutes old) is "down".
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->subMinutes(20)->timestamp, 'created_at' => now()->subMinutes(20)->timestamp]);
    $this->getJson('/health?token=secret-token')->assertStatus(503)->assertJsonPath('checks.queue.status', 'fail');
});

test('/health is disabled without a configured token', function () {
    config(['security.health_token' => null]);
    $this->getJson('/health?token=')->assertNotFound();
});

test('the OPcache reset endpoint needs the deploy token', function () {
    config(['security.opcache_reset_token' => 'deploy-token']);

    $this->postJson('/deploy/opcache-reset')->assertNotFound();
    $this->withHeader('X-Deploy-Token', 'wrong')->postJson('/deploy/opcache-reset')->assertNotFound();
    $this->withHeader('X-Deploy-Token', 'deploy-token')->postJson('/deploy/opcache-reset')->assertOk()->assertJsonStructure(['opcache_reset']);
});

test('the CSP allows images from the app and public disk origins', function () {
    config(['app.url' => 'https://erp.example.lk', 'filesystems.disks.public.url' => 'https://img.example.lk/storage']);

    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($csp)->toContain("img-src 'self' data: blob: https://erp.example.lk https://img.example.lk;");
});
