<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Adminer lives in public/database-adminer/index.php, outside the kernel. Apache serves the
 * directory URL itself in production, but the Symfony CLI server only serves existing files and
 * sends directory URLs here, which would 404. Access rule: public redirect only; Adminer itself is
 * gated by the proxy's basic auth and its own MySQL login (see docker/Caddyfile).
 */
final class DatabaseAdminerController extends AbstractController
{
    #[Route('/database-adminer', name: 'app_database_adminer', methods: ['GET'])]
    public function index(Request $request): RedirectResponse
    {
        return new RedirectResponse($request->getBasePath() . '/database-adminer/index.php');
    }
}
