<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 1000)]
#[AsEventListener(event: KernelEvents::RESPONSE, priority: 1000)]
class CorsListener
{
  public function onKernelRequest(RequestEvent $event): void
  {
    $request = $event->getRequest();
    if ($request->getMethod() === 'OPTIONS') {
      $response = new \Symfony\Component\HttpFoundation\Response();
      $response->headers->set('Access-Control-Allow-Origin', '*');
      $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
      $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
      $response->headers->set('Access-Control-Max-Age', '3600');
      $event->setResponse($response);
    }
  }

  public function onKernelResponse(ResponseEvent $event): void
  {
    $response = $event->getResponse();
    $request = $event->getRequest();

    // Only add CORS headers for API routes
    if (str_starts_with($request->getPathInfo(), '/api/')) {
      $response->headers->set('Access-Control-Allow-Origin', '*');
      $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
      $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
      $response->headers->set('Access-Control-Max-Age', '3600');
    }
  }
}