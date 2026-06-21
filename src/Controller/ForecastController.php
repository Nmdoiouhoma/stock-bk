<?php

namespace App\Controller;

use App\Entity\Forecast;
use App\Entity\Operation;
use App\Enum\ForecastStatus;
use App\Repository\ForecastRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/operations/{id}/forecasts')]
final class ForecastController extends AbstractController
{
    #[Route('', name: 'forecast_index', methods: ['GET'])]
    public function index(Operation $operation, ForecastRepository $forecastRepository): JsonResponse
    {
        if (!($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_SUPERVISOR') || $this->isGranted('ROLE_WORKER'))) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Access Denied.');
        }

        $forecasts = $forecastRepository->findBy(['operation' => $operation], ['plannedDate' => 'ASC']);

        return $this->json(array_map(fn(Forecast $f) => $this->toArray($f), $forecasts));
    }

    #[Route('', name: 'forecast_create', methods: ['POST'])]
    public function create(
        Operation $operation,
        Request $request,
        EntityManagerInterface $em,
        ValidatorInterface $validator
    ): JsonResponse {
        if (!($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_SUPERVISOR'))) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Access Denied.');
        }

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

        $status = ForecastStatus::PENDING;
        if (isset($data['status'])) {
            $status = ForecastStatus::tryFrom($data['status']);
            if ($status === null) {
                return $this->json(['error' => 'Le champ "status" doit être "pending", "in_progress" ou "completed".'], Response::HTTP_BAD_REQUEST);
            }
        }

        $forecast = new Forecast();
        $forecast->setPlannedDate($plannedDate);
        $forecast->setPlannedQuantity($data['plannedQuantity']);
        $forecast->setStatus($status);
        $forecast->setOperation($operation);

        $errors = $validator->validate($forecast);
        if (count($errors) > 0) {
            return $this->json($this->formatErrors($errors), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em->persist($forecast);
        $em->flush();

        return $this->json($this->toArray($forecast), Response::HTTP_CREATED);
    }

    #[Route('/{fId}', name: 'forecast_update', methods: ['PUT'])]
    public function update(
        Operation $operation,
        int $fId,
        Request $request,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
        ForecastRepository $forecastRepository
    ): JsonResponse {
        if (!($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_SUPERVISOR'))) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Access Denied.');
        }

        $forecast = $forecastRepository->find($fId);
        if ($forecast === null || $forecast->getOperation() !== $operation) {
            return $this->json(['error' => 'Prévision introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('plannedDate', $data)) {
            $plannedDate = \DateTime::createFromFormat('Y-m-d', $data['plannedDate']);
            if (!$plannedDate) {
                return $this->json(['error' => 'Le champ "plannedDate" doit être au format YYYY-MM-DD.'], Response::HTTP_BAD_REQUEST);
            }
            $forecast->setPlannedDate($plannedDate);
        }

        if (array_key_exists('plannedQuantity', $data)) {
            if (!is_int($data['plannedQuantity']) || $data['plannedQuantity'] < 1) {
                return $this->json(['error' => 'Le champ "plannedQuantity" doit être un entier positif.'], Response::HTTP_BAD_REQUEST);
            }
            $forecast->setPlannedQuantity($data['plannedQuantity']);
        }

        if (array_key_exists('status', $data)) {
            $status = ForecastStatus::tryFrom($data['status']);
            if ($status === null) {
                return $this->json(['error' => 'Le champ "status" doit être "pending", "in_progress" ou "completed".'], Response::HTTP_BAD_REQUEST);
            }
            $forecast->setStatus($status);
        }

        $errors = $validator->validate($forecast);
        if (count($errors) > 0) {
            return $this->json($this->formatErrors($errors), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em->flush();

        return $this->json($this->toArray($forecast));
    }

    #[Route('/{fId}', name: 'forecast_delete', methods: ['DELETE'])]
    public function delete(
        Operation $operation,
        int $fId,
        EntityManagerInterface $em,
        ForecastRepository $forecastRepository
    ): JsonResponse {
        if (!($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_SUPERVISOR'))) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Access Denied.');
        }

        $forecast = $forecastRepository->find($fId);
        if ($forecast === null || $forecast->getOperation() !== $operation) {
            return $this->json(['error' => 'Prévision introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $em->remove($forecast);
        $em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function toArray(Forecast $f): array
    {
        return [
            'id' => $f->getId(),
            'plannedDate' => $f->getPlannedDate()?->format('Y-m-d'),
            'plannedQuantity' => $f->getPlannedQuantity(),
            'status' => $f->getStatus()->value,
            'operation' => $f->getOperation() ? [
                'id' => $f->getOperation()->getId(),
                'label' => $f->getOperation()->getLabel(),
            ] : null,
        ];
    }

    private function formatErrors(\Symfony\Component\Validator\ConstraintViolationListInterface $errors): array
    {
        $formatted = [];
        foreach ($errors as $error) {
            $formatted[] = [
                'field' => $error->getPropertyPath(),
                'message' => $error->getMessage(),
            ];
        }

        return ['errors' => $formatted];
    }
}
