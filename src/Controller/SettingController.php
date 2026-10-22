<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Setting;
use App\Service\SettingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class SettingController extends AbstractController
{
    /**
     * Public: settings the storefront needs before anyone logs in.
     * Returns {"global": {name: value}, "front_end": {name: value}}.
     */
    #[Route('/api/public-settings', name: 'app_settings_public', methods: ['GET'])]
    public function public(SettingService $settingService): JsonResponse
    {
        return $this->noStore($settingService->getByScopes([
            Setting::SCOPE_GLOBAL,
            Setting::SCOPE_FRONT_END,
        ]));
    }

    /**
     * Authenticated users only (ROLE_USER): returns {"user": {name: value}}.
     */
    #[Route('/api/user-settings', name: 'app_settings_user', methods: ['GET'])]
    public function user(SettingService $settingService): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        return $this->noStore($settingService->getByScopes([Setting::SCOPE_USER]));
    }

    /**
     * @param array<string, array<string, string|null>> $settings
     */
    private function noStore(array $settings): JsonResponse
    {
        // (object) keeps an empty scope serialized as {} rather than []
        $response = $this->json(array_map(static fn (array $values): object => (object) $values, $settings));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
