<?php

namespace App\Controller;

use App\Entity\Machine;
use App\Entity\Operation;
use App\Entity\Workstation;
use App\Repository\MachineRepository;
use App\Repository\WorkstationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/machines')]
#[IsGranted('ROLE_WORKER')]
final class MachineController extends AbstractController
{
    #[Route('', name: 'machine_index', methods: ['GET'])]
    public function index(MachineRepository $machineRepository): JsonResponse
    {
        $machines = $machineRepository->findAll();

        return $this->json(array_map(fn(Machine $m) => $this->toArray($m), $machines));
    }

    #[Route('/{id}', name: 'machine_show', methods: ['GET'])]
    public function show(Machine $machine): JsonResponse
    {
        return $this->json($this->toArray($machine, true));
    }

    #[Route('', name: 'machine_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
        MachineRepository $machineRepository,
        WorkstationRepository $workstationRepository,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (!isset($data['reference']) || !is_string($data['reference']) || trim($data['reference']) === '') {
            return $this->json(['error' => 'Le champ "reference" est obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        if ($machineRepository->findOneBy(['reference' => trim($data['reference'])])) {
            return $this->json(['error' => 'Cette référence est déjà utilisée.'], Response::HTTP_CONFLICT);
        }

        if (!isset($data['label']) || !is_string($data['label']) || trim($data['label']) === '') {
            return $this->json(['error' => 'Le champ "label" est obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        $workstations = [];
        if (array_key_exists('workstation_ids', $data)) {
            $result = $this->resolveWorkstations($data['workstation_ids'], $workstationRepository);
            if ($result instanceof JsonResponse) {
                return $result;
            }
            $workstations = $result;
        }

        $machine = new Machine();
        $machine->setReference(trim($data['reference']));
        $machine->setLabel(trim($data['label']));
        foreach ($workstations as $ws) {
            $machine->addWorkstation($ws);
        }

        $errors = $validator->validate($machine);
        if (count($errors) > 0) {
            return $this->json($this->formatErrors($errors), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em->persist($machine);
        $em->flush();

        return $this->json($this->toArray($machine), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'machine_update', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(
        Machine $machine,
        Request $request,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
        MachineRepository $machineRepository,
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
            $existing = $machineRepository->findOneBy(['reference' => trim($data['reference'])]);
            if ($existing !== null && $existing->getId() !== $machine->getId()) {
                return $this->json(['error' => 'Cette référence est déjà utilisée.'], Response::HTTP_CONFLICT);
            }
            $machine->setReference(trim($data['reference']));
        }

        if (array_key_exists('label', $data)) {
            if (!is_string($data['label']) || trim($data['label']) === '') {
                return $this->json(['error' => 'Le champ "label" ne peut pas être vide.'], Response::HTTP_BAD_REQUEST);
            }
            $machine->setLabel(trim($data['label']));
        }

        if (array_key_exists('workstation_ids', $data)) {
            $result = $this->resolveWorkstations($data['workstation_ids'], $workstationRepository);
            if ($result instanceof JsonResponse) {
                return $result;
            }
            foreach ($machine->getWorkstations()->toArray() as $ws) {
                $machine->removeWorkstation($ws);
            }
            foreach ($result as $ws) {
                $machine->addWorkstation($ws);
            }
        }

        $errors = $validator->validate($machine);
        if (count($errors) > 0) {
            return $this->json($this->formatErrors($errors), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em->flush();
        $em->refresh($machine);

        return $this->json($this->toArray($machine));
    }

    #[Route('/{id}', name: 'machine_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Machine $machine, EntityManagerInterface $em): JsonResponse
    {
        if (!$machine->getOperations()->isEmpty()) {
            return $this->json(['error' => 'Cette machine est liée à des opérations et ne peut pas être supprimée.'], Response::HTTP_CONFLICT);
        }

        $em->remove($machine);
        $em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function resolveWorkstations(mixed $ids, WorkstationRepository $repo): array|JsonResponse
    {
        if (!is_array($ids)) {
            return $this->json(['error' => 'Le champ "workstation_ids" doit être un tableau d\'entiers.'], Response::HTTP_BAD_REQUEST);
        }

        $workstations = [];
        foreach ($ids as $id) {
            if (!is_int($id)) {
                return $this->json(['error' => 'Chaque identifiant dans "workstation_ids" doit être un entier.'], Response::HTTP_BAD_REQUEST);
            }
            $ws = $repo->find($id);
            if ($ws === null) {
                return $this->json(['error' => sprintf('Poste de travail %d introuvable.', $id)], Response::HTTP_NOT_FOUND);
            }
            $workstations[] = $ws;
        }

        return $workstations;
    }

    private function toArray(Machine $machine, bool $detail = false): array
    {
        $data = [
            'id'               => $machine->getId(),
            'reference'        => $machine->getReference(),
            'label'            => $machine->getLabel(),
            'workstations'     => array_map(
                fn(Workstation $ws) => ['id' => $ws->getId(), 'reference' => $ws->getReference(), 'label' => $ws->getLabel()],
                $machine->getWorkstations()->toArray()
            ),
            'operationsCount'  => $machine->getOperations()->count(),
        ];

        if ($detail) {
            $data['operations'] = array_map(
                fn(Operation $op) => ['id' => $op->getId(), 'rank' => $op->getRank(), 'label' => $op->getLabel()],
                $machine->getOperations()->toArray()
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
