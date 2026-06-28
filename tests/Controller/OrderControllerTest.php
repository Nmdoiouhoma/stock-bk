<?php

namespace App\Tests\Controller;

use App\Entity\Order;
use App\Entity\Quote;
use App\Tests\DataFixtures\QuoteTestFixtures;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class OrderControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private QuoteTestFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client   = static::createClient();
        $this->em       = static::getContainer()->get(EntityManagerInterface::class);
        $this->fixtures = new QuoteTestFixtures(static::getContainer()->get(UserPasswordHasherInterface::class));

        (new ORMExecutor($this->em, new ORMPurger()))->execute([$this->fixtures]);

        $this->loginAs(QuoteTestFixtures::SELLER_EMAIL);
    }

    private function loginAs(string $email, string $password = QuoteTestFixtures::TEST_PASSWORD): void
    {
        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email'    => $email,
            'password' => $password,
        ]));
        $token = json_decode($this->client->getResponse()->getContent(), true)['token'] ?? null;
        $this->assertNotNull($token, "Login failed for $email");
        $this->client->setServerParameters(['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    }

    private function quote1Id(): int
    {
        return $this->fixtures->getReference(QuoteTestFixtures::REF_QUOTE1, Quote::class)->getId();
    }

    private function quote2Id(): int
    {
        return $this->fixtures->getReference(QuoteTestFixtures::REF_QUOTE2, Quote::class)->getId();
    }

    private function quote3Id(): int
    {
        return $this->fixtures->getReference(QuoteTestFixtures::REF_QUOTE3, Quote::class)->getId();
    }

    private function quote5Id(): int
    {
        return $this->fixtures->getReference(QuoteTestFixtures::REF_QUOTE5, Quote::class)->getId();
    }

    private function order1Id(): int
    {
        return $this->fixtures->getReference(QuoteTestFixtures::REF_ORDER1, Order::class)->getId();
    }

    // ── Accès ─────────────────────────────────────────────────

    public function testUnauthenticatedReturns401(): void
    {
        $this->client->setServerParameters([]);
        $this->client->request('GET', '/api/orders');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testCustomerCannotListOrders(): void
    {
        $this->loginAs(QuoteTestFixtures::CUSTOMER1_EMAIL);
        $this->client->request('GET', '/api/orders');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testCustomerCannotCreateOrder(): void
    {
        $this->loginAs(QuoteTestFixtures::CUSTOMER1_EMAIL);
        $this->client->request('POST', '/api/orders', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'quoteId' => $this->quote1Id(),
        ]));
        $this->assertResponseStatusCodeSame(403);
    }

    // ── GET /api/orders ───────────────────────────────────────

    public function testSellerCanListOrders(): void
    {
        $this->client->request('GET', '/api/orders');
        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertCount(1, $data); // order1 créée en fixture
    }

    // ── GET /api/orders/{id} ──────────────────────────────────

    public function testSellerCanGetOrderById(): void
    {
        $this->client->request('GET', '/api/orders/' . $this->order1Id());
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame($this->order1Id(), $data['id']);
        $this->assertArrayHasKey('quote', $data);
        $this->assertArrayHasKey('lines', $data);
        $this->assertIsArray($data['lines']);
    }

    public function testGetOrderLinesMatchQuoteLines(): void
    {
        $this->client->request('GET', '/api/orders/' . $this->order1Id());
        $data = json_decode($this->client->getResponse()->getContent(), true);

        // order1 vient de quote2 (1 ligne : part1, qty=1)
        $this->assertCount(1, $data['lines']);
        $this->assertSame('100.00', $data['lines'][0]['unitPrice']);
        $this->assertSame(1, $data['lines'][0]['quantity']);
    }

    public function testGetUnknownOrderReturns404(): void
    {
        $this->client->request('GET', '/api/orders/999999');
        $this->assertResponseStatusCodeSame(404);
    }

    // ── POST /api/orders ──────────────────────────────────────

    public function testSellerCanCreateOrderFromPendingQuote(): void
    {
        $this->client->request('POST', '/api/orders', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'quoteId' => $this->quote1Id(),
        ]));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('pending', $data['status']);
        $this->assertSame($this->quote1Id(), $data['quote']['id']);
        $this->assertCount(1, $data['lines']); // quote1 a 1 ligne
    }

    public function testSellerCanCreateOrderFromAcceptedQuote(): void
    {
        // Un deuxième order depuis quote2 (déjà utilisée en fixture — pas de contrainte unique)
        $this->client->request('POST', '/api/orders', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'quoteId' => $this->quote2Id(),
        ]));
        $this->assertResponseStatusCodeSame(201);
    }

    public function testOrderLinesAreCopiedFromQuote(): void
    {
        $this->client->request('POST', '/api/orders', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'quoteId' => $this->quote1Id(),
        ]));

        $order = json_decode($this->client->getResponse()->getContent(), true);

        $this->client->request('GET', '/api/quotes/' . $this->quote1Id() . '/lines');
        $quoteLines = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertCount(count($quoteLines), $order['lines']);
        $this->assertSame($quoteLines[0]['unitPrice'], $order['lines'][0]['unitPrice']);
        $this->assertSame($quoteLines[0]['quantity'],  $order['lines'][0]['quantity']);
    }

    public function testCannotCreateOrderFromExpiredQuote(): void
    {
        $this->client->request('POST', '/api/orders', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'quoteId' => $this->quote3Id(),
        ]));
        $this->assertResponseStatusCodeSame(422);
    }

    public function testCannotCreateOrderFromQuoteWithNoLines(): void
    {
        $this->client->request('POST', '/api/orders', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'quoteId' => $this->quote5Id(),
        ]));
        $this->assertResponseStatusCodeSame(422);
    }

    public function testCreateWithMissingQuoteIdReturns400(): void
    {
        $this->client->request('POST', '/api/orders', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([]));
        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateWithUnknownQuoteReturns404(): void
    {
        $this->client->request('POST', '/api/orders', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'quoteId' => 999999,
        ]));
        $this->assertResponseStatusCodeSame(404);
    }

    // ── PUT /api/orders/{id} ──────────────────────────────────

    public function testSellerCanUpdateOrderStatus(): void
    {
        $this->client->request('PUT', '/api/orders/' . $this->order1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'status' => 'in_progress',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('in_progress', $data['status']);
    }

    public function testUpdateWithInvalidStatusReturns400(): void
    {
        $this->client->request('PUT', '/api/orders/' . $this->order1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'status' => 'not_a_valid_status',
        ]));
        $this->assertResponseStatusCodeSame(400);
    }

    public function testUpdateWithMissingStatusReturns400(): void
    {
        $this->client->request('PUT', '/api/orders/' . $this->order1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([]));
        $this->assertResponseStatusCodeSame(400);
    }

    public function testCustomerCannotUpdateOrder(): void
    {
        $this->loginAs(QuoteTestFixtures::CUSTOMER1_EMAIL);
        $this->client->request('PUT', '/api/orders/' . $this->order1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'status' => 'completed',
        ]));
        $this->assertResponseStatusCodeSame(403);
    }

    // ── DELETE absent ─────────────────────────────────────────

    public function testDeleteRouteDoesNotExist(): void
    {
        $this->client->request('DELETE', '/api/orders/' . $this->order1Id());
        $this->assertResponseStatusCodeSame(405);
    }
}
