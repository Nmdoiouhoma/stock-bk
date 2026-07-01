<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\QuoteLine;
use App\Enum\OrderStatus;
use App\Enum\QuoteStatus;
use App\Repository\OrderRepository;
use App\Repository\QuoteLineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/orders')]
class OrderController extends AbstractController
{
    #[Route('', name: 'order_index', methods: ['GET'])]
    public function index(OrderRepository $repository): JsonResponse
    {
        if (!$this->isGranted('ROLE_SELLER') && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        return $this->json(array_map(fn(Order $o) => $this->toArray($o), $repository->findAll()));
    }

    #[Route('/{id}', name: 'order_show', methods: ['GET'])]
    public function show(Order $order): JsonResponse
    {
        if (!$this->isGranted('ROLE_SELLER') && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        return $this->json($this->toArray($order));
    }

    #[Route('', name: 'order_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        QuoteLineRepository $quoteLineRepository,
    ): JsonResponse {
        if (!$this->isGranted('ROLE_SELLER') && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data) || !isset($data['quoteLineIds']) || !is_array($data['quoteLineIds']) || empty($data['quoteLineIds'])) {
            return $this->json(['error' => 'Champ requis : quoteLineIds (tableau non vide d\'identifiants)'], Response::HTTP_BAD_REQUEST);
        }

        $ids = array_unique(array_map('intval', $data['quoteLineIds']));
        /** @var QuoteLine[] $quoteLines */
        $quoteLines = [];

        foreach ($ids as $id) {
            $line = $quoteLineRepository->find($id);
            if ($line === null) {
                return $this->json(['error' => "Ligne de devis introuvable : $id"], Response::HTTP_NOT_FOUND);
            }
            $quoteLines[] = $line;
        }

        // All QuoteLines must belong to the same client
        $clientId = null;
        foreach ($quoteLines as $line) {
            $lineClientId = $line->getQuote()->getClient()->getId();
            if ($clientId === null) {
                $clientId = $lineClientId;
            } elseif ($clientId !== $lineClientId) {
                return $this->json(
                    ['error' => 'Toutes les lignes de devis doivent appartenir au même client'],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
        }

        // Order date must not be past the deadline of any associated quote
        $today = new \DateTimeImmutable('today');
        foreach ($quoteLines as $line) {
            $deadline = $line->getQuote()->getDeadline();
            if ($deadline !== null && $today > $deadline) {
                return $this->json(
                    ['error' => 'La date de commande dépasse le délai du devis #' . $line->getQuote()->getId()],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
        }

        // A QuoteLine can only be ordered once
        foreach ($quoteLines as $line) {
            if (!$line->getOrders()->isEmpty()) {
                return $this->json(
                    ['error' => 'La ligne de devis #' . $line->getId() . ' est déjà associée à une commande'],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
        }

        // Total = sum of (unitPrice * quantity) for each QuoteLine
        $totalAmount = 0.0;
        foreach ($quoteLines as $line) {
            $totalAmount += (float) ($line->getQuantity() ?? 0) * (float) ($line->getUnitPrice() ?? '0');
        }
        $totalAmount = number_format($totalAmount, 2, '.', '');

        $order = new Order();
        $order->setCreatedAt(new \DateTimeImmutable());
        $order->setStatus(OrderStatus::PENDING);
        $order->setTotalAmount($totalAmount);
        foreach ($quoteLines as $line) {
            $order->addLine($line);
        }
        $em->persist($order);

        // Mark a quote as "accepted" when all its lines are now ordered
        $affectedQuotes = [];
        foreach ($quoteLines as $line) {
            $quote = $line->getQuote();
            $affectedQuotes[$quote->getId()] = $quote;
        }
        foreach ($affectedQuotes as $quote) {
            $allOrdered = true;
            foreach ($quote->getLines() as $quoteLine) {
                $alreadyOrdered = !$quoteLine->getOrders()->isEmpty();
                $inCurrentBatch = in_array($quoteLine, $quoteLines, true);
                if (!$alreadyOrdered && !$inCurrentBatch) {
                    $allOrdered = false;
                    break;
                }
            }
            if ($allOrdered) {
                $quote->setStatus(QuoteStatus::ACCEPTED);
            }
        }

        try {
            $em->flush();
        } catch (\Exception $e) {
            return $this->json(['error' => 'Erreur base de données : ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json($this->toArray($order), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'order_update', methods: ['PUT'])]
    public function update(Order $order, Request $request, EntityManagerInterface $em): JsonResponse
    {
        if (!$this->isGranted('ROLE_SELLER') && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data) || !array_key_exists('status', $data)) {
            return $this->json(['error' => 'Champ requis : status'], Response::HTTP_BAD_REQUEST);
        }

        $status = OrderStatus::tryFrom((string) $data['status']);
        if ($status === null) {
            return $this->json(
                ['error' => 'Statut invalide. Valeurs acceptées : ' . implode(', ', array_column(OrderStatus::cases(), 'value'))],
                Response::HTTP_BAD_REQUEST
            );
        }

        $order->setStatus($status);
        $em->flush();

        return $this->json($this->toArray($order));
    }

    #[Route('/{id}', name: 'order_delete', methods: ['DELETE'])]
    public function delete(): JsonResponse
    {
        return $this->json(['error' => 'La suppression d\'une commande n\'est pas autorisée'], Response::HTTP_METHOD_NOT_ALLOWED);
    }

    private function toArray(Order $order): array
    {
        return [
            'id'          => $order->getId(),
            'createdAt'   => $order->getCreatedAt()?->format('Y-m-d'),
            'status'      => $order->getStatus()?->value,
            'totalAmount' => $order->getTotalAmount(),
            'lines'       => $order->getLines()->map(fn(QuoteLine $l) => [
                'id'        => $l->getId(),
                'quoteId'   => $l->getQuote()?->getId(),
                'part'      => [
                    'id'        => $l->getPart()?->getId(),
                    'reference' => $l->getPart()?->getReference(),
                    'label'     => $l->getPart()?->getLabel(),
                ],
                'quantity'  => $l->getQuantity(),
                'unitPrice' => $l->getUnitPrice(),
            ])->toArray(),
        ];
    }
}
