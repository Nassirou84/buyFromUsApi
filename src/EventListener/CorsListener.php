<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function in_array;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 1000)]
#[AsEventListener(event: KernelEvents::RESPONSE, priority: 1000)]
class CorsListener
{
    private array $allowedOrigins = [
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ];

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $origin = $request->headers->get('Origin');

        if ('OPTIONS' === $request->getMethod() && in_array($origin, $this->allowedOrigins, true)) {
            $response = new Response();
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
            $response->headers->set('Access-Control-Max-Age', '3600');

            $event->setResponse($response);
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        $origin = $request->headers->get('Origin');

        if (str_starts_with($request->getPathInfo(), '/api/') && in_array($origin, $this->allowedOrigins, true)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
            $response->headers->set('Access-Control-Max-Age', '3600');
            $response->headers->set('Vary', 'Origin');
        }
    }
}
