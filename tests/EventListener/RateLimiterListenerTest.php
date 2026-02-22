<?php

namespace App\Tests\EventListener;

use App\EventListener\RateLimiterListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

class RateLimiterListenerTest extends TestCase
{
    private function makeFactory(int $limit): RateLimiterFactory
    {
        $storage = new CacheStorage(new ArrayAdapter());

        return new RateLimiterFactory([
            'id'       => uniqid('test_'),
            'policy'   => 'fixed_window',
            'limit'    => $limit,
            'interval' => '1 minute',
        ], $storage);
    }

    private function makeExhaustedFactory(): RateLimiterFactory
    {
        $factory = $this->makeFactory(1);
        $factory->create('127.0.0.1')->consume(1);

        return $factory;
    }

    private function createEvent(string $path, string $method = 'GET'): RequestEvent
    {
        $kernel  = $this->createMock(HttpKernelInterface::class);
        $request = Request::create($path, $method, [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function makeListener(RateLimiterFactory $public, RateLimiterFactory $private): RateLimiterListener
    {
        return new RateLimiterListener($public, $private);
    }

    // Route non limitata

    public function testUnknownRouteIsSkipped(): void
    {
        $listener = $this->makeListener(
            $this->makeExhaustedFactory(),
            $this->makeExhaustedFactory(),
        );

        $listener->onKernelRequest($this->createEvent('/healthz'));

        $this->addToAssertionCount(1);
    }

    // Route accettate

    public function testHomeIsAccepted(): void
    {
        $listener = $this->makeListener($this->makeFactory(1000), $this->makeFactory(1000));

        $listener->onKernelRequest($this->createEvent('/'));

        $this->addToAssertionCount(1);
    }

    public function testApiAuthIsAccepted(): void
    {
        $listener = $this->makeListener($this->makeFactory(1000), $this->makeFactory(1000));

        $listener->onKernelRequest($this->createEvent('/api/auth', 'POST'));

        $this->addToAssertionCount(1);
    }

    public function testApiJobsIsAccepted(): void
    {
        $listener = $this->makeListener($this->makeFactory(1000), $this->makeFactory(1000));

        $listener->onKernelRequest($this->createEvent('/api/jobs'));

        $this->addToAssertionCount(1);
    }

    // Limiter pubblico (/ e /api/auth)

    public function testHomeThrowsWhenPublicLimitExceeded(): void
    {
        $listener = $this->makeListener(
            $this->makeExhaustedFactory(), // pubblico esaurito
            $this->makeFactory(1000),
        );

        $this->expectException(TooManyRequestsHttpException::class);

        $listener->onKernelRequest($this->createEvent('/'));
    }

    public function testApiAuthThrowsWhenPublicLimitExceeded(): void
    {
        $listener = $this->makeListener(
            $this->makeExhaustedFactory(), // pubblico esaurito
            $this->makeFactory(1000),
        );

        $this->expectException(TooManyRequestsHttpException::class);

        $listener->onKernelRequest($this->createEvent('/api/auth', 'POST'));
    }

    // Limiter privato (/api/*)

    public function testApiJobsThrowsWhenPrivateLimitExceeded(): void
    {
        $listener = $this->makeListener(
            $this->makeFactory(1000),
            $this->makeExhaustedFactory(), // privato esaurito
        );

        $this->expectException(TooManyRequestsHttpException::class);

        $listener->onKernelRequest($this->createEvent('/api/jobs'));
    }

    // Verifica che / usi il limiter pubblico e non il privato

    public function testHomeUsesPublicNotPrivateLimiter(): void
    {
        $listener = $this->makeListener(
            $this->makeFactory(1000),      // pubblico: accetta
            $this->makeExhaustedFactory(), // privato: rifiuta
        );

        // Se usasse il privato lancerebbe un'eccezione — non deve
        $listener->onKernelRequest($this->createEvent('/'));

        $this->addToAssertionCount(1);
    }

    // Verifica che /api/jobs usi il limiter privato e non il pubblico

    public function testApiJobsUsesPrivateNotPublicLimiter(): void
    {
        $listener = $this->makeListener(
            $this->makeExhaustedFactory(), // pubblico: rifiuta
            $this->makeFactory(1000),      // privato: accetta
        );

        // Se usasse il pubblico lancerebbe un'eccezione — non deve
        $listener->onKernelRequest($this->createEvent('/api/jobs'));

        $this->addToAssertionCount(1);
    }
}
