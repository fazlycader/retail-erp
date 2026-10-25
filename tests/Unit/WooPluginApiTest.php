<?php

// The WordPress plugin's API client is plain PHP with an injectable transport: tested without WordPress.
require_once dirname(__DIR__, 2).'/wordpress-plugin/mvrk-woo-connector/includes/class-mvrk-api.php';

/** A transport that records every call and answers with the given status and body. */
function pluginTransport(int $status, array $body, array &$calls): callable
{
    return function (string $method, string $url, array $headers, ?string $payload) use ($status, $body, &$calls) {
        $calls[] = compact('method', 'url', 'headers', 'payload');

        return ['status' => $status, 'body' => json_encode($body)];
    };
}

test('availability sends the bearer token and an encoded SKU, and returns the data', function () {
    $calls = [];
    $api = new Mvrk_Wc_Api('https://erp.example.lk/ ', 'tok-123', pluginTransport(200, ['sku' => 'SOF GRY/1', 'locations' => []], $calls));

    $result = $api->availability(' SOF GRY/1 ');

    expect($result)->toMatchArray(['ok' => true, 'status' => 200, 'error' => null])
        ->and($result['data']['sku'])->toBe('SOF GRY/1')
        ->and($calls[0]['method'])->toBe('GET')
        ->and($calls[0]['url'])->toBe('https://erp.example.lk/api/storefront/availability?sku=SOF%20GRY%2F1')
        ->and($calls[0]['headers'])->toMatchArray(['Authorization' => 'Bearer tok-123', 'Accept' => 'application/json'])
        ->and($calls[0]['payload'])->toBeNull();
});

test('an enquiry posts only the allowed fields as JSON, with the visitor IP when it is valid', function () {
    $calls = [];
    $api = new Mvrk_Wc_Api('https://erp.example.lk', 'tok', pluginTransport(201, ['ok' => true], $calls));

    expect($api->enquiry(['name' => ' Nimal ', 'phone' => '0712345678', 'role' => 'admin', 'website' => 'x'], '203.0.113.5')['ok'])->toBeTrue();
    $api->enquiry(['name' => 'Nimal'], 'not-an-ip');

    expect($calls[0]['method'])->toBe('POST')
        ->and(json_decode($calls[0]['payload'], true))->toBe(['name' => 'Nimal', 'phone' => '0712345678'])
        ->and($calls[0]['headers'])->toMatchArray(['Content-Type' => 'application/json', 'X-Visitor-IP' => '203.0.113.5'])
        ->and($calls[1]['headers'])->not->toHaveKey('X-Visitor-IP');
});

test('errors are mapped to messages a shop owner can act on', function (int $status, array $body, string $message) {
    $calls = [];
    $result = (new Mvrk_Wc_Api('https://erp.example.lk', 'tok', pluginTransport($status, $body, $calls)))->ping();

    expect($result['ok'])->toBeFalse()->and($result['status'])->toBe($status)->and($result['error'])->toContain($message);
})->with([
    'token refused' => [401, [], 'token was refused'],
    'wrong ability' => [403, [], 'token was refused'],
    'validation' => [422, ['message' => 'The phone field is required.'], 'The phone field is required.'],
    'rate limited' => [429, [], 'Too many requests'],
    'server error' => [500, [], 'The app answered 500'],
]);

test('nothing is sent when not configured or not https, and transport failures are caught', function () {
    $calls = [];
    expect((new Mvrk_Wc_Api('http://erp.example.lk', 'tok', pluginTransport(200, [], $calls)))->ping()['error'])->toContain('Not connected')
        ->and((new Mvrk_Wc_Api('https://erp.example.lk', '', pluginTransport(200, [], $calls)))->ping()['ok'])->toBeFalse()
        ->and((new Mvrk_Wc_Api('https://erp.example.lk', 'tok', pluginTransport(200, [], $calls)))->availability('  ')['error'])->toBe('No SKU.')
        ->and($calls)->toBeEmpty();

    $down = new Mvrk_Wc_Api('https://erp.example.lk', 'tok', fn () => throw new RuntimeException('cURL error 28'));
    expect($down->ping())->toMatchArray(['ok' => false, 'status' => 0])->and($down->ping()['error'])->toContain('cURL error 28');
});
