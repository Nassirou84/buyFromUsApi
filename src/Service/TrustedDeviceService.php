<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\TrustedDevice;
use App\Entity\User;
use App\Repository\TrustedDeviceRepository;
use Doctrine\ORM\EntityManagerInterface;

class TrustedDeviceService
{
    public function __construct(
        private EntityManagerInterface $entityManagerInterface,
        private TrustedDeviceRepository $trustedDeviceRepository,
    ) {
    }

    public function createTrustedDeviceEntry(array $data, ?User $user): TrustedDevice
    {
        $trustedDevice = new TrustedDevice();
        $trustedDevice->setVisitorId(self::hashVisitorId($data['visitorId']));
        $trustedDevice->setLanguage($data['language'] ?? null);
        $trustedDevice->setPlatform($data['platform'] ?? null);
        $trustedDevice->setTimeZone($data['timeZone'] ?? null);
        $trustedDevice->setUserAgent($data['userAgent'] ?? null);
        $trustedDevice->setUser($user);

        $this->entityManagerInterface->persist($trustedDevice);
        $this->entityManagerInterface->flush();

        return $trustedDevice;
    }

    public function findTrustedDeviceByVisitorId(string $visitorId): ?TrustedDevice
    {
        $hashedVisitorId = self::hashVisitorId($visitorId);

        return $this->trustedDeviceRepository->findOneBy(['visitorId' => $hashedVisitorId]);
    }

    public function isDeviceTrusted(string $visitorId, ?User $user): bool
    {
        $trustedDevice = $this->findTrustedDeviceByVisitorId($visitorId);

        return null !== $trustedDevice && $trustedDevice->getUser() === $user;
    }

    public function removeTrustedDevice(TrustedDevice $trustedDevice): void
    {
        $this->entityManagerInterface->remove($trustedDevice);
        $this->entityManagerInterface->flush();
    }

    public static function hashVisitorId($visitorId): string
    {
        return hash('sha256', $visitorId);
    }
}
