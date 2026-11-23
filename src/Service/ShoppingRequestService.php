<?php

namespace App\Service;

use App\Entity\ShoppingRequest;
use App\Message\NewShoppingRequestMessage;
use App\Service\GcsUploaderService;
use App\Service\UniqUidGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class ShoppingRequestService
{
  public function __construct(
    private GcsUploaderService $gcsUploaderService,
    private EntityManagerInterface $entityManagerInterface,
    private UniqUidGenerator $uniqUidGenerator,
    private TokenStorageInterface $tokenStorageInterface,
    private MessageBusInterface $messageBusInterface,
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

    $uniqueId = $this->uniqUidGenerator->generateUniqueUid(ShoppingRequest::class);

    foreach ($files as $file) {
      $uploadedImage = $this->gcsUploaderService->uploadShoppingRequestImage($file, $uniqueId);
      $images[] = $uploadedImage;
    }

    $shoppingRequest->setImages($images);
    $shoppingRequest->setStatus(ShoppingRequest::STATUS_SUBMITTED);
    $shoppingRequest->setUid($uniqueId);
    $shoppingRequest->setAccessToken(str_shuffle(sha1($uniqueId . random_bytes(16))));
    $this->entityManagerInterface->persist($shoppingRequest);
    $this->entityManagerInterface->flush();

    $this->messageBusInterface->dispatch(new NewShoppingRequestMessage(
      $shoppingRequest->getEmail(),
      $shoppingRequest->getTitle(),
      $shoppingRequest->getDescription(),
      $shoppingRequest->getFullName(),
      $shoppingRequest->getPhone(),
      $shoppingRequest->getAddress(),
      $shoppingRequest->getCreatedAt()->format('d-m-Y H:i:s'),
      $shoppingRequest->getUid(),
      $shoppingRequest->getPreferredContact(),
      $shoppingRequest->getQuantity(),
      $shoppingRequest->getAccessToken()
    ));

    return $shoppingRequest;
  }
}