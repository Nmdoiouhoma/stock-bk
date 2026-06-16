<?php

namespace App\Controller;

use App\Entity\Routing;
use App\Enum\PieceType;
use App\Enum\Role;
use App\Repository\PartRepository;
use App\Repository\RoutingRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/routings')]
#[IsGranted('ROLE_WORKER')]
final class RoutingController extends AbstractController
{
    #[Route('', name: 'routing_index', methods: ['GET'])]
    public function index(RoutingRepository $routingRepository): JsonResponse
    {
        $routings = $routingRepository->findAll();

        return $this->json(array_map(fn(Routing $g) => $this->toArray($g), $routings));
    }

    #[Route('/{id}', name: 'routing_show', methods: ['GET'])]
    public function show(Routing $routing): JsonResponse
    {
        return $this->json($this->toArray($routing));
    }

    #[Route('', name: 'routing_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
        PartRepository $partRepository,
        UserRepository $userRepository,
        RoutingRepository $routingRepository,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (!isset($data['reference']) || !is_string($data['reference']) || trim($data['reference']) === '') {
            return $this->json(['error' => 'Le champ "reference" est obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        if ($routingRepository->findOneBy(['reference' => trim($data['reference'])])) {
            return $this->json(['error' => 'Cette référence est déjà utilisée.'], Response::HTTP_CONFLICT);
        }

        if (!isset($data['label']) || !is_string($data['label']) || trim($data['label']) === '') {
            return $this->json(['error' => 'Le champ "label" est obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        if (!isset($data['partId']) || !is_int($data['partId'])) {
            return $this->json(['error' => 'Le champ "partId" est obligatoire et doit être un entier.'], Response::HTTP_BAD_REQUEST);
        }

        $part = $partRepository->find($data['partId']);
        if ($part === null) {
            return $this->json(['error' => 'La pièce spécifiée n\'existe pas.'], Response::HTTP_NOT_FOUND);
        }

        if (!in_array($part->getType(), [PieceType::Finished, PieceType::Intermediate], true)) {
            return $this->json(['error' => 'La pièce doit être de type fabriquée (Finished ou Intermediate).'], Response::HTTP_BAD_REQUEST);
        }

        if (!$part->getRoutings()->isEmpty()) {
            return $this->json(['error' => 'Cette pièce possède déjà une gamme.'], Response::HTTP_CONFLICT);
        }

        if (!isset($data['supervisorId']) || !is_int($data['supervisorId'])) {
            return $this->json(['error' => 'Le champ "supervisorId" est obligatoire et doit être un entier.'], Response::HTTP_BAD_REQUEST);
        }

        $supervisor = $userRepository->find($data['supervisorId']);
        if ($supervisor === null) {
            return $this->json(['error' => 'Le responsable spécifié n\'existe pas.'], Response::HTTP_NOT_FOUND);
        }

        if ($supervisor->getRole() !== Role::Supervisor) {
            return $this->json(['error' => 'L\'utilisateur spécifié n\'a pas le rôle superviseur.'], Response::HTTP_BAD_REQUEST);
        }

        $routing = new Routing();
        $routing->setReference(trim($data['reference']));
        $routing->setLabel(trim($data['label']));
        $routing->setPart($part);
        $routing->setSupervisor($supervisor);

        $errors = $validator->validate($routing);
        if (count($errors) > 0) {
            return $this->json($this->formatErrors($errors), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em->persist($routing);
        $em->flush();

        return $this->json($this->toArray($routing), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'routing_update', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(
        Routing $routing,
        Request $request,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
        PartRepository $partRepository,
        UserRepository $userRepository,
        RoutingRepository $routingRepository,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('reference', $data)) {
            if (!is_string($data['reference']) || trim($data['reference']) === '') {
                return $this->json(['error' => 'Le champ "reference" ne peut pas être vide.'], Response::HTTP_BAD_REQUEST);
            }
            $existing = $routingRepository->findOneBy(['reference' => trim($data['reference'])]);
            if ($existing !== null && $existing->getId() !== $routing->getId()) {
                return $this->json(['error' => 'Cette référence est déjà utilisée.'], Response::HTTP_CONFLICT);
            }
            $routing->setReference(trim($data['reference']));
        }
        if (array_key_exists('label', $data)) {
            if (!is_string($data['label']) || trim($data['label']) === '') {
                return $this->json(['error' => 'Le champ "label" ne peut pas être vide.'], Response::HTTP_BAD_REQUEST);
            }
            $routing->setLabel(trim($data['label']));
        }
        if (array_key_exists('partId', $data)) {
            if (!is_int($data['partId'])) {
                return $this->json(['error' => 'Le champ "partId" doit être un entier.'], Response::HTTP_BAD_REQUEST);
            }
            $part = $partRepository->find($data['partId']);
            if ($part === null) {
                return $this->json(['error' => 'La pièce spécifiée n\'existe pas.'], Response::HTTP_NOT_FOUND);
            }
            if (!in_array($part->getType(), [PieceType::Finished, PieceType::Intermediate], true)) {
                return $this->json(['error' => 'La pièce doit être de type fabriquée (Finished ou Intermediate).'], Response::HTTP_BAD_REQUEST);
            }
            $existingRoutings = $part->getRoutings()->filter(fn(Routing $r) => $r->getId() !== $routing->getId());
            if (!$existingRoutings->isEmpty()) {
                return $this->json(['error' => 'Cette pièce possède déjà une gamme.'], Response::HTTP_CONFLICT);
            }
            $routing->setPart($part);
        }
        if (array_key_exists('supervisorId', $data)) {
            if (!is_int($data['supervisorId'])) {
                return $this->json(['error' => 'Le champ "supervisorId" doit être un entier.'], Response::HTTP_BAD_REQUEST);
            }
            $supervisor = $userRepository->find($data['supervisorId']);
            if ($supervisor === null) {
                return $this->json(['error' => 'Le responsable spécifié n\'existe pas.'], Response::HTTP_NOT_FOUND);
            }
            if ($supervisor->getRole() !== Role::Supervisor) {
                return $this->json(['error' => 'L\'utilisateur spécifié n\'a pas le rôle superviseur.'], Response::HTTP_BAD_REQUEST);
            }
            $routing->setSupervisor($supervisor);
        }

        $errors = $validator->validate($routing);
        if (count($errors) > 0) {
            return $this->json($this->formatErrors($errors), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em->flush();

        return $this->json($this->toArray($routing));
    }

    #[Route('/{id}', name: 'routing_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Routing $routing, EntityManagerInterface $em): JsonResponse
    {
        $em->remove($routing);
        $em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function toArray(Routing $routing): array
    {
        return [
            'id'              => $routing->getId(),
            'reference'       => $routing->getReference(),
            'label'           => $routing->getLabel(),
            'part'            => $routing->getPart() ? [
                'id'        => $routing->getPart()->getId(),
                'reference' => $routing->getPart()->getReference(),
                'label'     => $routing->getPart()->getLabel(),
            ] : null,
            'supervisor'      => $routing->getSupervisor() ? [
                'id'        => $routing->getSupervisor()->getId(),
                'firstname' => $routing->getSupervisor()->getFirstname(),
                'lastname'  => $routing->getSupervisor()->getLastname(),
            ] : null,
            'operationsCount' => $routing->getOperations()->count(),
        ];
    }

    private function formatErrors(\Symfony\Component\Validator\ConstraintViolationListInterface $errors): array
    {
        $formatted = [];
        foreach ($errors as $error) {
            $formatted[] = [
                'field'   => $error->getPropertyPath(),
                'message' => $error->getMessage(),
            ];
        }

        return ['errors' => $formatted];
    }
}
