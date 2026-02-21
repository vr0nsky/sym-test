<?php

namespace App\Tests\EventListener;

use App\EventListener\JwtListener;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class JwtListenerTest extends TestCase
{
    private HttpClientInterface $http;
    private CacheItemPoolInterface $cache;
    private JwtListener $listener;

    protected function setUp(): void
    {
        $this->http = $this->createMock(HttpClientInterface::class);
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->listener = new JwtListener(
            $this->http,
            $this->cache,
            'http://keycloak',
            'master'
        );
    }

    public function testSkipsNonApiRoutes(): void
    {
        $request = Request::create('/public/page'); // richiesta a route fuori da api
        $event = $this->createEvent($request);

        $this->listener->onKernelRequest($event);

        $this->assertNull($event->getResponse()); //non deve fare nulla
    }

    public function testSkipsAuthRoute(): void
    {
        $request = Request::create('/api/auth', 'POST'); // il listener deve ignorare /api/auth che è il login
        $event = $this->createEvent($request);

        $this->listener->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }

    public function testReturns401WhenNoToken(): void
    {
        $request = Request::create('/api/jobs'); //richiesta a route autenticata senza header, non deve funzionare
        $event = $this->createEvent($request);

        $this->setupCacheHit();

        $this->listener->onKernelRequest($event);

        $this->assertEquals(401, $event->getResponse()->getStatusCode());
        $this->assertStringContainsString('No token', $event->getResponse()->getContent());
    }

    public function testReturns401WhenInvalidToken(): void 
    {
        $request = Request::create('/api/jobs');
        $request->headers->set('Authorization', 'Bearer invalidtoken123'); // token non valido
        $event = $this->createEvent($request);

        $this->setupCacheHit();

        $this->listener->onKernelRequest($event);

        $this->assertEquals(401, $event->getResponse()->getStatusCode());
        $this->assertStringContainsString('Invalid token', $event->getResponse()->getContent());
    }

    public function testFetchesJwksFromKeycloakWhenCacheMiss(): void
    {
        $request = Request::create('/api/jobs');
        $request->headers->set('Authorization', 'Bearer sometoken'); // token non valido
        $event = $this->createEvent($request);

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);
        $cacheItem->method('set')->willReturnSelf();
        $cacheItem->method('expiresAfter')->willReturnSelf();
        $cacheItem->method('get')->willReturn(['keys' => []]);

        $this->cache->method('getItem')->willReturn($cacheItem);
        $this->cache->method('save');

        $httpResponse = $this->createMock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $httpResponse->method('toArray')->willReturn(['keys' => []]);
        $this->http->expects($this->once())->method('request')->willReturn($httpResponse);

        $this->listener->onKernelRequest($event);

        // token non valido ma Keycloak è stato chiamato
        $this->assertEquals(401, $event->getResponse()->getStatusCode());
    }

    private function createEvent(Request $request): RequestEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function setupCacheHit(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn(['keys' => []]);
        $this->cache->method('getItem')->willReturn($cacheItem);
    }
}