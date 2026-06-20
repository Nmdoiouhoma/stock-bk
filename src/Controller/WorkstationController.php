<?php

namespace App\Controller;

use App\Entity\Machine;
use App\Entity\User;
use App\Entity\Workstation;
use App\Repository\WorkstationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/workstations')]
#[IsGranted('ROLE_WORKER')]
final class WorkstationController extends AbstractController
{
    #[Route('', name: 'workstation_index', methods: ['GET'])]
    public function index(WorkstationRepository $workstationRepository): JsonResponse
    {
        $workstations = $workstationRepository->findAll();

        return $this->json(array_map(fn(Workstation $w) => $this->toArray($w), $workstations));
    }

    #[Route('/{id}', name: 'workstation_show', methods: ['GET'])]
    public function show(Workstation $workstation): JsonResponse
    {
        return $this->json($this->toArray($workstation, true));
    }

    #[Route('', name: 'workstation_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
        WorkstationRepository $workstationRepository,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (!isset($data['reference']) || !is_string($data['reference']) || trim($data['reference']) === '') {
            return $this->json(['error' => 'Le champ "reference" est obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        if ($workstationRepository->findOneBy(['reference' => trim($data['reference'])])) {
            return $this->json(['error' => 'Cette référence est déjà utilisée.'], Response::HTTP_CONFLICT);
        }

        if (!isset($data['label']) || !is_string($data['label']) || trim($data['label']) === '') {
            return $this->json(['error' => 'Le champ "label" est obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('capacity', $data) && $data['capacity'] !== null && !is_int($data['capacity'])) {
            return $this->json(['error' => 'Le champ "capacity" doit être un entier ou null.'], Response::HTTP_BAD_REQUEST);
        }

        $workstation = new Workstation();
        $workstation->setReference(trim($data['reference']));
        $workstation->setLabel(trim($data['label']));
        $workstation->setCapacity($data['capacity'] ?? null);

        $errors = $validator->validate($workstation);
        if (count($errors) > 0) {
            return $this->json($this->formatErrors($errors), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em->persist($workstation);
        $em->flush();

        return $this->json($this->toArray($workstation), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'workstation_update', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(
        Workstation $workstation,
        Request $request,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
        WorkstationRepository $workstationRepository,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('reference', $data)) {
            if (!is_string($data['reference']) || trim($data['reference']) === '') {
                return $this->json(['error' => 'Le champ "reference" ne peut pas être vide.'], Response::HTTP_BAD_REQUEST);
            }
            $existing = $workstationRepository->findOneBy(['reference' => trim($data['reference'])]);
            if ($existing !== null && $existing->getId() !== $workstation->getId()) {
                return $this->json(['error' => 'Cette référence est déjà utilisée.'], Response::HTTP_CONFLICT);
            }
            $workstation->setReference(trim($data['reference']));
        }

        if (array_key_exists('label', $data)) {
            if (!is_string($data['label']) || trim($data['label']) === '') {
                return $this->json(['error' => 'Le champ "label" ne peut pas être vide.'], Response::HTTP_BAD_REQUEST);
            }
            $workstation->setLabel(trim($data['label']));
        }

        if (array_key_exists('capacity', $data)) {
            if ($data['capacity'] !== null && !is_int($data['capacity'])) {
                return $this->json(['error' => 'Le champ "capacity" doit être un entier ou null.'], Response::HTTP_BAD_REQUEST);
            }
            $workstation->setCapacity($data['capacity']);
        }

        $errors = $validator->validate($workstation);
        if (count($errors) > 0) {
            return $this->json($this->formatErrors($errors), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em->flush();

        return $this->json($this->toArray($workstation));
    }

    #[Route('/{id}', name: 'workstation_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Workstation $workstation, EntityManagerInterface $em): JsonResponse
    {
        if (!$workstation->getOperations()->isEmpty()) {
            return $this->json(['error' => 'Ce poste de travail est lié à des opérations et ne peut pas être supprimé.'], Response::HTTP_CONFLICT);
        }

        if (!$workstation->getMachines()->isEmpty()) {
            return $this->json(['error' => 'Ce poste de travail est lié à des machines et ne peut pas être supprimé.'], Response::HTTP_CONFLICT);
        }

        $em->remove($workstation);
        $em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function toArray(Workstation $workstation, bool $detail = false): array
    {
        $data = [
            'id'              => $workstation->getId(),
            'reference'       => $workstation->getReference(),
            'label'           => $workstation->getLabel(),
            'capacity'        => $workstation->getCapacity(),
            'machinesCount'   => $workstation->getMachines()->count(),
            'operationsCount' => $workstation->getOperations()->count(),
        ];

        if ($detail) {
            $data['machines'] = array_map(
                fn(Machine $m) => ['id' => $m->getId(), 'reference' => $m->getReference(), 'label' => $m->getLabel()],
                $workstation->getMachines()->toArray()
            );
            $data['qualifiedUsers'] = array_map(
                fn(User $u) => ['id' => $u->getId(), 'firstname' => $u->getFirstname(), 'lastname' => $u->getLastname(), 'email' => $u->getEmail()],
                $workstation->getQualifiedUsers()->toArray()
            );
        }

        return $data;
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
