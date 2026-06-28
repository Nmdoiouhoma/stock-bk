<?php

namespace App\Controller;

use App\Entity\Quote;
use App\Entity\QuoteLine;
use App\Enum\PieceType;
use App\Enum\QuoteStatus;
use App\Repository\PartRepository;
use App\Repository\QuoteLineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/quotes/{id}/lines')]
class QuoteLineController extends AbstractController
{
    private const LOCKED_STATUSES = [QuoteStatus::ACCEPTED, QuoteStatus::EXPIRED];

    #[Route('', name: 'quote_line_index', methods: ['GET'])]
    public function index(Quote $quote): JsonResponse
    {
        if (!$this->isGranted('ROLE_SELLER') && !$this->isGranted('ROLE_CUSTOMER') && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        if ($this->isGranted('ROLE_CUSTOMER') && $quote->getClient() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        return $this->json($quote->getLines()->map(fn(QuoteLine $l) => $this->toArray($l))->toArray());
    }

    #[Route('', name: 'quote_line_create', methods: ['POST'])]
    public function create(
        Quote $quote,
        Request $request,
        EntityManagerInterface $em,
        PartRepository $partRepository,
    ): JsonResponse {
        if (!$this->isGranted('ROLE_SELLER') && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        if (in_array($quote->getStatus(), self::LOCKED_STATUSES, true)) {
            return $this->json(
                ['error' => 'Impossible de modifier un devis accepté ou expiré.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (!isset($data['partId'], $data['quantity'])) {
            return $this->json(['error' => 'Champs manquants : partId, quantity'], Response::HTTP_BAD_REQUEST);
        }

        $part = $partRepository->find((int) $data['partId']);
        if ($part === null) {
            return $this->json(['error' => 'Pièce introuvable'], Response::HTTP_NOT_FOUND);
        }

        if ($part->getType() !== PieceType::Finished) {
            return $this->json(
                ['error' => 'Seules les pièces de type "finished" peuvent être ajoutées à un devis.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $alreadyPresent = $quote->getLines()->exists(
            fn(int $key, QuoteLine $l) => $l->getPart()?->getId() === $part->getId()
        );
        if ($alreadyPresent) {
            return $this->json(
                ['error' => 'Cette pièce est déjà présente dans le devis.'],
                Response::HTTP_CONFLICT
            );
        }

        $line = new QuoteLine();
        $line->setPart($part);
        $line->setQuantity((int) $data['quantity']);
        $line->setUnitPrice((string) $part->getSalePrice());

        $quote->addLine($line);
        $em->flush();

        return $this->json($this->toArray($line), Response::HTTP_CREATED);
    }

    #[Route('/{lineId}', name: 'quote_line_update', methods: ['PUT'])]
    public function update(
        Quote $quote,
        int $lineId,
        Request $request,
        EntityManagerInterface $em,
        QuoteLineRepository $lineRepository,
    ): JsonResponse {
        if (!$this->isGranted('ROLE_SELLER') && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        if (in_array($quote->getStatus(), self::LOCKED_STATUSES, true)) {
            return $this->json(
                ['error' => 'Impossible de modifier un devis accepté ou expiré.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $line = $lineRepository->find($lineId);
        if ($line === null || $line->getQuote() !== $quote) {
            return $this->json(['error' => 'Ligne introuvable dans ce devis.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (!array_key_exists('quantity', $data)) {
            return $this->json(['error' => 'Champ modifiable : quantity'], Response::HTTP_BAD_REQUEST);
        }

        $line->setQuantity((int) $data['quantity']);
        $em->flush();

        return $this->json($this->toArray($line));
    }

    #[Route('/{lineId}', name: 'quote_line_delete', methods: ['DELETE'])]
    public function delete(
        Quote $quote,
        int $lineId,
        EntityManagerInterface $em,
        QuoteLineRepository $lineRepository,
    ): JsonResponse {
        if (!$this->isGranted('ROLE_SELLER') && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        if (in_array($quote->getStatus(), self::LOCKED_STATUSES, true)) {
            return $this->json(
                ['error' => 'Impossible de modifier un devis accepté ou expiré.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $line = $lineRepository->find($lineId);
        if ($line === null || $line->getQuote() !== $quote) {
            return $this->json(['error' => 'Ligne introuvable dans ce devis.'], Response::HTTP_NOT_FOUND);
        }

        $quote->removeLine($line);
        $em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function toArray(QuoteLine $line): array
    {
        return [
            'id'        => $line->getId(),
            'part'      => [
                'id'        => $line->getPart()?->getId(),
                'reference' => $line->getPart()?->getReference(),
                'label'     => $line->getPart()?->getLabel(),
            ],
            'quantity'  => $line->getQuantity(),
            'unitPrice' => $line->getUnitPrice(),
        ];
    }
}
