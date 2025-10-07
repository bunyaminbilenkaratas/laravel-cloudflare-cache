<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Yediyuz\CloudflareCache\CloudflareCache;
use Yediyuz\CloudflareCache\CloudflarePagesMiddleware;

// ====================================
// BUG DEMONSTRATION TESTS
// Bu testler FAIL olacak çünkü bug'ı gösteriyorlar
// ====================================

it('FAILS: demonstrates tag accumulation across middleware calls', function () {
    $middleware = new CloudflarePagesMiddleware;
    $request = Request::create('/test', 'GET');

    // İlk middleware çağrısı - 'first' tag'i
    $response1 = new Response('First');
    $result1 = $middleware->handle($request, fn () => $response1, '30', 'first');

    // Request attributes'a yazıldığını kontrol et
    expect($request->attributes->get(CloudflareCache::TAGS_ATTR))
        ->toBe(['first']);

    // İkinci middleware çağrısı - AYNI REQUEST objesi ile 'second' tag'i
    $response2 = new Response('Second');
    $result2 = $middleware->handle($request, fn () => $response2, '10', 'second');

    // ❌ BUG: İlk tag hala orada, merge olmuş!
    $actualTags = $request->attributes->get(CloudflareCache::TAGS_ATTR);
    expect($actualTags)
        ->toBe(['second'])  // BEKLENİYOR: sadece 'second'
        ->and($actualTags)->not->toContain('first');  // FAIL: 'first' hala var!

    // Response header'ı da kontrol et
    $headerTags = explode(',', $result2->headers->get('Cache-Tags') ?? '');
    expect($headerTags)
        ->toBe(['second'])  // BEKLENİYOR: sadece 'second'
        ->and($headerTags)->not->toContain('first');  // FAIL: 'first,second' gelir
})->fails();

it('FAILS: demonstrates TTL persistence across middleware calls', function () {
    $middleware = new CloudflarePagesMiddleware;
    $request = Request::create('/test', 'GET');

    // İlk çağrı: TTL = 30
    $response1 = new Response('First');
    $result1 = $middleware->handle($request, fn () => $response1, '30', 'first');

    expect($result1->headers->get('Cache-Control'))
        ->toContain('max-age=30');

    // İkinci çağrı: TTL boş (default kullanmalı)
    $response2 = new Response('Second');
    $result2 = $middleware->handle($request, fn () => $response2, '', 'second');

    // ❌ BUG: İlk TTL (30) hala kullanılıyor!
    expect($result2->headers->get('Cache-Control'))
        ->not->toContain('max-age=30')  // FAIL: 30 hala var!
        ->toContain('max-age=600');  // BEKLENİYOR: default 600
})->fails();

it('FAILS: demonstrates tag accumulation with multiple routes', function () {
    $middleware = new CloudflarePagesMiddleware;
    $request = Request::create('/test', 'GET');

    // Üç farklı route simülasyonu
    $responses = [];
    $expectedTags = ['first', 'second', 'third'];

    foreach ($expectedTags as $tag) {
        $response = new Response($tag);
        $result = $middleware->handle($request, fn () => $response, '10', $tag);
        $responses[] = $result;
    }

    // Son response'un tag'leri kontrol et
    $lastResponse = end($responses);
    $headerTags = explode(',', $lastResponse->headers->get('Cache-Tags') ?? '');

    // ❌ BUG: Tüm tag'ler birikmiş!
    expect($headerTags)
        ->toBe(['third'])  // BEKLENİYOR: sadece 'third'
        ->and($headerTags)->not->toContain('first')  // FAIL: hepsi var!
        ->and($headerTags)->not->toContain('second');  // FAIL: hepsi var!
})->fails();

// ====================================
// DIRECT ATTRIBUTE MANIPULATION TESTS
// Bu testler bug'ı daha net gösterir
// ====================================

it('FAILS: request attributes persist between middleware calls', function () {
    $middleware = new CloudflarePagesMiddleware;
    $request = Request::create('/test', 'GET');

    // İlk çağrı
    $middleware->handle($request, fn () => new Response, '30', 'tag1');
    $firstCallTags = $request->attributes->get(CloudflareCache::TAGS_ATTR);

    // İkinci çağrı
    $middleware->handle($request, fn () => new Response, '10', 'tag2');
    $secondCallTags = $request->attributes->get(CloudflareCache::TAGS_ATTR);

    // ❌ BUG: İkinci çağrıda ilk çağrının tag'i hala var
    expect($secondCallTags)
        ->not->toEqual($firstCallTags)  // Farklı olmalı
        ->and($secondCallTags)->toBe(['tag2'])  // FAIL: ['tag1', 'tag2'] gelir
        ->and($secondCallTags)->not->toContain('tag1');  // FAIL: içeriyor!
})->fails();

it('FAILS: getCacheTags method merges instead of replacing', function () {
    $middleware = new CloudflarePagesMiddleware;
    $request = Request::create('/test', 'GET');

    // Manuel olarak attribute set et
    $request->attributes->set(CloudflareCache::TAGS_ATTR, ['existing-tag']);

    // Middleware çağır
    $response = new Response;
    $result = $middleware->handle($request, fn () => $response, '10', 'new-tag');

    $tags = explode(',', $result->headers->get('Cache-Tags') ?? '');

    // ❌ BUG: Eski tag ile yeni tag merge olmuş
    expect($tags)
        ->toBe(['new-tag'])  // BEKLENİYOR: sadece yeni tag
        ->and($tags)->not->toContain('existing-tag');  // FAIL: eski tag var!
})->fails();

// ====================================
// REALISTIC SCENARIO TESTS
// Gerçek dünya senaryolarını simüle eder
// ====================================

it('FAILS: simulates PHP-FPM worker handling multiple requests', function () {
    $middleware = new CloudflarePagesMiddleware;

    // Aynı worker'da 3 farklı request işleniyor
    $sharedRequest = Request::create('/shared', 'GET');

    $requests = [
        ['url' => '/homepage', 'tags' => 'homepage', 'ttl' => '3600'],
        ['url' => '/products', 'tags' => 'products', 'ttl' => '1800'],
        ['url' => '/about', 'tags' => 'about', 'ttl' => '600'],
    ];

    $lastResponse = null;
    foreach ($requests as $req) {
        $response = new Response($req['url']);
        $lastResponse = $middleware->handle(
            $sharedRequest,
            fn () => $response,
            $req['ttl'],
            $req['tags']
        );
    }

    // Son request'in (about) sadece kendi tag'ini içermesi gerekiyor
    $tags = explode(',', $lastResponse->headers->get('Cache-Tags') ?? '');

    // ❌ BUG: Tüm tag'ler birikmiş
    expect($tags)
        ->toBe(['about'])  // BEKLENİYOR: sadece 'about'
        ->and($tags)->not->toContain('homepage')  // FAIL: var!
        ->and($tags)->not->toContain('products');  // FAIL: var!
})->fails();

it('FAILS: different routes should have isolated cache tags', function () {
    $middleware = new CloudflarePagesMiddleware;
    $request = Request::create('/test', 'GET');

    // Route 1: User profile
    $r1 = $middleware->handle(
        $request,
        fn () => new Response('User Profile'),
        '300',
        'user-profile;user-123'
    );

    $tags1 = explode(',', $r1->headers->get('Cache-Tags') ?? '');
    expect($tags1)->toHaveCount(2)->toContain('user-profile', 'user-123');

    // Route 2: Product list (tamamen farklı)
    $r2 = $middleware->handle(
        $request,
        fn () => new Response('Products'),
        '600',
        'products;category-5'
    );

    $tags2 = explode(',', $r2->headers->get('Cache-Tags') ?? '');

    // ❌ BUG: İlk route'un tag'leri hala var
    expect($tags2)
        ->toHaveCount(2)  // FAIL: 4 tane var!
        ->toContain('products', 'category-5')
        ->not->toContain('user-profile')  // FAIL: var!
        ->not->toContain('user-123');  // FAIL: var!
})->fails();

// ====================================
// EDGE CASE TESTS
// Sınır durumları test eder
// ====================================

it('FAILS: empty tags should not inherit previous tags', function () {
    $middleware = new CloudflarePagesMiddleware;
    $request = Request::create('/test', 'GET');

    // İlk çağrı: tag var
    $r1 = $middleware->handle($request, fn () => new Response, '10', 'has-tag');
    expect($r1->headers->get('Cache-Tags'))->toBe('has-tag');

    // İkinci çağrı: tag yok (empty string)
    $r2 = $middleware->handle($request, fn () => new Response, '10', '');

    // ❌ BUG: İlk çağrının tag'i hala var
    expect($r2->headers->has('Cache-Tags'))->toBeFalse()  // FAIL: header var!
        ->or(fn () => expect($r2->headers->get('Cache-Tags'))->toBe(''));  // FAIL: 'has-tag' var!
})->fails();

it('FAILS: null tags should start fresh', function () {
    $middleware = new CloudflarePagesMiddleware;
    $request = Request::create('/test', 'GET');

    // Set some tags first
    $request->attributes->set(CloudflareCache::TAGS_ATTR, ['old-tag-1', 'old-tag-2']);

    // Call middleware without tags
    $response = $middleware->handle($request, fn () => new Response, '10', '');

    // ❌ BUG: Eski tag'ler hala orada
    $tags = $request->attributes->get(CloudflareCache::TAGS_ATTR, []);
    expect($tags)
        ->toBeEmpty()  // FAIL: ['old-tag-1', 'old-tag-2'] var!
        ->or(fn () => expect($tags)->toBe([]));
})->fails();

// ====================================
// CONCURRENCY SIMULATION
// Concurrent request simülasyonu
// ====================================

it('FAILS: concurrent-like requests should not share tags', function () {
    $middleware = new CloudflarePagesMiddleware;

    // Aynı request objesi (worker persistence simülasyonu)
    $workerRequest = Request::create('/worker', 'GET');

    // 5 farklı "request" aynı worker'da
    $requestData = [
        ['tags' => 'api-v1', 'expected' => ['api-v1']],
        ['tags' => 'api-v2', 'expected' => ['api-v2']],
        ['tags' => 'web-home', 'expected' => ['web-home']],
        ['tags' => 'admin-dashboard', 'expected' => ['admin-dashboard']],
        ['tags' => 'public-page', 'expected' => ['public-page']],
    ];

    $results = [];
    foreach ($requestData as $data) {
        $response = new Response;
        $result = $middleware->handle($workerRequest, fn () => $response, '10', $data['tags']);

        $actualTags = explode(',', $result->headers->get('Cache-Tags') ?? '');
        $results[] = [
            'expected' => $data['expected'],
            'actual' => $actualTags,
            'matches' => $actualTags === $data['expected'],
        ];
    }

    // ❌ BUG: Sadece ilk request doğru, diğerleri birikmiş tag'ler içeriyor
    foreach ($results as $index => $result) {
        expect($result['matches'])
            ->toBeTrue()  // FAIL: İlk dışında hepsi false!
            ->and($result['actual'])->toBe($result['expected']);
    }
})->fails();

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
