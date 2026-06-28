<?php

namespace App\Controller;

use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Enum\QuoteStatus;
use App\Repository\OrderRepository;
use App\Repository\QuoteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/orders')]
class OrderController extends AbstractController
{
    private const VALID_QUOTE_STATUSES = [QuoteStatus::PENDING, QuoteStatus::ACCEPTED];

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
        QuoteRepository $quoteRepository,
    ): JsonResponse {
        if (!$this->isGranted('ROLE_SELLER') && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data) || !isset($data['quoteId'])) {
            return $this->json(['error' => 'Champ manquant : quoteId'], Response::HTTP_BAD_REQUEST);
        }

        $quote = $quoteRepository->find((int) $data['quoteId']);
        if ($quote === null) {
            return $this->json(['error' => 'Devis introuvable'], Response::HTTP_NOT_FOUND);
        }

        if (!in_array($quote->getStatus(), self::VALID_QUOTE_STATUSES, true)) {
            return $this->json(
                ['error' => 'Le devis doit être en statut "pending" ou "accepted" pour créer une commande.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ($quote->getLines()->isEmpty()) {
            return $this->json(
                ['error' => 'Impossible de créer une commande depuis un devis sans lignes.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $order = new Order();
        $order->setQuote($quote);
        $order->setCreatedAt(new \DateTimeImmutable());
        $order->setStatus(OrderStatus::PENDING);

        foreach ($quote->getLines() as $quoteLine) {
            $order->addLine($quoteLine);
        }

        $em->persist($order);
        $em->flush();

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

    private function toArray(Order $order): array
    {
        return [
            'id'        => $order->getId(),
            'createdAt' => $order->getCreatedAt()?->format('Y-m-d'),
            'status'    => $order->getStatus()?->value,
            'quote'     => [
                'id'          => $order->getQuote()?->getId(),
                'reference'   => $order->getQuote()?->getReference(),
                'totalAmount' => $order->getQuote()?->getTotalAmount(),
            ],
            'lines'     => $order->getLines()->map(fn(\App\Entity\QuoteLine $l) => [
                'id'        => $l->getId(),
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
