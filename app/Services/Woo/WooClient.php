<?php

namespace App\Services\Woo;

use App\Facades\Integrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * WooCommerce REST API v3 (`/wp-json/wc/v3`), with the consumer key/secret from Integrations
 * (HTTP basic auth over https). Retries 429 / 5xx / connection errors with backoff (0.5 s, 2 s).
 */
class WooClient
{
    /** WooCommerce's batch endpoints accept at most 100 items per call. */
    public const BATCH_LIMIT = 100;

    public function configured(): bool
    {
        return Integrations::filled('WOO_STORE_URL', 'WOO_CONSUMER_KEY', 'WOO_CONSUMER_SECRET');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('get', $path, $query)->json() ?? [];
    }

    /**
     * Every page of a list endpoint (per_page 100), lazily.
     *
     * @param  array<string, mixed>  $query
     * @return \Generator<int, array<string, mixed>>
     */
    public function each(string $path, array $query = []): \Generator
    {
        $page = 1;
        do {
            $response = $this->send('get', $path, [...$query, 'per_page' => 100, 'page' => $page]);
            foreach ($response->json() ?? [] as $row) {
                yield $row;
            }
            $pages = (int) ($response->header('X-WP-TotalPages') ?: 1);
            $page++;
        } while ($page <= $pages);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<mixed>
     */
    public function post(string $path, array $data): array
    {
        return $this->send('post', $path, $data)->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<mixed>
     */
    public function put(string $path, array $data): array
    {
        return $this->send('put', $path, $data)->json() ?? [];
    }

    /** GET /system_status-lite: who are we talking to (for "Test connection"). */
    public function ping(): array
    {
        $settings = $this->get('settings/general');

        return ['ok' => true, 'currency' => collect($settings)->firstWhere('id', 'woocommerce_currency')['value'] ?? null];
    }

    private function send(string $method, string $path, array $data = []): Response
    {
        if (! $this->configured()) {
            throw new WooApiException('WooCommerce is not set up (Settings → Integrations).');
        }

        try {
            $response = $this->request()->{$method}(ltrim($path, '/'), $data);
        } catch (RequestException $e) {
            $response = $e->response;
        } catch (ConnectionException $e) {
            throw new WooApiException('Could not reach WooCommerce: '.$e->getMessage());
        } catch (Throwable $e) {
            throw new WooApiException($e->getMessage());
        }

        if ($response->failed()) {
            $message = $response->json('message') ?? $response->reason();
            throw new WooApiException("WooCommerce {$response->status()}: {$message}", $response->status());
        }

        return $response;
    }

    private function request(): PendingRequest
    {
        $base = rtrim((string) Integrations::get('WOO_STORE_URL'), '/').'/wp-json/wc/v3/';

        return Http::baseUrl($base)
            ->withBasicAuth((string) Integrations::get('WOO_CONSUMER_KEY'), (string) Integrations::get('WOO_CONSUMER_SECRET'))
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->connectTimeout(5)
            ->retry([500, 2000], when: fn (Throwable $e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && ($e->response->status() === 429 || $e->response->serverError())));
    }
}
