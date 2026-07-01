<?php

namespace App\Tests\Controller;

use App\Entity\Order;
use App\Entity\Quote;
use App\Entity\QuoteLine;
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

    private function order1Id(): int
    {
        return $this->fixtures->getReference(QuoteTestFixtures::REF_ORDER1, Order::class)->getId();
    }

    private function quote1LineId(): int
    {
        return $this->fixtures->getReference(QuoteTestFixtures::REF_QUOTE1_LINE, QuoteLine::class)->getId();
    }

    private function quote2LineId(): int
    {
        return $this->fixtures->getReference(QuoteTestFixtures::REF_QUOTE2_LINE, QuoteLine::class)->getId();
    }

    private function quote3LineId(): int
    {
        return $this->fixtures->getReference(QuoteTestFixtures::REF_QUOTE3_LINE, QuoteLine::class)->getId();
    }

    private function quote4LineId(): int
    {
        return $this->fixtures->getReference(QuoteTestFixtures::REF_QUOTE4_LINE, QuoteLine::class)->getId();
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
            'quoteLineIds' => [$this->quote1LineId()],
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
        $this->assertArrayHasKey('totalAmount', $data);
        $this->assertArrayHasKey('lines', $data);
        $this->assertIsArray($data['lines']);
    }

    public function testGetOrderLinesMatchQuoteLines(): void
    {
        $this->client->request('GET', '/api/orders/' . $this->order1Id());
        $data = json_decode($this->client->getResponse()->getContent(), true);

        // order1 contient quote2Line (part1, qty=1, unitPrice=100.00)
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

    public function testSellerCanCreateOrderFromQuoteLines(): void
    {
        $this->client->request('POST', '/api/orders', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'quoteLineIds' => [$this->quote1LineId()],
        ]));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('pending', $data['status']);
        $this->assertArrayHasKey('totalAmount', $data);
        $this->assertCount(1, $data['lines']);
        $this->assertSame($this->quote1LineId(), $data['lines'][0]['id']);
    }

    public function testTotalAmountIsCalculatedFromQuoteLines(): void
    {
        // quote1Line: qty=2, unitPrice=100.00 → total=200.00
        $this->client->request('POST', '/api/orders', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'quoteLineIds' => [$this->quote1LineId()],
        ]));

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('200.00', $data['totalAmount']);
    }

    public function testQuoteBecomesAcceptedWhenAllLinesOrdered(): void
    {
        // quote1 has a single line (quote1Line) — after ordering it, quote1 should become "accepted"
        $this->client->request('POST', '/api/orders', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'quoteLineIds' => [$this->quote1LineId()],
        ]));
        $this->assertResponseStatusCodeSame(201);

        $this->em->clear();
        $quote1 = $this->em->find(Quote::class, $this->quote1Id());
        $this->assertSame('accepted', $quote1->getStatus()->value);
    }

    public function testCreateWithMissingQuoteLineIdsReturns400(): void
    {
        $this->client->request('POST', '/api/orders', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([]));
        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateWithEmptyQuoteLineIdsReturns400(): void
    {
        $this->client->request('POST', '/api/orders', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'quoteLineIds' => [],
        ]));
        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateWithUnknownQuoteLineIdReturns404(): void
    {
        $this->client->request('POST', '/api/orders', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'quoteLineIds' => [999999],
        ]));
        $this->assertResponseStatusCodeSame(404);
    }

    public function testCannotCreateOrderFromExpiredQuoteLine(): void
    {
        // quote3 has a deadline in the past (-5 days)
        $this->client->request('POST', '/api/orders', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'quoteLineIds' => [$this->quote3LineId()],
        ]));
        $this->assertResponseStatusCodeSame(422);
    }

    public function testCannotCreateOrderFromLineAlreadyInOrder(): void
    {
        // quote2Line is already in order1 (fixture)
        $this->client->request('POST', '/api/orders', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'quoteLineIds' => [$this->quote2LineId()],
        ]));
        $this->assertResponseStatusCodeSame(422);
    }

    public function testCannotCreateOrderFromLinesOfDifferentClients(): void
    {
        // quote1Line belongs to customer1, quote4Line belongs to customer2
        $this->client->request('POST', '/api/orders', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'quoteLineIds' => [$this->quote1LineId(), $this->quote4LineId()],
        ]));
        $this->assertResponseStatusCodeSame(422);
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

    // ── DELETE ────────────────────────────────────────────────

    public function testDeleteRouteReturns405(): void
    {
        $this->client->request('DELETE', '/api/orders/' . $this->order1Id());
        $this->assertResponseStatusCodeSame(405);
    }
}
