<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\HttpFoundation\Request;

class RateLimiterListener implements EventSubscriberInterface
{
    public function __construct(
        private RateLimiterFactory $apiPublicLimiter,
        private RateLimiterFactory $apiPrivateLimiter
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // per brevità a scopo di demo metto tutto qui, diversamente ci vorrebbe un limiter per ogni tipo di api
        // home (pubblica)
        if ($path === '/') {
            $limiter = $this->apiPublicLimiter->create($request->getClientIp());
        }
        // altre api
        elseif (str_starts_with($path, '/api')) {
            if ($path === '/api/auth') {
                $limiter = $this->apiPublicLimiter->create($request->getClientIp());
            } else {
                $limiter = $this->apiPrivateLimiter->create($request->getClientIp());
            }
        } else {
            return; 
        }

        $limit = $limiter->consume(1);

        if (!$limit->isAccepted()) {
        	// corretta
            //$seconds = $limit->getRetryAfter()->getTimestamp() - time(); 
            // suddivisa
            $retryAfter = $limit->getRetryAfter(); 
            $timestamp = $retryAfter->getTimestamp(); 
            $now = time();
            $seconds = $timestamp - $now;
            //dd($now);
            throw new TooManyRequestsHttpException(
                $seconds, 
                'Troppe richieste. Riprova tra ' . $seconds . ' secondi.'
            );
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 10]
        ];
    }
}