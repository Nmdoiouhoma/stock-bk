<?php

namespace App\Controller;

use App\Entity\Part;
use App\Enum\PieceType;
use App\Repository\PartRepository;
use App\Repository\SupplierRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/parts')]
#[IsGranted('ROLE_WORKER')]
class PartController extends AbstractController
{
    #[Route('', name: 'part_index', methods: ['GET'])]
    public function index(PartRepository $repository): JsonResponse
    {
        $parts = $repository->findAll();

        return $this->json(array_map(fn(Part $p) => $this->toArray($p), $parts));
    }

    #[Route('', name: 'part_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        SupplierRepository $supplierRepo,
        ValidatorInterface $validator,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $type = $this->resolveType($data['type'] ?? null);
        if ($type === null) {
            return $this->json(
                ['error' => 'Type invalide. Valeurs acceptées : ' . implode(', ', array_column(PieceType::cases(), 'value'))],
                Response::HTTP_BAD_REQUEST
            );
        }

        $part = new Part();
        $part->setReference($data['reference'] ?? '');
        $part->setLabel($data['label'] ?? '');
        $part->setType($type);
        $part->setSalePrice(isset($data['salePrice']) ? (float) $data['salePrice'] : null);
        $part->setCatalogPrice(isset($data['catalogPrice']) ? (float) $data['catalogPrice'] : null);
        $part->setStockQuantity((int) ($data['stockQuantity'] ?? 0));

        if (isset($data['supplierId'])) {
            $supplier = $supplierRepo->find($data['supplierId']);
            if (!$supplier) {
                return $this->json(['error' => 'Fournisseur introuvable'], Response::HTTP_NOT_FOUND);
            }
            $part->setSupplier($supplier);
        }

        $errors = $validator->validate($part);
        if (count($errors) > 0) {
            return $this->json($this->formatErrors($errors), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em->persist($part);
        $em->flush();

        return $this->json($this->toArray($part), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'part_show', methods: ['GET'])]
    public function show(Part $part): JsonResponse
    {
        return $this->json($this->toArray($part));
    }

    #[Route('/{id}', name: 'part_update', methods: ['PUT'])]
    public function update(
        Part $part,
        Request $request,
        EntityManagerInterface $em,
        SupplierRepository $supplierRepo,
        ValidatorInterface $validator,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('reference', $data)) {
            $part->setReference((string) $data['reference']);
        }
        if (array_key_exists('label', $data)) {
            $part->setLabel((string) $data['label']);
        }
        if (array_key_exists('type', $data)) {
            $type = $this->resolveType($data['type']);
            if ($type === null) {
                return $this->json(
                    ['error' => 'Type invalide. Valeurs acceptées : ' . implode(', ', array_column(PieceType::cases(), 'value'))],
                    Response::HTTP_BAD_REQUEST
                );
            }
            $part->setType($type);
        }
        if (array_key_exists('salePrice', $data)) {
            $part->setSalePrice($data['salePrice'] !== null ? (float) $data['salePrice'] : null);
        }
        if (array_key_exists('catalogPrice', $data)) {
            $part->setCatalogPrice($data['catalogPrice'] !== null ? (float) $data['catalogPrice'] : null);
        }
        if (array_key_exists('stockQuantity', $data)) {
            $part->setStockQuantity((int) $data['stockQuantity']);
        }
        if (array_key_exists('supplierId', $data)) {
            if ($data['supplierId'] === null) {
                $part->setSupplier(null);
            } else {
                $supplier = $supplierRepo->find($data['supplierId']);
                if (!$supplier) {
                    return $this->json(['error' => 'Fournisseur introuvable'], Response::HTTP_NOT_FOUND);
                }
                $part->setSupplier($supplier);
            }
        }

        $errors = $validator->validate($part);
        if (count($errors) > 0) {
            return $this->json($this->formatErrors($errors), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em->flush();

        return $this->json($this->toArray($part));
    }

    #[Route('/{id}', name: 'part_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Part $part, EntityManagerInterface $em): JsonResponse
    {
        if (!$part->getRoutings()->isEmpty()) {
            return $this->json(
                ['error' => 'Impossible de supprimer cette pièce : elle est associée à une ou plusieurs gammes.'],
                Response::HTTP_CONFLICT
            );
        }

        if (!$part->getParentBoms()->isEmpty() || !$part->getChildBoms()->isEmpty()) {
            return $this->json(
                ['error' => 'Impossible de supprimer cette pièce : elle est référencée dans une nomenclature.'],
                Response::HTTP_CONFLICT
            );
        }

        $em->remove($part);
        $em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function toArray(Part $part): array
    {
        return [
            'id'            => $part->getId(),
            'reference'     => $part->getReference(),
            'label'         => $part->getLabel(),
            'type'          => $part->getType()?->value,
            'salePrice'     => $part->getSalePrice(),
            'catalogPrice'  => $part->getCatalogPrice(),
            'stockQuantity' => $part->getStockQuantity(),
            'supplier'      => $part->getSupplier() ? [
                'id'   => $part->getSupplier()->getId(),
                'name' => $part->getSupplier()->getName(),
            ] : null,
        ];
    }

    private function resolveType(mixed $value): ?PieceType
    {
        if ($value === null) {
            return null;
        }

        return PieceType::tryFrom((string) $value);
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
