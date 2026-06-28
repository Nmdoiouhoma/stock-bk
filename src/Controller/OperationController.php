<?php

namespace App\Controller;

use App\Entity\Operation;
use App\Entity\Routing;
use App\Repository\MachineRepository;
use App\Repository\OperationRepository;
use App\Repository\RoutingRepository;
use App\Repository\WorkstationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/operations')]
final class OperationController extends AbstractController
{
    #[Route('', name: 'operation_index', methods: ['GET'])]
    #[IsGranted('ROLE_WORKER')]
    public function index(OperationRepository $operationRepository): JsonResponse
    {
        $operations = $operationRepository->findBy([], ['rank' => 'ASC']);

        return $this->json(array_map(fn(Operation $o) => $this->toArray($o), $operations));
    }

    #[Route('/{id}', name: 'operation_show', methods: ['GET'])]
    #[IsGranted('ROLE_WORKER')]
    public function show(Operation $operation): JsonResponse
    {
        return $this->json($this->toArray($operation));
    }

    #[Route('', name: 'operation_create', methods: ['POST'])]
    #[IsGranted('ROLE_SUPERVISOR')]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
        RoutingRepository $routingRepository,
        WorkstationRepository $workstationRepository,
        OperationRepository $operationRepository,
        MachineRepository $machineRepository
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (!isset($data['label']) || !is_string($data['label']) || trim($data['label']) === '') {
            return $this->json(['error' => 'Le champ "label" est obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        if (!isset($data['unitTime']) || !is_numeric($data['unitTime'])) {
            return $this->json(['error' => 'Le champ "unitTime" est obligatoire et doit être un nombre.'], Response::HTTP_BAD_REQUEST);
        }

        if (!isset($data['routingIds']) || !is_array($data['routingIds']) || count($data['routingIds']) === 0) {
            return $this->json(['error' => 'Le champ "routingIds" est obligatoire et doit être un tableau non vide d\'entiers.'], Response::HTTP_BAD_REQUEST);
        }

        $routings = [];
        foreach ($data['routingIds'] as $routingId) {
            if (!is_int($routingId)) {
                return $this->json(['error' => 'Chaque élément de "routingIds" doit être un entier.'], Response::HTTP_BAD_REQUEST);
            }
            $routing = $routingRepository->find($routingId);
            if ($routing === null) {
                return $this->json(['error' => "La gamme $routingId n'existe pas."], Response::HTTP_NOT_FOUND);
            }
            $routings[] = $routing;
        }

        if (!isset($data['workstationId']) || !is_int($data['workstationId'])) {
            return $this->json(['error' => 'Le champ "workstationId" est obligatoire et doit être un entier.'], Response::HTTP_BAD_REQUEST);
        }

        $workstation = $workstationRepository->find($data['workstationId']);
        if ($workstation === null) {
            return $this->json(['error' => 'La poste de travail spécifiée n\'existe pas.'], Response::HTTP_NOT_FOUND);
        }

        if (!isset($data['machineId']) || !is_int($data['machineId'])) {
            return $this->json(['error' => 'Le champ "machineId" est obligatoire et doit être un entier.'], Response::HTTP_BAD_REQUEST);
        }

        $machine = $machineRepository->find($data['machineId']);
        if ($machine === null) {
            return $this->json(['error' => 'La machine spécifiée n\'existe pas.'], Response::HTTP_NOT_FOUND);
        }

        if (!$workstation->getMachines()->contains($machine)) {
            return $this->json(['error' => 'Cette machine n\'est pas compatible avec ce poste de travail.'], Response::HTTP_BAD_REQUEST);
        }

        $max  = $operationRepository->getMaxRankForRouting($routings[0]);
        $rank = ($max === null) ? 1 : ($max + 1);

        $operation = new Operation();
        $operation->setLabel(trim($data['label']));
        $operation->setUnitTime((float) $data['unitTime']);
        $operation->setWorkstation($workstation);
        $operation->setMachine($machine);
        $operation->setRank($rank);

        foreach ($routings as $routing) {
            $operation->addRouting($routing);
        }

        $errors = $validator->validate($operation);
        if (count($errors) > 0) {
            return $this->json($this->formatErrors($errors), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em->persist($operation);
        $em->flush();

        return $this->json($this->toArray($operation), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'operation_update', methods: ['PUT'])]
    #[IsGranted('ROLE_SUPERVISOR')]
    public function update(
        Operation $operation,
        Request $request,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
        WorkstationRepository $workstationRepository,
        MachineRepository $machineRepository
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('label', $data)) {
            if (!is_string($data['label']) || trim($data['label']) === '') {
                return $this->json(['error' => 'Le champ "label" ne peut pas être vide.'], Response::HTTP_BAD_REQUEST);
            }
            $operation->setLabel(trim($data['label']));
        }

        if (array_key_exists('unitTime', $data)) {
            if (!is_numeric($data['unitTime'])) {
                return $this->json(['error' => 'Le champ "unitTime" doit être un nombre.'], Response::HTTP_BAD_REQUEST);
            }
            $operation->setUnitTime((float) $data['unitTime']);
        }

        if (array_key_exists('workstationId', $data)) {
            if (!is_int($data['workstationId'])) {
                return $this->json(['error' => 'Le champ "workstationId" doit être un entier.'], Response::HTTP_BAD_REQUEST);
            }
            $workstation = $workstationRepository->find($data['workstationId']);
            if ($workstation === null) {
                return $this->json(['error' => 'La poste de travail spécifiée n\'existe pas.'], Response::HTTP_NOT_FOUND);
            }
            $operation->setWorkstation($workstation);
        }

        if (array_key_exists('machineId', $data)) {
            if ($data['machineId'] === null) {
                $operation->setMachine(null);
            } else {
                if (!is_int($data['machineId'])) {
                    return $this->json(['error' => 'Le champ "machineId" doit être un entier ou null.'], Response::HTTP_BAD_REQUEST);
                }
                $machine = $machineRepository->find($data['machineId']);
                if ($machine === null) {
                    return $this->json(['error' => 'La machine spécifiée n\'existe pas.'], Response::HTTP_NOT_FOUND);
                }
                $operation->setMachine($machine);
            }
        }

        $currentMachine = $operation->getMachine();
        if ($currentMachine !== null && !$operation->getWorkstation()->getMachines()->contains($currentMachine)) {
            return $this->json(['error' => 'Cette machine n\'est pas compatible avec ce poste de travail.'], Response::HTTP_BAD_REQUEST);
        }

        $errors = $validator->validate($operation);
        if (count($errors) > 0) {
            return $this->json($this->formatErrors($errors), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em->flush();

        return $this->json($this->toArray($operation));
    }

    #[Route('/{id}', name: 'operation_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_SUPERVISOR')]
    public function delete(Operation $operation, EntityManagerInterface $em): JsonResponse
    {
        if (!$operation->getProductionOrders()->isEmpty()) {
            return $this->json(['error' => 'Cette opération a des ordres de fabrication et ne peut pas être supprimée.'], Response::HTTP_CONFLICT);
        }

        $em->remove($operation);
        $em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/move', name: 'operation_move', methods: ['POST'])]
    #[IsGranted('ROLE_SUPERVISOR')]
    public function move(
        Operation $operation,
        Request $request,
        EntityManagerInterface $em,
        OperationRepository $operationRepository
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !isset($data['direction']) || !in_array($data['direction'], ['up', 'down'], true)) {
            return $this->json(['error' => 'direction manquante ou invalide ("up" ou "down")'], Response::HTTP_BAD_REQUEST);
        }

        $neighbor = $operationRepository->findNeighbor($operation, $data['direction']);

        if ($neighbor === null) {
            return $this->json(['error' => 'Impossible de déplacer dans cette direction.'], Response::HTTP_BAD_REQUEST);
        }

        $currentRank = $operation->getRank();
        $operation->setRank($neighbor->getRank());
        $neighbor->setRank($currentRank);

        $em->flush();

        return $this->json($this->toArray($operation));
    }

    private function toArray(Operation $o): array
    {
        return [
            'id'          => $o->getId(),
            'rank'        => $o->getRank(),
            'label'       => $o->getLabel(),
            'unitTime'    => $o->getUnitTime(),
            'routings'    => array_map(fn(Routing $r) => [
                'id'        => $r->getId(),
                'reference' => $r->getReference(),
                'label'     => $r->getLabel(),
            ], $o->getRoutings()->toArray()),
            'workstation' => $o->getWorkstation() ? [
                'id'    => $o->getWorkstation()->getId(),
                'label' => $o->getWorkstation()->getLabel() ?? null,
            ] : null,
            'machine'     => $o->getMachine() ? [
                'id'    => $o->getMachine()->getId(),
                'label' => $o->getMachine()->getLabel() ?? null,
            ] : null,
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
