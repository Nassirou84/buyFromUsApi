<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserLoginHistoryRepository;
use Symfony\Component\HttpFoundation\Request;

class DeviceDetectionService
{
  public function __construct(
    private UserLoginHistoryRepository $userLoginHistoryRepository
  ) {
  }

  public function detectNewDevice(Request $request, User $user): bool
  {
    $userAgent = $request->headers->get('User-Agent');
    if (empty($userAgent)) {
      return true;
    }

    return !$this->userLoginHistoryRepository->isKnownDevice($user, $userAgent);
  }

  public function recordLoginAttempt(Request $request, User $user): void
  {
    $userAgent = $request->headers->get('User-Agent');
    $ipAddress = $request->getClientIp();

    if (empty($userAgent)) {
      return;
    }

    $existingDevice = $this->userLoginHistoryRepository->findDeviceByUserAgent($user, $userAgent);

    if ($existingDevice) {
      $this->userLoginHistoryRepository->updateLastUsed($user, $userAgent);
    } else {
      $this->userLoginHistoryRepository->createDeviceEntry($user, $userAgent, $ipAddress);
    }
  }

  public function trustCurrentDevice(Request $request, User $user): void
  {
    $userAgent = $request->headers->get('User-Agent');

    if (!empty($userAgent)) {
      $this->userLoginHistoryRepository->trustDevice($user, $userAgent);
    }
  }

  public function getDeviceType(string $userAgent): string
  {
    if (preg_match('/mobile|android|iphone|ipad|phone/i', $userAgent)) {
      return 'mobile';
    } elseif (preg_match('/tablet|ipad|playbook|silk/i', $userAgent)) {
      return 'tablet';
    } else {
      return 'desktop';
    }
  }

  public function getDeviceSummary(string $userAgent): array
  {
    return [
      'type' => $this->getDeviceType($userAgent),
      'browser' => $this->detectBrowser($userAgent),
      'os' => $this->detectOs($userAgent)
    ];
  }

  private function detectBrowser(string $userAgent): string
  {
    if (strpos($userAgent, 'Chrome') !== false)
      return 'Chrome';
    if (strpos($userAgent, 'Firefox') !== false)
      return 'Firefox';
    if (strpos($userAgent, 'Safari') !== false)
      return 'Safari';
    if (strpos($userAgent, 'Edge') !== false)
      return 'Edge';
    return 'Unknown';
  }

  private function detectOS(string $userAgent): string
  {
    if (strpos($userAgent, 'Windows') !== false)
      return 'Windows';
    if (strpos($userAgent, 'Mac') !== false)
      return 'macOS';
    if (strpos($userAgent, 'Linux') !== false)
      return 'Linux';
    if (strpos($userAgent, 'Android') !== false)
      return 'Android';
    if (strpos($userAgent, 'iOS') !== false || strpos($userAgent, 'iPhone') !== false)
      return 'iOS';
    return 'Unknown';
  }
}