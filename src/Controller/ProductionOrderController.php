<?php

namespace App\Controller;

use App\Entity\Operation;
use App\Entity\ProductionOrder;
use App\Enum\OperationStatus;
use App\Repository\ProductionOrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/operations/{id}/production-orders')]
final class ProductionOrderController extends AbstractController
{
    #[Route('', name: 'production_order_index', methods: ['GET'])]
    #[IsGranted('ROLE_WORKER')]
    public function index(Operation $operation, ProductionOrderRepository $repository): JsonResponse
    {
        $orders = $repository->findBy(['operation' => $operation], ['plannedDate' => 'ASC']);

        return $this->json(array_map(fn(ProductionOrder $o) => $this->toArray($o), $orders));
    }

    #[Route('', name: 'production_order_create', methods: ['POST'])]
    #[IsGranted('ROLE_SUPERVISOR')]
    public function create(
        Operation $operation,
        Request $request,
        EntityManagerInterface $em,
        ValidatorInterface $validator
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (!isset($data['plannedDate']) || !is_string($data['plannedDate'])) {
            return $this->json(['error' => 'Le champ "plannedDate" est obligatoire (format YYYY-MM-DD).'], Response::HTTP_BAD_REQUEST);
        }

        $plannedDate = \DateTime::createFromFormat('Y-m-d', $data['plannedDate']);
        if (!$plannedDate) {
            return $this->json(['error' => 'Le champ "plannedDate" doit être au format YYYY-MM-DD.'], Response::HTTP_BAD_REQUEST);
        }

        if (!isset($data['plannedQuantity']) || !is_int($data['plannedQuantity']) || $data['plannedQuantity'] < 1) {
            return $this->json(['error' => 'Le champ "plannedQuantity" est obligatoire et doit être un entier positif.'], Response::HTTP_BAD_REQUEST);
        }

        $status = OperationStatus::PENDING;
        if (isset($data['status'])) {
            $status = OperationStatus::tryFrom($data['status']);
            if ($status === null) {
                return $this->json(['error' => 'Le champ "status" doit être "pending", "in_progress" ou "completed".'], Response::HTTP_BAD_REQUEST);
            }
        }

        $order = new ProductionOrder();
        $order->setPlannedDate($plannedDate);
        $order->setPlannedQuantity($data['plannedQuantity']);
        $order->setStatus($status);
        $order->setOperation($operation);

        if (array_key_exists('actualQuantity', $data)) {
            if ($data['actualQuantity'] !== null && (!is_int($data['actualQuantity']) || $data['actualQuantity'] < 1)) {
                return $this->json(['error' => 'Le champ "actualQuantity" doit être un entier positif ou null.'], Response::HTTP_BAD_REQUEST);
            }
            $order->setActualQuantity($data['actualQuantity']);
        }

        if (array_key_exists('actualDuration', $data)) {
            if ($data['actualDuration'] !== null && !is_numeric($data['actualDuration'])) {
                return $this->json(['error' => 'Le champ "actualDuration" doit être un nombre ou null.'], Response::HTTP_BAD_REQUEST);
            }
            $order->setActualDuration($data['actualDuration'] !== null ? (float) $data['actualDuration'] : null);
        }

        $errors = $validator->validate($order);
        if (count($errors) > 0) {
            return $this->json($this->formatErrors($errors), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($status === OperationStatus::COMPLETED && $order->getActualQuantity() !== null) {
            $routing = $operation->getRoutings()->first();
            if ($routing) {
                $part = $routing->getPart();
                $part->setStockQuantity($part->getStockQuantity() + $order->getActualQuantity());
            }
        }

        $em->persist($order);
        $em->flush();

        return $this->json($this->toArray($order), Response::HTTP_CREATED);
    }

    #[Route('/{oId}', name: 'production_order_update', methods: ['PUT'])]
    #[IsGranted('ROLE_WORKER')]
    public function update(
        Operation $operation,
        int $oId,
        Request $request,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
        ProductionOrderRepository $repository
    ): JsonResponse {
        $order = $repository->find($oId);
        if ($order === null || $order->getOperation() !== $operation) {
            return $this->json(['error' => 'Ordre introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $wasCompleted    = $order->getStatus() === OperationStatus::COMPLETED;
        $oldActualQty    = $order->getActualQuantity();

        if (array_key_exists('plannedDate', $data) || array_key_exists('plannedQuantity', $data)) {
            if (!$this->isGranted('ROLE_SUPERVISOR')) {
                return $this->json(['error' => 'Seul un superviseur peut modifier plannedDate ou plannedQuantity.'], Response::HTTP_FORBIDDEN);
            }
        }

        if (array_key_exists('plannedDate', $data)) {
            $plannedDate = \DateTime::createFromFormat('Y-m-d', $data['plannedDate']);
            if (!$plannedDate) {
                return $this->json(['error' => 'Le champ "plannedDate" doit être au format YYYY-MM-DD.'], Response::HTTP_BAD_REQUEST);
            }
            $order->setPlannedDate($plannedDate);
        }

        if (array_key_exists('plannedQuantity', $data)) {
            if (!is_int($data['plannedQuantity']) || $data['plannedQuantity'] < 1) {
                return $this->json(['error' => 'Le champ "plannedQuantity" doit être un entier positif.'], Response::HTTP_BAD_REQUEST);
            }
            $order->setPlannedQuantity($data['plannedQuantity']);
        }

        if (array_key_exists('actualQuantity', $data)) {
            if ($data['actualQuantity'] !== null && (!is_int($data['actualQuantity']) || $data['actualQuantity'] < 1)) {
                return $this->json(['error' => 'Le champ "actualQuantity" doit être un entier positif ou null.'], Response::HTTP_BAD_REQUEST);
            }
            $order->setActualQuantity($data['actualQuantity']);
        }

        if (array_key_exists('actualDuration', $data)) {
            if ($data['actualDuration'] !== null && !is_numeric($data['actualDuration'])) {
                return $this->json(['error' => 'Le champ "actualDuration" doit être un nombre ou null.'], Response::HTTP_BAD_REQUEST);
            }
            $order->setActualDuration($data['actualDuration'] !== null ? (float) $data['actualDuration'] : null);
        }

        if (array_key_exists('status', $data)) {
            $status = OperationStatus::tryFrom($data['status']);
            if ($status === null) {
                return $this->json(['error' => 'Le champ "status" doit être "pending", "in_progress" ou "completed".'], Response::HTTP_BAD_REQUEST);
            }
            $order->setStatus($status);
        }

        $errors = $validator->validate($order);
        if (count($errors) > 0) {
            return $this->json($this->formatErrors($errors), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $isCompleted  = $order->getStatus() === OperationStatus::COMPLETED;
        $newActualQty = $order->getActualQuantity();
        $firstRouting = $operation->getRoutings()->first();
        $part         = $firstRouting ? $firstRouting->getPart() : null;

        if ($part !== null) {
            if (!$wasCompleted && $isCompleted && $newActualQty !== null) {
                $part->setStockQuantity($part->getStockQuantity() + $newActualQty);
            } elseif ($wasCompleted && !$isCompleted && $oldActualQty !== null) {
                $part->setStockQuantity($part->getStockQuantity() - $oldActualQty);
            } elseif ($wasCompleted && $isCompleted && $newActualQty !== $oldActualQty) {
                $delta = ($newActualQty ?? 0) - ($oldActualQty ?? 0);
                if ($delta !== 0) {
                    $part->setStockQuantity($part->getStockQuantity() + $delta);
                }
            }
        }

        $em->flush();

        return $this->json($this->toArray($order));
    }

    #[Route('/{oId}', name: 'production_order_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_SUPERVISOR')]
    public function delete(
        Operation $operation,
        int $oId,
        EntityManagerInterface $em,
        ProductionOrderRepository $repository
    ): JsonResponse {
        $order = $repository->find($oId);
        if ($order === null || $order->getOperation() !== $operation) {
            return $this->json(['error' => 'Ordre introuvable.'], Response::HTTP_NOT_FOUND);
        }

        if ($order->getStatus() === OperationStatus::COMPLETED && $order->getActualQuantity() !== null) {
            $firstRouting = $operation->getRoutings()->first();
            if ($firstRouting) {
                $part = $firstRouting->getPart();
                $part->setStockQuantity($part->getStockQuantity() - $order->getActualQuantity());
            }
        }

        $em->remove($order);
        $em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function toArray(ProductionOrder $o): array
    {
        return [
            'id'              => $o->getId(),
            'plannedDate'     => $o->getPlannedDate()?->format('Y-m-d'),
            'plannedQuantity' => $o->getPlannedQuantity(),
            'actualQuantity'  => $o->getActualQuantity(),
            'actualDuration'  => $o->getActualDuration(),
            'status'          => $o->getStatus()->value,
            'operation'       => $o->getOperation() ? [
                'id'    => $o->getOperation()->getId(),
                'label' => $o->getOperation()->getLabel(),
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
