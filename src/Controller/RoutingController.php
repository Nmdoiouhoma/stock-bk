<?php

namespace App\Controller;

use App\Entity\Operation;
use App\Entity\Routing;
use App\Enum\PieceType;
use App\Enum\Role;
use App\Repository\MachineRepository;
use App\Repository\OperationRepository;
use App\Repository\PartRepository;
use App\Repository\RoutingRepository;
use App\Repository\UserRepository;
use App\Repository\WorkstationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/routings')]
#[IsGranted('ROLE_USER')]
final class RoutingController extends AbstractController
{
    #[Route('', name: 'routing_index', methods: ['GET'])]
    public function index(RoutingRepository $routingRepository): JsonResponse
    {
        if (!($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_SUPERVISOR') || $this->isGranted('ROLE_WORKER'))) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Access Denied.');
        }

        $routings = $routingRepository->findAll();

        return $this->json(array_map(fn(Routing $g) => $this->toArray($g), $routings));
    }

    #[Route('/{id}', name: 'routing_show', methods: ['GET'])]
    public function show(Routing $routing): JsonResponse
    {
        if (!($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_SUPERVISOR') || $this->isGranted('ROLE_WORKER'))) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Access Denied.');
        }

        return $this->json($this->toArray($routing));
    }

    #[Route('', name: 'routing_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
        PartRepository $partRepository,
        UserRepository $userRepository,
        RoutingRepository $routingRepository,
    ): JsonResponse {
        if (!($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_SUPERVISOR'))) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Access Denied.');
        }

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
    public function update(
        Routing $routing,
        Request $request,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
        PartRepository $partRepository,
        UserRepository $userRepository,
        RoutingRepository $routingRepository,
    ): JsonResponse {
        if (!($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_SUPERVISOR'))) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Access Denied.');
        }

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
    public function delete(Routing $routing, EntityManagerInterface $em): JsonResponse
    {
        if (!($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_SUPERVISOR'))) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Access Denied.');
        }

        if (!$routing->getOperations()->isEmpty()) {
            return $this->json(['error' => 'Impossible de supprimer une gamme qui possède des opérations.'], Response::HTTP_CONFLICT);
        }

        $em->remove($routing);
        $em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/operations', name: 'routing_operation_create', methods: ['POST'])]
    #[IsGranted('ROLE_SUPERVISOR')]
    public function createOperation(
        Routing $routing,
        Request $request,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
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

        if (!isset($data['workstationId']) || !is_int($data['workstationId'])) {
            return $this->json(['error' => 'Le champ "workstationId" est obligatoire et doit être un entier.'], Response::HTTP_BAD_REQUEST);
        }

        $workstation = $workstationRepository->find($data['workstationId']);
        if ($workstation === null) {
            return $this->json(['error' => 'La poste de travail spécifiée n\'existe pas.'], Response::HTTP_NOT_FOUND);
        }

        $machine = null;
        if (array_key_exists('machineId', $data) && $data['machineId'] !== null) {
            if (!is_int($data['machineId'])) {
                return $this->json(['error' => 'Le champ "machineId" doit être un entier ou null.'], Response::HTTP_BAD_REQUEST);
            }
            $machine = $machineRepository->find($data['machineId']);
            if ($machine === null) {
                return $this->json(['error' => 'La machine spécifiée n\'existe pas.'], Response::HTTP_NOT_FOUND);
            }
        }

        $max = $operationRepository->getMaxRankForRouting($routing);
        $rank = ($max === null) ? 1 : ($max + 1);

        $operation = new Operation();
        $operation->setLabel(trim($data['label']));
        $operation->setUnitTime((float) $data['unitTime']);
        $operation->addRouting($routing);
        $operation->setWorkstation($workstation);
        $operation->setMachine($machine);
        $operation->setRank($rank);

        $errors = $validator->validate($operation);
        if (count($errors) > 0) {
            return $this->json($this->formatErrors($errors), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em->persist($operation);
        $em->flush();

        // Return similar payload as OperationController::toArray
        return $this->json([
            'id'       => $operation->getId(),
            'rank'     => $operation->getRank(),
            'label'    => $operation->getLabel(),
            'unitTime' => $operation->getUnitTime(),
            'routings' => [[
                'id'        => $routing->getId(),
                'reference' => $routing->getReference(),
                'label'     => $routing->getLabel(),
            ]],
            'workstation' => $operation->getWorkstation() ? [
                'id'    => $operation->getWorkstation()->getId(),
                'label' => $operation->getWorkstation()->getLabel() ?? null,
            ] : null,
            'machine' => $operation->getMachine() ? [
                'id'    => $operation->getMachine()->getId(),
                'label' => $operation->getMachine()->getLabel() ?? null,
            ] : null,
        ], Response::HTTP_CREATED);
    }
    #[Route('/{id}/operations/{opId}', name: 'routing_operation_add', methods: ['POST'])]
    #[IsGranted('ROLE_SUPERVISOR')]
    public function addOperation(
        Routing $routing,
        int $opId,
        OperationRepository $operationRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        $operation = $operationRepository->find($opId);
        if ($operation === null) {
            return $this->json(['error' => 'Opération introuvable.'], Response::HTTP_NOT_FOUND);
        }

        if ($operation->getRoutings()->contains($routing)) {
            return $this->json(['error' => 'Cette opération est déjà associée à cette gamme.'], Response::HTTP_CONFLICT);
        }

        $operation->addRouting($routing);
        $em->flush();

        return $this->json($this->toArray($routing), Response::HTTP_OK);
    }

    #[Route('/{id}/operations/{opId}', name: 'routing_operation_remove', methods: ['DELETE'])]
    #[IsGranted('ROLE_SUPERVISOR')]
    public function removeOperation(
        Routing $routing,
        int $opId,
        OperationRepository $operationRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        $operation = $operationRepository->find($opId);
        if ($operation === null) {
            return $this->json(['error' => 'Opération introuvable.'], Response::HTTP_NOT_FOUND);
        }

        if (!$operation->getRoutings()->contains($routing)) {
            return $this->json(['error' => 'Cette opération n\'est pas associée à cette gamme.'], Response::HTTP_NOT_FOUND);
        }

        $operation->removeRouting($routing);
        $em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function toArray(Routing $routing): array
    {
        $operations = $routing->getOperations()->toArray();
        usort($operations, fn(Operation $a, Operation $b) => $a->getRank() <=> $b->getRank());

        return [
            'id'              => $routing->getId(),
            'reference'       => $routing->getReference(),
            'label'           => $routing->getLabel(),
            'operationsCount' => $routing->getOperations()->count(),
            'part'            => $routing->getPart() ? [
                'id'        => $routing->getPart()->getId(),
                'reference' => $routing->getPart()->getReference(),
                'label'     => $routing->getPart()->getLabel(),
            ] : null,
            'supervisor' => $routing->getSupervisor() ? [
                'id'        => $routing->getSupervisor()->getId(),
                'firstname' => $routing->getSupervisor()->getFirstname(),
                'lastname'  => $routing->getSupervisor()->getLastname(),
            ] : null,
            'operations' => array_map(fn(Operation $o) => [
                'id'       => $o->getId(),
                'rank'     => $o->getRank(),
                'label'    => $o->getLabel(),
                'unitTime' => $o->getUnitTime(),
                'workstation' => $o->getWorkstation() ? [
                    'id'    => $o->getWorkstation()->getId(),
                    'label' => $o->getWorkstation()->getLabel(),
                ] : null,
                'machine' => $o->getMachine() ? [
                    'id'    => $o->getMachine()->getId(),
                    'label' => $o->getMachine()->getLabel(),
                ] : null,
            ], $operations),
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
