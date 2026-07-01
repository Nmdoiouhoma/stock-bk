<?php

namespace App\Tests\Controller;

use App\Entity\Quote;
use App\Entity\User;
use App\Tests\DataFixtures\QuoteTestFixtures;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class QuoteControllerTest extends WebTestCase
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

    private function quote4Id(): int
    {
        return $this->fixtures->getReference(QuoteTestFixtures::REF_QUOTE4, Quote::class)->getId();
    }

    private function customer1Id(): int
    {
        return $this->fixtures->getReference(QuoteTestFixtures::REF_CUSTOMER1, User::class)->getId();
    }

    // ── Authentification ──────────────────────────────────────

    public function testUnauthenticatedReturns401(): void
    {
        $this->client->setServerParameters([]);
        $this->client->request('GET', '/api/quotes');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testWorkerCannotAccessQuotes(): void
    {
        $this->loginAs(QuoteTestFixtures::WORKER_EMAIL);
        $this->client->request('GET', '/api/quotes');
        $this->assertResponseStatusCodeSame(403);
    }

    // ── GET /api/quotes ───────────────────────────────────────

    public function testSellerSeesAllQuotes(): void
    {
        $this->client->request('GET', '/api/quotes');
        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertGreaterThanOrEqual(5, count($data));
    }

    public function testAdminSeesAllQuotes(): void
    {
        $this->loginAs(QuoteTestFixtures::ADMIN_EMAIL);
        $this->client->request('GET', '/api/quotes');
        $this->assertResponseIsSuccessful();
        $this->assertGreaterThanOrEqual(5, count(json_decode($this->client->getResponse()->getContent(), true)));
    }

    public function testCustomerOnlySeesOwnQuotes(): void
    {
        $this->loginAs(QuoteTestFixtures::CUSTOMER1_EMAIL);
        $this->client->request('GET', '/api/quotes');
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertNotEmpty($data);
        foreach ($data as $quote) {
            $this->assertSame($this->customer1Id(), $quote['client']['id']);
        }
    }

    public function testCustomerDoesNotSeeOtherClientQuotes(): void
    {
        $this->loginAs(QuoteTestFixtures::CUSTOMER1_EMAIL);
        $this->client->request('GET', '/api/quotes');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $ids  = array_column($data, 'id');
        $this->assertNotContains($this->quote4Id(), $ids);
    }

    // ── GET /api/quotes/{id} ──────────────────────────────────

    public function testSellerCanGetQuoteById(): void
    {
        $this->client->request('GET', '/api/quotes/' . $this->quote1Id());
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame($this->quote1Id(), $data['id']);
        $this->assertArrayHasKey('lines', $data);
        $this->assertIsArray($data['lines']);
    }

    public function testCustomerCanAccessOwnQuote(): void
    {
        $this->loginAs(QuoteTestFixtures::CUSTOMER1_EMAIL);
        $this->client->request('GET', '/api/quotes/' . $this->quote1Id());
        $this->assertResponseIsSuccessful();
    }

    public function testCustomerCannotAccessOtherClientQuote(): void
    {
        $this->loginAs(QuoteTestFixtures::CUSTOMER1_EMAIL);
        $this->client->request('GET', '/api/quotes/' . $this->quote4Id());
        $this->assertResponseStatusCodeSame(403);
    }

    public function testGetUnknownQuoteReturns404(): void
    {
        $this->client->request('GET', '/api/quotes/999999');
        $this->assertResponseStatusCodeSame(404);
    }

    // ── POST /api/quotes ──────────────────────────────────────

    public function testCustomerCannotCreateQuote(): void
    {
        $this->loginAs(QuoteTestFixtures::CUSTOMER1_EMAIL);
        $this->client->request('POST', '/api/quotes', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference' => 'QTEST-NEW', 'clientId' => $this->customer1Id(),
            'deadline' => '2099-12-31', 'status' => 'pending', 'totalAmount' => '100.00',
        ]));
        $this->assertResponseStatusCodeSame(403);
    }

    public function testSellerCanCreateQuote(): void
    {
        $this->client->request('POST', '/api/quotes', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference'   => 'QTEST-NEW-001',
            'clientId'    => $this->customer1Id(),
            'deadline'    => '2099-12-31',
            'status'      => 'pending',
            'totalAmount' => '500.00',
        ]));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('QTEST-NEW-001', $data['reference']);
        $this->assertSame('pending', $data['status']);
        $this->assertSame('500.00', $data['totalAmount']);
        $this->assertSame($this->customer1Id(), $data['client']['id']);
    }

    public function testCreateWithMissingFieldsReturns400(): void
    {
        $this->client->request('POST', '/api/quotes', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference' => 'QTEST-MISS',
        ]));
        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateWithInvalidStatusReturns400(): void
    {
        $this->client->request('POST', '/api/quotes', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference' => 'QTEST-BAD', 'clientId' => $this->customer1Id(),
            'deadline' => '2099-12-31', 'status' => 'not_a_status', 'totalAmount' => '100.00',
        ]));
        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateWithUnknownClientReturns404(): void
    {
        $this->client->request('POST', '/api/quotes', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference' => 'QTEST-NOCT', 'clientId' => 999999,
            'deadline' => '2099-12-31', 'status' => 'pending', 'totalAmount' => '100.00',
        ]));
        $this->assertResponseStatusCodeSame(404);
    }

    public function testCreateWithInvalidDateReturns400(): void
    {
        $this->client->request('POST', '/api/quotes', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference' => 'QTEST-DATE', 'clientId' => $this->customer1Id(),
            'deadline' => 'not-a-date', 'status' => 'pending', 'totalAmount' => '100.00',
        ]));
        $this->assertResponseStatusCodeSame(400);
    }

    // ── PUT /api/quotes/{id} ──────────────────────────────────

    public function testCustomerCannotUpdateQuote(): void
    {
        $this->loginAs(QuoteTestFixtures::CUSTOMER1_EMAIL);
        $this->client->request('PUT', '/api/quotes/' . $this->quote1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'status' => 'accepted',
        ]));
        $this->assertResponseStatusCodeSame(403);
    }

    public function testSellerCanUpdateDeadlineAndStatus(): void
    {
        $this->client->request('PUT', '/api/quotes/' . $this->quote1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'deadline' => '2099-01-01',
            'status'   => 'cancelled',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('cancelled', $data['status']);
        $this->assertSame('2099-01-01', $data['deadline']);
    }

    public function testReadOnlyFieldsAreIgnoredOnUpdate(): void
    {
        // reference, totalAmount sont ignorés — le totalAmount reste calculé depuis les lignes
        $this->client->request('PUT', '/api/quotes/' . $this->quote1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'totalAmount' => '999.00',
            'reference'   => 'SHOULD-NOT-CHANGE',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('QTEST-DEV-001', $data['reference']); // inchangé
        $this->assertNotSame('999.00', $data['totalAmount']);    // ignoré
    }

    public function testCannotUpdateNonPendingQuote(): void
    {
        // quote2 est ACCEPTED
        $this->client->request('PUT', '/api/quotes/' . $this->quote2Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'deadline' => '2099-01-01',
        ]));
        $this->assertResponseStatusCodeSame(422);
    }

    public function testUpdateWithInvalidStatusReturns400(): void
    {
        $this->client->request('PUT', '/api/quotes/' . $this->quote1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'status' => 'invalid_status',
        ]));
        $this->assertResponseStatusCodeSame(400);
    }

    public function testUpdateWithInvalidDeadlineReturns400(): void
    {
        $this->client->request('PUT', '/api/quotes/' . $this->quote1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'deadline' => 'not-a-date',
        ]));
        $this->assertResponseStatusCodeSame(400);
    }

    public function testUpdateUnknownQuoteReturns404(): void
    {
        $this->client->request('PUT', '/api/quotes/999999', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'status' => 'accepted',
        ]));
        $this->assertResponseStatusCodeSame(404);
    }
}
