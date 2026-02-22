<?php
// src/EventListener/JwtListener.php

namespace App\EventListener;

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class JwtListener
{
    public function __construct(
        private HttpClientInterface $http,
        private CacheItemPoolInterface $cache,
        private string $keycloakUrl,
        private string $keycloakRealm,
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api/') 
            || $request->getPathInfo() === '/api/auth') {
            return;
        }

        $authHeader = $request->headers->get('Authorization', '');
        $token = str_replace('Bearer ', '', $authHeader);

        if (!$token) {
            $event->setResponse(new JsonResponse(['error' => ['code' => 'MISSING_TOKEN', 'message' => 'No token']], 401));
            return;
        }

        try {
            $jwks = $this->getJwks();
            $decoded = JWT::decode($token, JWK::parseKeySet($jwks));
            $request->attributes->set('jwt_payload', (array) $decoded);
        } catch (\Exception $e) {
            $event->setResponse(new JsonResponse(['error' => ['code' => 'INVALID_TOKEN', 'message' => 'Invalid token']], 401));
        }
    }

    private function getJwks(): array
    {
        $item = $this->cache->getItem('keycloak_jwks');
        
        if (!$item->isHit()) {
            $url = "{$this->keycloakUrl}/realms/{$this->keycloakRealm}/protocol/openid-connect/certs";
            $jwks = $this->http->request('GET', $url)->toArray();
            $item->set($jwks)->expiresAfter(3600);
            $this->cache->save($item);
        }

        return $item->get();
    }
}