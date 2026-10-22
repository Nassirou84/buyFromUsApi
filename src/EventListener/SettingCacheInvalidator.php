<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Setting;
use App\Service\SettingService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Drops the settings cache once a flush that touched a Setting has been committed. Invalidating in
 * postFlush (not onFlush) avoids a concurrent request re-caching the old values before the commit.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class SettingCacheInvalidator
{
    private bool $dirty = false;

    public function __construct(private readonly SettingService $settingService)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        $changed = [
            ...$uow->getScheduledEntityInsertions(),
            ...$uow->getScheduledEntityUpdates(),
            ...$uow->getScheduledEntityDeletions(),
        ];

        foreach ($changed as $entity) {
            if ($entity instanceof Setting) {
                $this->dirty = true;

                return;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (!$this->dirty) {
            return;
        }

        $this->dirty = false;
        $this->settingService->clearCache();
    }
}
