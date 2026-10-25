<?php

/**
 * The ERP's storefront API (Sanctum bearer token, ability storefront:read). Plain PHP: the HTTP call
 * goes through a transport callable (WordPress's wp_remote_request by default), so it can be tested
 * without WordPress.
 *
 * Transport signature: fn(string $method, string $url, array $headers, ?string $body): array{status: int, body: string}
 */
final class Mvrk_Wc_Api
{
    /** @var callable */
    private $transport;

    public function __construct(private string $appUrl, private string $token, ?callable $transport = null)
    {
        $this->appUrl = rtrim(trim($appUrl), '/');
        $this->transport = $transport ?? [self::class, 'wordpressTransport'];
    }

    public function configured(): bool
    {
        return $this->appUrl !== '' && $this->token !== '' && str_starts_with($this->appUrl, 'https://');
    }

    /** @return array{ok: bool, status: int, data: array<string, mixed>, error: ?string} */
    public function ping(): array
    {
        return $this->call('GET', '/api/storefront/ping');
    }

    /** @return array{ok: bool, status: int, data: array<string, mixed>, error: ?string} */
    public function availability(string $sku): array
    {
        $sku = trim($sku);
        if ($sku === '') {
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'No SKU.'];
        }

        return $this->call('GET', '/api/storefront/availability?sku='.rawurlencode($sku));
    }

    /**
     * @param  array<string, string>  $fields  name, phone, email, sku, location, preferred_date, message
     * @return array{ok: bool, status: int, data: array<string, mixed>, error: ?string}
     */
    public function enquiry(array $fields, string $visitorIp): array
    {
        $allowed = ['name', 'phone', 'email', 'sku', 'location', 'preferred_date', 'message'];
        $body = array_intersect_key(array_map(fn ($v) => trim((string) $v), $fields), array_flip($allowed));

        return $this->call('POST', '/api/storefront/enquiries', $body, filter_var($visitorIp, FILTER_VALIDATE_IP) ? ['X-Visitor-IP' => $visitorIp] : []);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $extraHeaders
     * @return array{ok: bool, status: int, data: array<string, mixed>, error: ?string}
     */
    private function call(string $method, string $path, ?array $body = null, array $extraHeaders = []): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'Not connected: set the app address (https://) and token in Settings → MVRK Connector.'];
        }

        $headers = ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$this->token] + $extraHeaders;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        try {
            $response = ($this->transport)($method, $this->appUrl.$path, $headers, $body === null ? null : json_encode($body));
        } catch (Throwable $e) {
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'The app could not be reached: '.$e->getMessage()];
        }

        $status = (int) ($response['status'] ?? 0);
        $data = json_decode((string) ($response['body'] ?? ''), true);
        $data = is_array($data) ? $data : [];
        $ok = $status >= 200 && $status < 300;

        return [
            'ok' => $ok,
            'status' => $status,
            'data' => $data,
            'error' => $ok ? null : match (true) {
                $status === 0 => 'The app could not be reached.',
                $status === 401, $status === 403 => 'The connection token was refused: create a new one (php artisan woo:storefront-token).',
                $status === 404 => 'Not found.',
                $status === 422 => (string) ($data['message'] ?? 'Please check the form.'),
                $status === 429 => (string) ($data['message'] ?? 'Too many requests.'),
                default => "The app answered {$status}.",
            },
        ];
    }

    /** @return array{status: int, body: string} */
    public static function wordpressTransport(string $method, string $url, array $headers, ?string $body): array
    {
        $response = wp_remote_request($url, ['method' => $method, 'headers' => $headers, 'body' => $body, 'timeout' => 8, 'redirection' => 0]);

        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }

        return ['status' => (int) wp_remote_retrieve_response_code($response), 'body' => (string) wp_remote_retrieve_body($response)];
    }
}
