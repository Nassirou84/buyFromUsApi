<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\ScrapperService;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function in_array;

use const FILTER_VALIDATE_URL;
use const PHP_URL_SCHEME;

final class OnDemandScrapeController extends AbstractController
{
    /**
     * ROLE_ADMIN only: every call triggers a paid scrape on the scrapper service.
     */
    #[Route('/api/on-demand-scrape', name: 'app_on_demand_scrape', methods: ['GET'])]
    public function __invoke(Request $request, ScrapperService $scrapperService): JsonResponse
    {
        $user = $this->getUser();
        if (!$user || !in_array(User::ROLE_ADMIN, $user->getRoles(), true)) {
            return $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $url = (string) $request->query->get('url');

        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return $this->json(['error' => 'Invalid url parameter'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $scrapperService->scrape($url);
        } catch (RuntimeException) {
            return $this->json(['error' => 'Scrapper service unavailable'], Response::HTTP_BAD_GATEWAY);
        }

        return $this->json($result['data'], $result['status']);
    }
}
