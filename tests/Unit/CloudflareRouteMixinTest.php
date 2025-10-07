<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Yediyuz\CloudflareCache\CloudflareCache;
use Yediyuz\CloudflareCache\CloudflarePagesMiddleware;

it('should merge tags incorrectly in old middleware', function () {
    $middleware = new CloudflarePagesMiddleware;

    $request = Request::create('/first', 'GET');
    $response1 = new Response('First');
    $response2 = new Response('Second');

    // İlk route group
    $middleware->handle($request, fn ($req) => $response1, '30', 'first');

    // İkinci route group
    $middleware->handle($request, fn ($req) => $response2, '10', 'second');

    $tags = explode(',', $response2->headers->get('Cache-Tags') ?? '');

    // Bu attribute merge bug’ını gösterecek
    expect($tags)->toContain('first')->toContain('second');
});

it('merges tags and overwrites TTL for route groups (demonstrates existing bug)', function () {
    // İlk route grubu
    Route::cache(tags: ['first'], ttl: 30)->group(function () {
        Route::get('/first', fn () => response('First'));
    });

    // İkinci route grubu
    Route::cache(tags: ['second'], ttl: 10)->group(function () {
        Route::get('/second', fn () => response('Second'));
    });

    $firstResponse = $this->get('/first');
    $secondResponse = $this->get('/second');

    // Cache-Control header
    // Eski paket bug’ı: TTL en son route group’un TTL’si ile override edilir
    $firstResponse->assertHeader('Cache-Control', 'max-age=30, public'); // ⚠ Fail olacak
    $secondResponse->assertHeader('Cache-Control', 'max-age=10, public');

    // Cache-Tags header
    // Eski paket bug’ı: tags merge edilir
    $firstTags = explode(',', $firstResponse->headers->get('Cache-Tags') ?? '');
    $secondTags = explode(',', $secondResponse->headers->get('Cache-Tags') ?? '');

    expect($firstTags)->toContain('first')->not()->toContain('second'); // ⚠ Fail olacak
    expect($secondTags)->toContain('second')->not()->toContain('first'); // ⚠ Fail olacak
});

it('should not merge tags and TTL across route groups', function () {
    // İlk route grubu
    Route::cache(tags: ['first'], ttl: 30)->group(function () {
        Route::get('/first', fn () => response('First'));
    });

    // İkinci route grubu
    Route::cache(tags: ['second'], ttl: 10)->group(function () {
        Route::get('/second', fn () => response('Second'));
    });

    $firstResponse = $this->get('/first');
    $secondResponse = $this->get('/second');

    // Cache-Control header
    $firstResponse->assertHeader('Cache-Control', 'max-age=30, public');
    $secondResponse->assertHeader('Cache-Control', 'max-age=10, public');

    // Cache-Tags header
    $firstTags = explode(',', $firstResponse->headers->get('Cache-Tags') ?? '');
    $secondTags = explode(',', $secondResponse->headers->get('Cache-Tags') ?? '');

    // Test: İlk route sadece 'first', ikinci route sadece 'second' içermeli
    expect($firstTags)->toContain('first')->not()->toContain('second');
    expect($secondTags)->toContain('second')->not()->toContain('first');
});

dataset('cache_mixin_tag_types', [
    ['foo', ['foo']],
    [['foo', 'bar'], ['foo', 'bar']],
    [['foo', '123'], ['foo', '123']],
    [['foo', []], ['foo']],
    [['foo', ['bar']], ['foo']],
    [['foo', true, false], ['foo']],
    [['foo', new stdClass], ['foo']],
    [['foo', '', ' ', '0', '1'], ['foo', '0', '1']],
]);

it('should filter cache tag types as expected', function ($tags, $expectedTags, $ttl) {

    $request = request();

    $request->attributes->remove(CloudflareCache::TAGS_ATTR);
    $request->attributes->remove(CloudflareCache::TTL_ATTR);

    $this->assertFalse($request->attributes->has(CloudflareCache::TAGS_ATTR));
    $this->assertFalse($request->attributes->has(CloudflareCache::TTL_ATTR));

    Route::cache($tags, $ttl)->get('/test', function () {
        return 'test';
    });

    $response = $this->get('test');
    expect($response)->assertHeader('Cache-Tags', implode(',', $expectedTags));

})->with('cache_mixin_tag_types')
    ->with([
        null,
        600,
    ]);

test('cache tag must exist in the header only if used', function () {
    $response = $this->get('content_without_tags');
    $this->assertFalse($response->headers->has('Cache-Tags'));

    $response = $this->get('content_in_args');
    $this->assertTrue($response->headers->has('Cache-Tags'));
});

it('should set correct TTL and tags per route', function () {
    Route::cache(tags: ['first'], ttl: 30)->get('/first', fn () => 'First');
    Route::cache(tags: ['second'], ttl: 10)->get('/second', fn () => 'Second');

    $firstResponse = $this->get('/first');
    $secondResponse = $this->get('/second');

    $firstResponse->assertHeader('Cache-Control', 'max-age=30, public');
    $secondResponse->assertHeader('Cache-Control', 'max-age=10, public');

    $firstTags = explode(',', $firstResponse->headers->get('Cache-Tags'));
    $secondTags = explode(',', $secondResponse->headers->get('Cache-Tags'));

    expect($firstTags)->toContain('first')->not()->toContain('second');
    expect($secondTags)->toContain('second')->not()->toContain('first');
});
