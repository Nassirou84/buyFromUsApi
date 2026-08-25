<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use App\Service\GcsUploaderService;
use App\Entity\ShoppingRequest;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\UniqUidGenerator;

class ShoppingRequestService
{
  public function __construct(
    private GcsUploaderService $gcsUploaderService,
    private EntityManagerInterface $entityManagerInterface,
    private UniqUidGenerator $uniqUidGenerator,
    private TokenStorageInterface $tokenStorageInterface,
  ) {
  }

  public function createShoppingRequest(
    Request $request,
  ): ShoppingRequest {
    $files = $request->files;

    $shoppingRequest = new ShoppingRequest();
    $shoppingRequest->setTitle((string) $request->request->get('title'));
    $shoppingRequest->setDescription((string) $request->request->get('description'));
    $shoppingRequest->setQuantity((int) $request->request->get('quantity'));
    $shoppingRequest->setFullName((string) $request->request->get('fullName'));
    $shoppingRequest->setEmail((string) $request->request->get('email'));
    $shoppingRequest->setPhone((string) $request->request->get('phone'));
    $shoppingRequest->setAddress((string) $request->request->get('address'));
    $shoppingRequest->setPreferredContact((string) $request->request->get('preferredContact'));

    $token = $this->tokenStorageInterface->getToken();
    if ($token) {
      $currentUser = $token->getUser();
      if ($currentUser instanceof \App\Entity\User) {
        $shoppingRequest->setCustomer($currentUser);
      }
    }

    $images = [];

    foreach ($files as $file) {
      $uploadedImage = $this->gcsUploaderService->upload($file);
      $images[] = $uploadedImage;
    }
    $uniqueId = $this->uniqUidGenerator->generateUniqueUid(ShoppingRequest::class);

    $shoppingRequest->setImages($images);
    $shoppingRequest->setStatus(ShoppingRequest::STATUS_SUBMITTED);
    $shoppingRequest->setUid($uniqueId);
    $this->entityManagerInterface->persist($shoppingRequest);
    $this->entityManagerInterface->flush();
    return $shoppingRequest;
  }
}