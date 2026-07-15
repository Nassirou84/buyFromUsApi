<?php
// src/Controller/Api/AuthController.php
namespace App\Controller;

use App\Security\GoogleAuthenticator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth')]
class AuthController extends AbstractController
{
  #[Route('/google', name: 'api_google_login', methods: ['POST'])]
  public function googleLogin(Request $request, GoogleAuthenticator $authenticator): Response
  {
    // The authenticator handles everything
    // This controller is just a placeholder
    return new Response('', 200);
  }
}