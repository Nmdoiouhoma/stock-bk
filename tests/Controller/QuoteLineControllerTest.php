<?php

namespace App\Tests\Controller;

use App\Entity\Part;
use App\Entity\Quote;
use App\Entity\QuoteLine;
use App\Tests\DataFixtures\QuoteTestFixtures;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class QuoteLineControllerTest extends WebTestCase
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

    private function quote4Id(): int
    {
        return $this->fixtures->getReference(QuoteTestFixtures::REF_QUOTE4, Quote::class)->getId();
    }

    private function part1Id(): int
    {
        return $this->fixtures->getReference(QuoteTestFixtures::REF_PART1, Part::class)->getId();
    }

    private function part2Id(): int
    {
        return $this->fixtures->getReference(QuoteTestFixtures::REF_PART2, Part::class)->getId();
    }

    private function part3Id(): int
    {
        return $this->fixtures->getReference(QuoteTestFixtures::REF_PART3, Part::class)->getId();
    }

    private function quote1LineId(): int
    {
        return $this->fixtures->getReference(QuoteTestFixtures::REF_QUOTE1_LINE, QuoteLine::class)->getId();
    }

    private function quote2LineId(): int
    {
        return $this->fixtures->getReference(QuoteTestFixtures::REF_QUOTE2_LINE, QuoteLine::class)->getId();
    }

    // ── GET /api/quotes/{id}/lines ────────────────────────────

    public function testSellerCanListLines(): void
    {
        $this->client->request('GET', '/api/quotes/' . $this->quote1Id() . '/lines');
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertArrayHasKey('part', $data[0]);
        $this->assertArrayHasKey('quantity', $data[0]);
        $this->assertArrayHasKey('unitPrice', $data[0]);
    }

    public function testCustomerCanListOwnQuoteLines(): void
    {
        $this->loginAs(QuoteTestFixtures::CUSTOMER1_EMAIL);
        $this->client->request('GET', '/api/quotes/' . $this->quote1Id() . '/lines');
        $this->assertResponseIsSuccessful();
    }

    public function testCustomerCannotListOtherClientQuoteLines(): void
    {
        $this->loginAs(QuoteTestFixtures::CUSTOMER1_EMAIL);
        $this->client->request('GET', '/api/quotes/' . $this->quote4Id() . '/lines');
        $this->assertResponseStatusCodeSame(403);
    }

    // ── POST /api/quotes/{id}/lines ───────────────────────────

    public function testCustomerCannotAddLine(): void
    {
        $this->loginAs(QuoteTestFixtures::CUSTOMER1_EMAIL);
        $this->client->request('POST', '/api/quotes/' . $this->quote1Id() . '/lines', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'partId' => $this->part2Id(), 'quantity' => 1,
        ]));
        $this->assertResponseStatusCodeSame(403);
    }

    public function testSellerCanAddFinishedPartLine(): void
    {
        $this->client->request('POST', '/api/quotes/' . $this->quote1Id() . '/lines', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'partId' => $this->part2Id(), 'quantity' => 3,
        ]));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame($this->part2Id(), $data['part']['id']);
        $this->assertSame(3, $data['quantity']);
    }

    public function testTotalAmountUpdatedAfterAddingLine(): void
    {
        // quote1 démarre avec quote1Line : qty=2, unitPrice=100 → totalAmount=200
        // On ajoute part2 (salePrice=200) qty=1 → nouveau total = 200 + 200 = 400
        $this->client->request('POST', '/api/quotes/' . $this->quote1Id() . '/lines', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'partId' => $this->part2Id(), 'quantity' => 1,
        ]));
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/quotes/' . $this->quote1Id());
        $quote = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('400.00', $quote['totalAmount']);
    }

    public function testUnitPriceIsAutoSetFromPartSalePrice(): void
    {
        $part2 = $this->fixtures->getReference(QuoteTestFixtures::REF_PART2, Part::class);

        $this->client->request('POST', '/api/quotes/' . $this->quote1Id() . '/lines', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'partId' => $part2->getId(), 'quantity' => 1,
        ]));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame((string) $part2->getSalePrice(), $data['unitPrice']);
    }

    public function testCannotAddNonFinishedPart(): void
    {
        $this->client->request('POST', '/api/quotes/' . $this->quote1Id() . '/lines', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'partId' => $this->part3Id(), 'quantity' => 1,
        ]));
        $this->assertResponseStatusCodeSame(422);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCannotAddDuplicatePart(): void
    {
        // quote1 already has part1
        $this->client->request('POST', '/api/quotes/' . $this->quote1Id() . '/lines', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'partId' => $this->part1Id(), 'quantity' => 1,
        ]));
        $this->assertResponseStatusCodeSame(409);
    }

    public function testCannotAddLineToAcceptedQuote(): void
    {
        $this->client->request('POST', '/api/quotes/' . $this->quote2Id() . '/lines', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'partId' => $this->part2Id(), 'quantity' => 1,
        ]));
        $this->assertResponseStatusCodeSame(422);
    }

    public function testCannotAddLineToExpiredQuote(): void
    {
        $this->client->request('POST', '/api/quotes/' . $this->quote3Id() . '/lines', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'partId' => $this->part2Id(), 'quantity' => 1,
        ]));
        $this->assertResponseStatusCodeSame(422);
    }

    public function testAddLineWithMissingFieldsReturns400(): void
    {
        $this->client->request('POST', '/api/quotes/' . $this->quote1Id() . '/lines', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'partId' => $this->part2Id(),
            // quantity manquant
        ]));
        $this->assertResponseStatusCodeSame(400);
    }

    // ── PUT /api/quotes/{id}/lines/{lineId} ───────────────────

    public function testCustomerCannotUpdateLine(): void
    {
        $this->loginAs(QuoteTestFixtures::CUSTOMER1_EMAIL);
        $this->client->request('PUT', '/api/quotes/' . $this->quote1Id() . '/lines/' . $this->quote1LineId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'quantity' => 5,
        ]));
        $this->assertResponseStatusCodeSame(403);
    }

    public function testSellerCanUpdateLineQuantity(): void
    {
        $this->client->request('PUT', '/api/quotes/' . $this->quote1Id() . '/lines/' . $this->quote1LineId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'quantity' => 10,
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(10, $data['quantity']);
    }

    public function testTotalAmountUpdatedAfterUpdatingQuantity(): void
    {
        // quote1Line : unitPrice=100, qty=2 → total=200 ; on passe qty à 5 → total=500
        $this->client->request('PUT', '/api/quotes/' . $this->quote1Id() . '/lines/' . $this->quote1LineId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'quantity' => 5,
        ]));
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/api/quotes/' . $this->quote1Id());
        $quote = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('500.00', $quote['totalAmount']);
    }

    public function testCannotUpdateLineInAcceptedQuote(): void
    {
        // quote2 est ACCEPTED → 422
        $this->client->request('PUT', '/api/quotes/' . $this->quote2Id() . '/lines/' . $this->quote2LineId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'quantity' => 5,
        ]));
        $this->assertResponseStatusCodeSame(422);
    }

    public function testCannotUpdateLineQuantityIfAlreadyOrdered(): void
    {
        // Setup : ajouter une 2e ligne à quote1 (pour qu'il reste PENDING après avoir commandé seulement quote1Line)
        $this->client->request('POST', '/api/quotes/' . $this->quote1Id() . '/lines', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'partId' => $this->part2Id(), 'quantity' => 1,
        ]));
        $this->assertResponseStatusCodeSame(201);

        // Créer une commande avec seulement quote1Line → quote1 reste PENDING (1 ligne sur 2 commandée)
        $this->client->request('POST', '/api/orders', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'quoteLineIds' => [$this->quote1LineId()],
        ]));
        $this->assertResponseStatusCodeSame(201);

        // Tenter de modifier la quantité de la ligne commandée → 422
        $this->client->request('PUT', '/api/quotes/' . $this->quote1Id() . '/lines/' . $this->quote1LineId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'quantity' => 99,
        ]));
        $this->assertResponseStatusCodeSame(422);
    }

    public function testUpdateLineFromDifferentQuoteReturns404(): void
    {
        // quote2Line belongs to quote2, not quote1
        $this->client->request('PUT', '/api/quotes/' . $this->quote1Id() . '/lines/' . $this->quote2LineId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'quantity' => 5,
        ]));
        $this->assertResponseStatusCodeSame(404);
    }

    // ── DELETE /api/quotes/{id}/lines/{lineId} ────────────────

    public function testCustomerCannotDeleteLine(): void
    {
        $this->loginAs(QuoteTestFixtures::CUSTOMER1_EMAIL);
        $this->client->request('DELETE', '/api/quotes/' . $this->quote1Id() . '/lines/' . $this->quote1LineId());
        $this->assertResponseStatusCodeSame(403);
    }

    public function testSellerCanDeleteLine(): void
    {
        $this->client->request('DELETE', '/api/quotes/' . $this->quote1Id() . '/lines/' . $this->quote1LineId());
        $this->assertResponseStatusCodeSame(204);

        // Vérification : la ligne n'existe plus
        $this->client->request('GET', '/api/quotes/' . $this->quote1Id() . '/lines');
        $lines = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertCount(0, $lines);
    }

    public function testTotalAmountUpdatedAfterDeletingLine(): void
    {
        // quote1Line : qty=2, unitPrice=100 → totalAmount=200 ; après suppression → 0
        $this->client->request('DELETE', '/api/quotes/' . $this->quote1Id() . '/lines/' . $this->quote1LineId());
        $this->assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/quotes/' . $this->quote1Id());
        $quote = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('0.00', $quote['totalAmount']);
    }

    public function testCannotDeleteLineInAcceptedQuote(): void
    {
        // quote2 est ACCEPTED → 422
        $this->client->request('DELETE', '/api/quotes/' . $this->quote2Id() . '/lines/' . $this->quote2LineId());
        $this->assertResponseStatusCodeSame(422);
    }

    public function testCannotDeleteLineIfAlreadyOrdered(): void
    {
        // Setup : ajouter une 2e ligne à quote1 (pour qu'il reste PENDING après avoir commandé seulement quote1Line)
        $this->client->request('POST', '/api/quotes/' . $this->quote1Id() . '/lines', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'partId' => $this->part2Id(), 'quantity' => 1,
        ]));
        $this->assertResponseStatusCodeSame(201);

        // Créer une commande avec seulement quote1Line → quote1 reste PENDING
        $this->client->request('POST', '/api/orders', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'quoteLineIds' => [$this->quote1LineId()],
        ]));
        $this->assertResponseStatusCodeSame(201);

        // Tenter de supprimer la ligne commandée → 422
        $this->client->request('DELETE', '/api/quotes/' . $this->quote1Id() . '/lines/' . $this->quote1LineId());
        $this->assertResponseStatusCodeSame(422);
    }

    public function testDeleteLineFromDifferentQuoteReturns404(): void
    {
        $this->client->request('DELETE', '/api/quotes/' . $this->quote1Id() . '/lines/' . $this->quote2LineId());
        $this->assertResponseStatusCodeSame(404);
    }
}
