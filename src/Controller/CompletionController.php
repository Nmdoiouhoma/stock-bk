<?php

namespace App\Controller;

use App\Entity\Completion;
use App\Entity\Operation;
use App\Repository\CompletionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/operations/{id}/completions')]
final class CompletionController extends AbstractController
{
    #[Route('', name: 'completion_index', methods: ['GET'])]
    #[IsGranted('ROLE_WORKER')]
    public function index(Operation $operation, CompletionRepository $completionRepository): JsonResponse
    {
        $completions = $completionRepository->findBy(['operation' => $operation], ['date' => 'DESC']);

        return $this->json(array_map(fn(Completion $c) => $this->toArray($c), $completions));
    }

    #[Route('', name: 'completion_create', methods: ['POST'])]
    #[IsGranted('ROLE_WORKER')]
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

        if (!isset($data['date']) || !is_string($data['date'])) {
            return $this->json(['error' => 'Le champ "date" est obligatoire (format YYYY-MM-DD).'], Response::HTTP_BAD_REQUEST);
        }

        $date = \DateTime::createFromFormat('Y-m-d', $data['date']);
        if (!$date) {
            return $this->json(['error' => 'Le champ "date" doit être au format YYYY-MM-DD.'], Response::HTTP_BAD_REQUEST);
        }

        if (!isset($data['actualQuantity']) || !is_int($data['actualQuantity']) || $data['actualQuantity'] < 1) {
            return $this->json(['error' => 'Le champ "actualQuantity" est obligatoire et doit être un entier positif.'], Response::HTTP_BAD_REQUEST);
        }

        $completion = new Completion();
        $completion->setDate($date);
        $completion->setActualQuantity($data['actualQuantity']);
        $completion->setOperation($operation);

        if (array_key_exists('actualDuration', $data)) {
            if ($data['actualDuration'] !== null && !is_numeric($data['actualDuration'])) {
                return $this->json(['error' => 'Le champ "actualDuration" doit être un nombre ou null.'], Response::HTTP_BAD_REQUEST);
            }
            $completion->setActualDuration($data['actualDuration'] !== null ? (float) $data['actualDuration'] : null);
        }

        $errors = $validator->validate($completion);
        if (count($errors) > 0) {
            return $this->json($this->formatErrors($errors), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $part = $operation->getRouting()->getPart();
        $part->setStockQuantity($part->getStockQuantity() + $completion->getActualQuantity());

        $em->persist($completion);
        $em->flush();

        return $this->json($this->toArray($completion), Response::HTTP_CREATED);
    }

    #[Route('/{cId}', name: 'completion_update', methods: ['PUT'])]
    #[IsGranted('ROLE_SUPERVISOR')]
    public function update(
        Operation $operation,
        int $cId,
        Request $request,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
        CompletionRepository $completionRepository
    ): JsonResponse {
        $completion = $completionRepository->find($cId);
        if ($completion === null || $completion->getOperation() !== $operation) {
            return $this->json(['error' => 'Réalisation introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $oldQuantity = $completion->getActualQuantity();

        if (array_key_exists('date', $data)) {
            $date = \DateTime::createFromFormat('Y-m-d', $data['date']);
            if (!$date) {
                return $this->json(['error' => 'Le champ "date" doit être au format YYYY-MM-DD.'], Response::HTTP_BAD_REQUEST);
            }
            $completion->setDate($date);
        }

        if (array_key_exists('actualQuantity', $data)) {
            if (!is_int($data['actualQuantity']) || $data['actualQuantity'] < 1) {
                return $this->json(['error' => 'Le champ "actualQuantity" doit être un entier positif.'], Response::HTTP_BAD_REQUEST);
            }
            $completion->setActualQuantity($data['actualQuantity']);
        }

        if (array_key_exists('actualDuration', $data)) {
            if ($data['actualDuration'] !== null && !is_numeric($data['actualDuration'])) {
                return $this->json(['error' => 'Le champ "actualDuration" doit être un nombre ou null.'], Response::HTTP_BAD_REQUEST);
            }
            $completion->setActualDuration($data['actualDuration'] !== null ? (float) $data['actualDuration'] : null);
        }

        $errors = $validator->validate($completion);
        if (count($errors) > 0) {
            return $this->json($this->formatErrors($errors), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $diff = $completion->getActualQuantity() - $oldQuantity;
        if ($diff !== 0) {
            $part = $operation->getRouting()->getPart();
            $part->setStockQuantity($part->getStockQuantity() + $diff);
        }

        $em->flush();

        return $this->json($this->toArray($completion));
    }

    #[Route('/{cId}', name: 'completion_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_SUPERVISOR')]
    public function delete(
        Operation $operation,
        int $cId,
        EntityManagerInterface $em,
        CompletionRepository $completionRepository
    ): JsonResponse {
        $completion = $completionRepository->find($cId);
        if ($completion === null || $completion->getOperation() !== $operation) {
            return $this->json(['error' => 'Réalisation introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $part = $operation->getRouting()->getPart();
        $part->setStockQuantity($part->getStockQuantity() - $completion->getActualQuantity());

        $em->remove($completion);
        $em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function toArray(Completion $c): array
    {
        return [
            'id'             => $c->getId(),
            'date'           => $c->getDate()?->format('Y-m-d'),
            'actualQuantity' => $c->getActualQuantity(),
            'actualDuration' => $c->getActualDuration(),
            'operation'      => $c->getOperation() ? [
                'id'    => $c->getOperation()->getId(),
                'label' => $c->getOperation()->getLabel(),
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
