<?php

namespace App\Tests\Stub;

use Symfony\Component\HttpKernel\Event\RequestEvent;

class JwtListenerStub
{
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api/')
            || $request->getPathInfo() === '/api/auth') {
            return;
        }

        $request->attributes->set('jwt_payload', ['sub' => 'test-user']);
    }
}
