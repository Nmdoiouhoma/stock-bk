<?php

namespace App\Tests\Controller;

use App\Entity\Operation;
use App\Entity\Part;
use App\Tests\DataFixtures\ProductionOrderTestFixtures;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ProductionOrderControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ProductionOrderTestFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em     = static::getContainer()->get(EntityManagerInterface::class);

        $hasher         = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->fixtures = new ProductionOrderTestFixtures($hasher);

        $executor = new ORMExecutor($this->em, new ORMPurger());
        $executor->execute([$this->fixtures]);

        $this->loginAs(ProductionOrderTestFixtures::WORKER_EMAIL);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    private function loginAs(string $email, string $password = ProductionOrderTestFixtures::TEST_PASSWORD): void
    {
        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email'    => $email,
            'password' => $password,
        ]));

        $data  = json_decode($this->client->getResponse()->getContent(), true);
        $token = $data['token'] ?? null;
        $this->assertNotNull($token, "Login failed for $email");

        $this->client->setServerParameters([
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
    }

    private function opId(): int
    {
        return $this->fixtures->getReference(ProductionOrderTestFixtures::REF_OPERATION, Operation::class)->getId();
    }

    private function op2Id(): int
    {
        return $this->fixtures->getReference(ProductionOrderTestFixtures::REF_OPERATION2, Operation::class)->getId();
    }

    private function order1Id(): int
    {
        return $this->fixtures->getReference(ProductionOrderTestFixtures::REF_ORDER1, \App\Entity\ProductionOrder::class)->getId();
    }

    private function order2Id(): int
    {
        return $this->fixtures->getReference(ProductionOrderTestFixtures::REF_ORDER2, \App\Entity\ProductionOrder::class)->getId();
    }

    private function partId(): int
    {
        return $this->fixtures->getReference(ProductionOrderTestFixtures::REF_PART, Part::class)->getId();
    }

    private function baseUrl(): string
    {
        return '/api/operations/' . $this->opId() . '/production-orders';
    }

    // ── Authentication & Authorisation ───────────────────────

    public function testUnauthenticatedRequestReturns401(): void
    {
        $this->client->setServerParameters([]);
        $this->client->request('GET', $this->baseUrl());

        $this->assertResponseStatusCodeSame(401);
    }

    public function testWorkerCannotCreateReturns403(): void
    {
        $this->client->request('POST', $this->baseUrl(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'plannedDate'     => '2026-08-01',
            'plannedQuantity' => 50,
        ]));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testWorkerCannotDeleteReturns403(): void
    {
        $this->client->request('DELETE', $this->baseUrl() . '/' . $this->order1Id());

        $this->assertResponseStatusCodeSame(403);
    }

    // ── GET /api/operations/{id}/production-orders ──────────

    public function testIndexReturnsOrdersForOperation(): void
    {
        $this->client->request('GET', $this->baseUrl());

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertCount(2, $data);
    }

    public function testIndexReturnsEmptyArrayForOperationWithNoOrders(): void
    {
        $this->client->request('GET', '/api/operations/' . $this->op2Id() . '/production-orders');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame([], $data);
    }

    public function testIndexReturnsOrdersSortedByPlannedDate(): void
    {
        $this->client->request('GET', $this->baseUrl());

        $data  = json_decode($this->client->getResponse()->getContent(), true);
        $dates = array_column($data, 'plannedDate');
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates);
    }

    // ── POST /api/operations/{id}/production-orders ──────────

    public function testCreateReturns201WithCorrectData(): void
    {
        $this->loginAs(ProductionOrderTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('POST', $this->baseUrl(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'plannedDate'     => '2026-08-01',
            'plannedQuantity' => 50,
        ]));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $data);
        $this->assertSame('2026-08-01', $data['plannedDate']);
        $this->assertSame(50, $data['plannedQuantity']);
        $this->assertSame('pending', $data['status']);
        $this->assertNull($data['actualQuantity']);
    }

    public function testCreateWithMissingPlannedDateReturnsBadRequest(): void
    {
        $this->loginAs(ProductionOrderTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('POST', $this->baseUrl(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'plannedQuantity' => 50,
        ]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateWithMissingPlannedQuantityReturnsBadRequest(): void
    {
        $this->loginAs(ProductionOrderTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('POST', $this->baseUrl(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'plannedDate' => '2026-08-01',
        ]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateWithInvalidStatusReturnsBadRequest(): void
    {
        $this->loginAs(ProductionOrderTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('POST', $this->baseUrl(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'plannedDate'     => '2026-08-01',
            'plannedQuantity' => 50,
            'status'          => 'invalid_status',
        ]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateCompletedOrderUpdatesStock(): void
    {
        $this->loginAs(ProductionOrderTestFixtures::SUPERVISOR_EMAIL);

        $partBefore = $this->em->find(Part::class, $this->partId());
        $stockBefore = $partBefore->getStockQuantity();

        $this->client->request('POST', $this->baseUrl(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'plannedDate'     => '2026-08-01',
            'plannedQuantity' => 50,
            'status'          => 'completed',
            'actualQuantity'  => 30,
        ]));

        $this->assertResponseStatusCodeSame(201);

        $this->em->clear();
        $partAfter = $this->em->find(Part::class, $this->partId());
        $this->assertSame($stockBefore + 30, $partAfter->getStockQuantity());
    }

    public function testCreateCompletedWithoutActualQuantityDoesNotUpdateStock(): void
    {
        $this->loginAs(ProductionOrderTestFixtures::SUPERVISOR_EMAIL);

        $partBefore = $this->em->find(Part::class, $this->partId());
        $stockBefore = $partBefore->getStockQuantity();

        $this->client->request('POST', $this->baseUrl(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'plannedDate'     => '2026-08-01',
            'plannedQuantity' => 50,
            'status'          => 'completed',
        ]));

        $this->assertResponseStatusCodeSame(201);

        $this->em->clear();
        $partAfter = $this->em->find(Part::class, $this->partId());
        $this->assertSame($stockBefore, $partAfter->getStockQuantity());
    }

    // ── PUT /api/operations/{id}/production-orders/{oId} ─────

    public function testWorkerCanUpdateStatus(): void
    {
        $this->client->request('PUT', $this->baseUrl() . '/' . $this->order1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'status' => 'completed',
            'actualQuantity' => 80,
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('completed', $data['status']);
        $this->assertSame(80, $data['actualQuantity']);
    }

    public function testWorkerCannotModifyPlannedDateReturns403(): void
    {
        $this->client->request('PUT', $this->baseUrl() . '/' . $this->order1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'plannedDate' => '2026-09-01',
        ]));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testWorkerCannotModifyPlannedQuantityReturns403(): void
    {
        $this->client->request('PUT', $this->baseUrl() . '/' . $this->order1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'plannedQuantity' => 999,
        ]));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testSupervisorCanModifyPlannedDate(): void
    {
        $this->loginAs(ProductionOrderTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('PUT', $this->baseUrl() . '/' . $this->order1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'plannedDate' => '2026-09-01',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('2026-09-01', $data['plannedDate']);
    }

    public function testUpdateToCompletedUpdatesStock(): void
    {
        $partBefore = $this->em->find(Part::class, $this->partId());
        $stockBefore = $partBefore->getStockQuantity();

        $this->client->request('PUT', $this->baseUrl() . '/' . $this->order1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'status'         => 'completed',
            'actualQuantity' => 75,
        ]));

        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $partAfter = $this->em->find(Part::class, $this->partId());
        $this->assertSame($stockBefore + 75, $partAfter->getStockQuantity());
    }

    public function testUpdateReturns404ForUnknownOrder(): void
    {
        $this->client->request('PUT', $this->baseUrl() . '/999999', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'status' => 'completed',
        ]));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testUpdateReturns404WhenOrderBelongsToOtherOperation(): void
    {
        $url = '/api/operations/' . $this->op2Id() . '/production-orders/' . $this->order1Id();

        $this->client->request('PUT', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'status' => 'completed',
        ]));

        $this->assertResponseStatusCodeSame(404);
    }

    // ── DELETE /api/operations/{id}/production-orders/{oId} ──

    public function testSupervisorCanDeleteOrder(): void
    {
        $this->loginAs(ProductionOrderTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('DELETE', $this->baseUrl() . '/' . $this->order1Id());

        $this->assertResponseStatusCodeSame(204);
    }

    public function testDeleteCompletedOrderRestoresStock(): void
    {
        $this->loginAs(ProductionOrderTestFixtures::WORKER_EMAIL);
        $this->client->request('PUT', $this->baseUrl() . '/' . $this->order1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'status'         => 'completed',
            'actualQuantity' => 60,
        ]));
        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $stockAfterComplete = $this->em->find(Part::class, $this->partId())->getStockQuantity();

        $this->loginAs(ProductionOrderTestFixtures::SUPERVISOR_EMAIL);
        $this->client->request('DELETE', $this->baseUrl() . '/' . $this->order1Id());
        $this->assertResponseStatusCodeSame(204);

        $this->em->clear();
        $stockAfterDelete = $this->em->find(Part::class, $this->partId())->getStockQuantity();
        $this->assertSame($stockAfterComplete - 60, $stockAfterDelete);
    }

    public function testDeleteReturns404ForUnknownOrder(): void
    {
        $this->loginAs(ProductionOrderTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('DELETE', $this->baseUrl() . '/999999');

        $this->assertResponseStatusCodeSame(404);
    }
}
