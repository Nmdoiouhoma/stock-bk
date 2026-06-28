<?php

namespace App\Controller;

use App\Entity\Quote;
use App\Entity\QuoteLine;
use App\Enum\QuoteStatus;
use App\Repository\PartRepository;
use App\Repository\QuoteRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/quotes')]
class QuoteController extends AbstractController
{
    #[Route('', name: 'quote_index', methods: ['GET'])]
    public function index(QuoteRepository $repository): JsonResponse
    {
        if (!$this->isGranted('ROLE_SELLER') && !$this->isGranted('ROLE_CUSTOMER') && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        if ($this->isGranted('ROLE_CUSTOMER')) {
            $quotes = $repository->findBy(['client' => $this->getUser()]);
        } else {
            $quotes = $repository->findAll();
        }

        return $this->json(array_map(fn(Quote $q) => $this->toArray($q), $quotes));
    }

    #[Route('/{id}', name: 'quote_show', methods: ['GET'])]
    public function show(Quote $quote): JsonResponse
    {
        if (!$this->isGranted('ROLE_SELLER') && !$this->isGranted('ROLE_CUSTOMER') && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        if ($this->isGranted('ROLE_CUSTOMER') && $quote->getClient() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        return $this->json($this->toArray($quote));
    }

    #[Route('', name: 'quote_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        UserRepository $userRepository,
        PartRepository $partRepository,
    ): JsonResponse {
        if (!$this->isGranted('ROLE_SELLER') && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $missing = array_diff(['reference', 'clientId', 'deadline', 'status', 'totalAmount'], array_keys($data));
        if ($missing) {
            return $this->json(['error' => 'Champs manquants : ' . implode(', ', $missing)], Response::HTTP_BAD_REQUEST);
        }

        $status = QuoteStatus::tryFrom((string) $data['status']);
        if ($status === null) {
            return $this->json(
                ['error' => 'Statut invalide. Valeurs acceptées : ' . implode(', ', array_column(QuoteStatus::cases(), 'value'))],
                Response::HTTP_BAD_REQUEST
            );
        }

        $client = $userRepository->find((int) $data['clientId']);
        if ($client === null) {
            return $this->json(['error' => 'Client introuvable'], Response::HTTP_NOT_FOUND);
        }

        $deadline = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $data['deadline']);
        if ($deadline === false) {
            return $this->json(['error' => 'Format de date invalide. Attendu : Y-m-d'], Response::HTTP_BAD_REQUEST);
        }

        $quote = new Quote();
        $quote->setReference((string) $data['reference']);
        $quote->setClient($client);
        $quote->setCreatedAt(new \DateTimeImmutable());
        $quote->setDeadline($deadline);
        $quote->setStatus($status);
        $quote->setTotalAmount((string) $data['totalAmount']);

        if (isset($data['lines']) && is_array($data['lines'])) {
            foreach ($data['lines'] as $index => $lineData) {
                $lineError = $this->buildLine($lineData, $index, $partRepository, $quote);
                if ($lineError instanceof JsonResponse) {
                    return $lineError;
                }
            }
        }

        $em->persist($quote);
        $em->flush();

        return $this->json($this->toArray($quote), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'quote_update', methods: ['PUT'])]
    public function update(
        Quote $quote,
        Request $request,
        EntityManagerInterface $em,
        UserRepository $userRepository,
    ): JsonResponse {
        if (!$this->isGranted('ROLE_SELLER') && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('reference', $data)) {
            $quote->setReference((string) $data['reference']);
        }

        if (array_key_exists('clientId', $data)) {
            $client = $userRepository->find((int) $data['clientId']);
            if ($client === null) {
                return $this->json(['error' => 'Client introuvable'], Response::HTTP_NOT_FOUND);
            }
            $quote->setClient($client);
        }

        if (array_key_exists('deadline', $data)) {
            $deadline = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $data['deadline']);
            if ($deadline === false) {
                return $this->json(['error' => 'Format de date invalide. Attendu : Y-m-d'], Response::HTTP_BAD_REQUEST);
            }
            $quote->setDeadline($deadline);
        }

        if (array_key_exists('status', $data)) {
            $status = QuoteStatus::tryFrom((string) $data['status']);
            if ($status === null) {
                return $this->json(
                    ['error' => 'Statut invalide. Valeurs acceptées : ' . implode(', ', array_column(QuoteStatus::cases(), 'value'))],
                    Response::HTTP_BAD_REQUEST
                );
            }
            $quote->setStatus($status);
        }

        if (array_key_exists('totalAmount', $data)) {
            $quote->setTotalAmount((string) $data['totalAmount']);
        }

        $em->flush();

        return $this->json($this->toArray($quote));
    }

    private function buildLine(mixed $lineData, int $index, PartRepository $partRepository, Quote $quote): QuoteLine|JsonResponse
    {
        if (!is_array($lineData) || !isset($lineData['partId'], $lineData['quantity'], $lineData['unitPrice'])) {
            return $this->json(
                ['error' => sprintf('Ligne %d : champs requis manquants (partId, quantity, unitPrice)', $index)],
                Response::HTTP_BAD_REQUEST
            );
        }

        $part = $partRepository->find((int) $lineData['partId']);
        if ($part === null) {
            return $this->json(
                ['error' => sprintf('Ligne %d : pièce introuvable (partId=%d)', $index, $lineData['partId'])],
                Response::HTTP_NOT_FOUND
            );
        }

        $line = new QuoteLine();
        $line->setPart($part);
        $line->setQuantity((int) $lineData['quantity']);
        $line->setUnitPrice((string) $lineData['unitPrice']);
        $quote->addLine($line);

        return $line;
    }

    private function toArray(Quote $quote): array
    {
        return [
            'id'          => $quote->getId(),
            'reference'   => $quote->getReference(),
            'client'      => [
                'id'        => $quote->getClient()?->getId(),
                'firstname' => $quote->getClient()?->getFirstname(),
                'lastname'  => $quote->getClient()?->getLastname(),
                'email'     => $quote->getClient()?->getEmail(),
            ],
            'createdAt'   => $quote->getCreatedAt()?->format('Y-m-d'),
            'deadline'    => $quote->getDeadline()?->format('Y-m-d'),
            'status'      => $quote->getStatus()?->value,
            'totalAmount' => $quote->getTotalAmount(),
            'lines'       => $quote->getLines()->map(fn(QuoteLine $line) => [
                'id'        => $line->getId(),
                'part'      => [
                    'id'        => $line->getPart()?->getId(),
                    'reference' => $line->getPart()?->getReference(),
                    'label'     => $line->getPart()?->getLabel(),
                ],
                'quantity'  => $line->getQuantity(),
                'unitPrice' => $line->getUnitPrice(),
            ])->toArray(),
        ];
    }
}
