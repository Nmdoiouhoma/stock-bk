<?php

namespace App\Controller;

use App\Entity\PasswordResetToken;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class PasswordController extends AbstractController
{
    #[Route('/forgot-password', name: 'forgot_password', methods: ['POST'])]
    public function forgotPassword(
        Request $request,
        UserRepository $userRepository,
        PasswordResetTokenRepository $tokenRepository,
        EntityManagerInterface $em,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['email']) || !is_string($data['email'])) {
            return $this->json(['error' => 'Le champ "email" est obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        $user = $userRepository->findOneBy(['email' => trim($data['email'])]);

        if ($user === null) {
            return $this->json(['error' => 'Aucun compte associé à cet email.'], Response::HTTP_NOT_FOUND);
        }

        $tokenRepository->deleteExpiredTokens();

        $token = bin2hex(random_bytes(32));
        $expiresAt = new \DateTimeImmutable('+1 hour');

        $resetToken = new PasswordResetToken($user, $token, $expiresAt);
        $em->persist($resetToken);
        $em->flush();

        return $this->json([
            'reset_token' => $token,
            'expires_at'  => $expiresAt->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/reset-password', name: 'reset_password', methods: ['POST'])]
    public function resetPassword(
        Request $request,
        PasswordResetTokenRepository $tokenRepository,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['token']) || !is_string($data['token'])) {
            return $this->json(['error' => 'Le champ "token" est obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        if (!isset($data['password']) || !is_string($data['password']) || trim($data['password']) === '') {
            return $this->json(['error' => 'Le champ "password" est obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        $resetToken = $tokenRepository->findValidToken($data['token']);

        if ($resetToken === null) {
            return $this->json(['error' => 'Token invalide ou expiré.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $resetToken->getUser();
        $user->setPassword($hasher->hashPassword($user, $data['password']));

        $resetToken->markUsed();
        $em->flush();

        return $this->json(['message' => 'Mot de passe mis à jour avec succès.']);
    }
}
