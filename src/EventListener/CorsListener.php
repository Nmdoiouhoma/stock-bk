<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class CorsListener implements EventSubscriberInterface
{
    private const ALLOWED_ORIGINS = [
        'https://stock-ft.onrender.com',
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ];

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST  => ['onKernelRequest',  250],
            KernelEvents::RESPONSE => ['onKernelResponse', 250],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $origin  = $request->headers->get('Origin', '');

        if ($request->getMethod() === 'OPTIONS') {
            $response = new Response('', 204);
            $this->addCorsHeaders($response, $origin);
            $event->setResponse($response);
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $origin = $event->getRequest()->headers->get('Origin', '');
        $this->addCorsHeaders($event->getResponse(), $origin);
    }

    private function addCorsHeaders(Response $response, string $origin): void
    {
        if (!in_array($origin, self::ALLOWED_ORIGINS, true)) {
            return;
        }

        $response->headers->set('Access-Control-Allow-Origin',  $origin);
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');
        $response->headers->set('Access-Control-Max-Age',       '3600');
    }
}
