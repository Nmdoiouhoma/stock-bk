<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\Role;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/users')]
#[IsGranted('ROLE_ADMIN')]
class UserController extends AbstractController
{
    #[Route('', name: 'user_index', methods: ['GET'])]
    public function index(UserRepository $repository): JsonResponse
    {
        $users = $repository->findAll();

        return $this->json(array_map(fn(User $u) => $this->toArray($u), $users));
    }

    #[Route('', name: 'user_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $missing = array_diff(['firstname', 'lastname', 'email', 'password', 'role'], array_keys($data));
        if ($missing) {
            return $this->json(['error' => 'Champs manquants : ' . implode(', ', $missing)], Response::HTTP_BAD_REQUEST);
        }

        $role = Role::tryFrom((string) $data['role']);
        if ($role === null) {
            return $this->json(
                ['error' => 'Rôle invalide. Valeurs acceptées : ' . implode(', ', array_column(Role::cases(), 'value'))],
                Response::HTTP_BAD_REQUEST
            );
        }

        $user = new User();
        $user->setFirstname((string) $data['firstname']);
        $user->setLastname((string) $data['lastname']);
        $user->setEmail((string) $data['email']);
        $user->setRole($role);
        $user->setPassword($hasher->hashPassword($user, (string) $data['password']));

        $em->persist($user);
        $em->flush();

        return $this->json($this->toArray($user), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'user_show', methods: ['GET'])]
    public function show(User $user): JsonResponse
    {
        return $this->json($this->toArray($user));
    }

    #[Route('/{id}', name: 'user_update', methods: ['PUT'])]
    public function update(
        User $user,
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('firstname', $data)) {
            $user->setFirstname((string) $data['firstname']);
        }
        if (array_key_exists('lastname', $data)) {
            $user->setLastname((string) $data['lastname']);
        }
        if (array_key_exists('email', $data)) {
            $user->setEmail((string) $data['email']);
        }
        if (array_key_exists('role', $data)) {
            $role = Role::tryFrom((string) $data['role']);
            if ($role === null) {
                return $this->json(
                    ['error' => 'Rôle invalide. Valeurs acceptées : ' . implode(', ', array_column(Role::cases(), 'value'))],
                    Response::HTTP_BAD_REQUEST
                );
            }
            $user->setRole($role);
        }
        if (array_key_exists('password', $data) && $data['password'] !== null && $data['password'] !== '') {
            $user->setPassword($hasher->hashPassword($user, (string) $data['password']));
        }

        $em->flush();

        return $this->json($this->toArray($user));
    }

    #[Route('/{id}', name: 'user_delete', methods: ['DELETE'])]
    public function delete(User $user, EntityManagerInterface $em): JsonResponse
    {
        $em->remove($user);
        $em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function toArray(User $user): array
    {
        return [
            'id'        => $user->getId(),
            'firstname' => $user->getFirstname(),
            'lastname'  => $user->getLastname(),
            'email'     => $user->getEmail(),
            'role'      => $user->getRole()?->value,
            'roles'     => $user->getRoles(),
        ];
    }
}
