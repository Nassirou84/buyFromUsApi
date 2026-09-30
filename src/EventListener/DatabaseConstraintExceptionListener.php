<?php

declare(strict_types=1);

namespace App\EventListener;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
class DatabaseConstraintExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        $previous = $throwable->getPrevious();

        if (!$throwable instanceof ForeignKeyConstraintViolationException
            && !$previous instanceof ForeignKeyConstraintViolationException
        ) {
            return;
        }

        $event->setResponse(new JsonResponse([
            'message' => 'This item cannot be deleted because other records still reference it.',
        ], 409));
    }
}
