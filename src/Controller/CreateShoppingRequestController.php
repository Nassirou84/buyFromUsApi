<?php

namespace App\Controller;

use App\Service\FileUploader;
use App\Service\UniqUidGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class CreateShoppingRequestController extends AbstractController
{
    public function __invoke(
        Request $request,
        FileUploader $fileUploader,
        EntityManagerInterface $entityManagerInterface,
        UniqUidGenerator $uniqUidGenerator,
        TokenStorageInterface $tokenStorageInterface
    ): JsonResponse {
        //Get Image from the request
        $files = $request->files;
        $pictureDirectory = $this->getParameter('picture_directory');
        $siteUrl = $this->getParameter('site_url');
        $basePath = $this->getParameter('base_path_dir');

        $images = [];

        $shoppingRequest = new \App\Entity\ShoppingRequest();
        if (
            $request->request->has('title') && $request->request->has('description') && $request->request->has('quantity') && $request->request->has('fullName') && $request->request->has('email') && $request->request->has('phone') &&
            $request->request->has('address') &&
            $request->request->has('preferredContact')
        ) {
            $shoppingRequest->setTitle((string) $request->request->get('title'));
            $shoppingRequest->setDescription((string) $request->request->get('description'));
            $shoppingRequest->setQuantity((int) $request->request->get('quantity'));
            $shoppingRequest->setFullName((string) $request->request->get('fullName'));
            $shoppingRequest->setEmail((string) $request->request->get('email'));
            $shoppingRequest->setPhone((string) $request->request->get('phone'));
            $shoppingRequest->setAddress((string) $request->request->get('address'));
            $shoppingRequest->setPreferredContact((string) $request->request->get('preferredContact'));
        } else {
            throw new \Exception('Veuillez remplir tous les champs obligatoires.');
        }

        $images = [];

        foreach ($files as $file) {
            $uploadedIcon = $fileUploader->uploadPicture($file, $basePath, $pictureDirectory);
            $images[] = $uploadedIcon;
        }

        if (count($images) === 0) {
            throw new \Exception('Veuillez envoyer au moins une image.');
        }

        $token = $tokenStorageInterface->getToken();
        if ($token) {
            $currentUser = $token->getUser();
            $shoppingRequest->setCustomer($currentUser);
        }

        $shoppingRequest->setImages($images);
        $shoppingRequest->setStatus(\App\Entity\ShoppingRequest::STATUS_SUBMITTED);
        $shoppingRequest->setUid($uniqUidGenerator->generateUniqueShoppingRequestUid());
        $entityManagerInterface->persist($shoppingRequest);
        $entityManagerInterface->flush();

        return $this->json($shoppingRequest, 201, [], ['groups' => ['shopping_request:read']]);
    }
}