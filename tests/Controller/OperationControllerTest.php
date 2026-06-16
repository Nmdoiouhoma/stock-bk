<?php

namespace App\Tests\Controller;

use App\Entity\Machine;
use App\Entity\Operation;
use App\Entity\Routing;
use App\Entity\Workstation;
use App\Tests\DataFixtures\OperationTestFixtures;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class OperationControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private OperationTestFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em     = static::getContainer()->get(EntityManagerInterface::class);

        $hasher         = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->fixtures = new OperationTestFixtures($hasher);

        $executor = new ORMExecutor($this->em, new ORMPurger());
        $executor->execute([$this->fixtures]);

        $this->loginAs(OperationTestFixtures::WORKER_EMAIL);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    private function loginAs(string $email, string $password = OperationTestFixtures::TEST_PASSWORD): void
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

    private function wsId(): int
    {
        return $this->fixtures->getReference(OperationTestFixtures::REF_WORKSTATION, Workstation::class)->getId();
    }

    private function ws2Id(): int
    {
        return $this->fixtures->getReference(OperationTestFixtures::REF_WORKSTATION2, Workstation::class)->getId();
    }

    private function machineId(): int
    {
        return $this->fixtures->getReference(OperationTestFixtures::REF_MACHINE, Machine::class)->getId();
    }

    private function routingId(): int
    {
        return $this->fixtures->getReference(OperationTestFixtures::REF_ROUTING, \App\Entity\Routing::class)->getId();
    }

    private function op1Id(): int
    {
        return $this->fixtures->getReference(OperationTestFixtures::REF_OPERATION1, Operation::class)->getId();
    }

    private function op2Id(): int
    {
        return $this->fixtures->getReference(OperationTestFixtures::REF_OPERATION2, Operation::class)->getId();
    }

    // ── Authentication & Authorisation ───────────────────────

    public function testUnauthenticatedRequestReturns403(): void
    {
        // Le firewall JWT est lazy et /api/operations n'est pas dans access_control :
        // la requête passe, le contrôleur lève une AccessDeniedHttpException → 403.
        $this->client->setServerParameters([]);
        $this->client->request('GET', '/api/operations');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testWorkerCannotCreateReturns403(): void
    {
        $this->client->request('POST', '/api/operations', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label'         => 'OPTEST NEW',
            'unitTime'      => 5.0,
            'routingId'     => $this->routingId(),
            'workstationId' => $this->wsId(),
        ]));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testWorkerCannotUpdateReturns403(): void
    {
        $this->client->request('PUT', '/api/operations/' . $this->op1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => 'Updated',
        ]));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testWorkerCannotDeleteReturns403(): void
    {
        $this->client->request('DELETE', '/api/operations/' . $this->op1Id());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testWorkerCannotMoveReturns403(): void
    {
        $this->client->request('POST', '/api/operations/' . $this->op2Id() . '/move', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'direction' => 'up',
        ]));

        $this->assertResponseStatusCodeSame(403);
    }

    // ── GET /api/operations ─────────────────────────────────

    public function testIndexReturnsSuccessfulJsonArray(): void
    {
        $this->client->request('GET', '/api/operations');

        $this->assertResponseIsSuccessful();
        $this->assertIsArray(json_decode($this->client->getResponse()->getContent(), true));
    }

    public function testIndexIncludesFixtureOperations(): void
    {
        $this->client->request('GET', '/api/operations');

        $this->assertResponseIsSuccessful();
        $data   = json_decode($this->client->getResponse()->getContent(), true);
        $labels = array_column($data, 'label');
        $this->assertContains('OPTEST 001', $labels);
        $this->assertContains('OPTEST 002', $labels);
    }

    public function testIndexReturnsOperationsOrderedByRank(): void
    {
        $this->client->request('GET', '/api/operations');

        $data  = json_decode($this->client->getResponse()->getContent(), true);
        $ranks = array_column($data, 'rank');
        $sorted = $ranks;
        sort($sorted);
        $this->assertSame($sorted, $ranks);
    }

    // ── GET /api/operations/{id} ────────────────────────────

    public function testShowReturnsCorrectOperationData(): void
    {
        $id = $this->op1Id();
        $this->client->request('GET', '/api/operations/' . $id);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame($id, $data['id']);
        $this->assertSame('OPTEST 001', $data['label']);
        $this->assertEquals(10.0, $data['unitTime']); // 10.0 → JSON int 10 → decoded as int
        $this->assertSame(1, $data['rank']);
        $this->assertArrayHasKey('routing', $data);
        $this->assertArrayHasKey('workstation', $data);
        $this->assertArrayHasKey('machine', $data);
        $this->assertNull($data['machine']);
    }

    public function testShowReturns404ForUnknownOperation(): void
    {
        $this->client->request('GET', '/api/operations/999999');

        $this->assertResponseStatusCodeSame(404);
    }

    // ── POST /api/operations ────────────────────────────────

    public function testCreateOperationReturns201WithCorrectData(): void
    {
        $this->loginAs(OperationTestFixtures::ADMIN_EMAIL);

        $this->client->request('POST', '/api/operations', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label'         => 'OPTEST NEW',
            'unitTime'      => 15.5,
            'routingId'     => $this->routingId(),
            'workstationId' => $this->wsId(),
        ]));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $data);
        $this->assertSame('OPTEST NEW', $data['label']);
        $this->assertSame(15.5, $data['unitTime']);
        $this->assertSame(3, $data['rank']); // fixture already has rank 1 and 2
        $this->assertSame($this->routingId(), $data['routing']['id']);
        $this->assertSame($this->wsId(), $data['workstation']['id']);
        $this->assertNull($data['machine']);
    }

    public function testCreateOperationWithMachineReturns201(): void
    {
        $this->loginAs(OperationTestFixtures::ADMIN_EMAIL);

        $this->client->request('POST', '/api/operations', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label'         => 'OPTEST NEW',
            'unitTime'      => 5.0,
            'routingId'     => $this->routingId(),
            'workstationId' => $this->wsId(),
            'machineId'     => $this->machineId(),
        ]));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame($this->machineId(), $data['machine']['id']);
    }

    public function testCreateWithInvalidJsonReturnsBadRequest(): void
    {
        $this->loginAs(OperationTestFixtures::ADMIN_EMAIL);

        $this->client->request('POST', '/api/operations', [], [], ['CONTENT_TYPE' => 'application/json'], 'not-valid-json');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateWithMissingLabelReturnsBadRequest(): void
    {
        $this->loginAs(OperationTestFixtures::ADMIN_EMAIL);

        $this->client->request('POST', '/api/operations', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'unitTime' => 5.0, 'routingId' => $this->routingId(), 'workstationId' => $this->wsId(),
        ]));

        $this->assertResponseStatusCodeSame(400);
        $this->assertArrayHasKey('error', json_decode($this->client->getResponse()->getContent(), true));
    }

    public function testCreateWithMissingUnitTimeReturnsBadRequest(): void
    {
        $this->loginAs(OperationTestFixtures::ADMIN_EMAIL);

        $this->client->request('POST', '/api/operations', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => 'OPTEST NEW', 'routingId' => $this->routingId(), 'workstationId' => $this->wsId(),
        ]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateWithMissingRoutingIdReturnsBadRequest(): void
    {
        $this->loginAs(OperationTestFixtures::ADMIN_EMAIL);

        $this->client->request('POST', '/api/operations', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => 'OPTEST NEW', 'unitTime' => 5.0, 'workstationId' => $this->wsId(),
        ]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateWithUnknownRoutingReturns404(): void
    {
        $this->loginAs(OperationTestFixtures::ADMIN_EMAIL);

        $this->client->request('POST', '/api/operations', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => 'OPTEST NEW', 'unitTime' => 5.0, 'routingId' => 999999, 'workstationId' => $this->wsId(),
        ]));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testCreateWithMissingWorkstationIdReturnsBadRequest(): void
    {
        $this->loginAs(OperationTestFixtures::ADMIN_EMAIL);

        $this->client->request('POST', '/api/operations', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => 'OPTEST NEW', 'unitTime' => 5.0, 'routingId' => $this->routingId(),
        ]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateWithUnknownWorkstationReturns404(): void
    {
        $this->loginAs(OperationTestFixtures::ADMIN_EMAIL);

        $this->client->request('POST', '/api/operations', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => 'OPTEST NEW', 'unitTime' => 5.0, 'routingId' => $this->routingId(), 'workstationId' => 999999,
        ]));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testCreateWithUnknownMachineReturns404(): void
    {
        $this->loginAs(OperationTestFixtures::ADMIN_EMAIL);

        $this->client->request('POST', '/api/operations', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => 'OPTEST NEW', 'unitTime' => 5.0,
            'routingId' => $this->routingId(), 'workstationId' => $this->wsId(),
            'machineId' => 999999,
        ]));

        $this->assertResponseStatusCodeSame(404);
    }

    // ── PUT /api/operations/{id} ────────────────────────────

    public function testUpdateChangesLabel(): void
    {
        $this->loginAs(OperationTestFixtures::ADMIN_EMAIL);

        $this->client->request('PUT', '/api/operations/' . $this->op1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => 'OPTEST UPDATED',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('OPTEST UPDATED', $data['label']);
        $this->assertEquals(10.0, $data['unitTime']); // unchanged
    }

    public function testUpdateChangesWorkstation(): void
    {
        $this->loginAs(OperationTestFixtures::ADMIN_EMAIL);

        $this->client->request('PUT', '/api/operations/' . $this->op1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'workstationId' => $this->ws2Id(),
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame($this->ws2Id(), $data['workstation']['id']);
    }

    public function testUpdateAssignsMachine(): void
    {
        $this->loginAs(OperationTestFixtures::ADMIN_EMAIL);

        $this->client->request('PUT', '/api/operations/' . $this->op1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'machineId' => $this->machineId(),
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame($this->machineId(), $data['machine']['id']);
    }

    public function testUpdateClearsMachineWhenMachineIdIsNull(): void
    {
        $this->loginAs(OperationTestFixtures::ADMIN_EMAIL);

        // First assign a machine
        $this->client->request('PUT', '/api/operations/' . $this->op1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'machineId' => $this->machineId(),
        ]));

        // Then clear it
        $this->client->request('PUT', '/api/operations/' . $this->op1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'machineId' => null,
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertNull($data['machine']);
    }

    public function testUpdateWithInvalidJsonReturnsBadRequest(): void
    {
        $this->loginAs(OperationTestFixtures::ADMIN_EMAIL);

        $this->client->request('PUT', '/api/operations/' . $this->op1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], 'bad-json');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testUpdateWithEmptyLabelReturnsBadRequest(): void
    {
        $this->loginAs(OperationTestFixtures::ADMIN_EMAIL);

        $this->client->request('PUT', '/api/operations/' . $this->op1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => '   ',
        ]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testUpdateWithUnknownWorkstationReturns404(): void
    {
        $this->loginAs(OperationTestFixtures::ADMIN_EMAIL);

        $this->client->request('PUT', '/api/operations/' . $this->op1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'workstationId' => 999999,
        ]));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testUpdateWithUnknownMachineReturns404(): void
    {
        $this->loginAs(OperationTestFixtures::ADMIN_EMAIL);

        $this->client->request('PUT', '/api/operations/' . $this->op1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'machineId' => 999999,
        ]));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testUpdateReturns404ForUnknownOperation(): void
    {
        $this->loginAs(OperationTestFixtures::ADMIN_EMAIL);

        $this->client->request('PUT', '/api/operations/999999', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => 'X',
        ]));

        $this->assertResponseStatusCodeSame(404);
    }

    // ── DELETE /api/operations/{id} ─────────────────────────

    public function testDeleteOperationReturns204(): void
    {
        $this->loginAs(OperationTestFixtures::ADMIN_EMAIL);

        $this->client->request('DELETE', '/api/operations/' . $this->op1Id());

        $this->assertResponseStatusCodeSame(204);
    }

    public function testDeletedOperationIsNoLongerAccessible(): void
    {
        $id = $this->op1Id();

        $this->loginAs(OperationTestFixtures::ADMIN_EMAIL);
        $this->client->request('DELETE', '/api/operations/' . $id);
        $this->assertResponseStatusCodeSame(204);

        $this->loginAs(OperationTestFixtures::WORKER_EMAIL);
        $this->client->request('GET', '/api/operations/' . $id);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteReturns404ForUnknownOperation(): void
    {
        $this->loginAs(OperationTestFixtures::ADMIN_EMAIL);

        $this->client->request('DELETE', '/api/operations/999999');

        $this->assertResponseStatusCodeSame(404);
    }

    // ── POST /api/operations/{id}/move ──────────────────────

    public function testMoveDownSwapsRanks(): void
    {
        $this->loginAs(OperationTestFixtures::ADMIN_EMAIL);

        $this->client->request('POST', '/api/operations/' . $this->op1Id() . '/move', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'direction' => 'down',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(2, $data['rank']); // op1 a pris le rang de op2

        $this->client->request('GET', '/api/operations/' . $this->op2Id());
        $data2 = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(1, $data2['rank']); // op2 a pris le rang de op1
    }

    public function testMoveUpSwapsRanks(): void
    {
        $this->loginAs(OperationTestFixtures::ADMIN_EMAIL);

        $this->client->request('POST', '/api/operations/' . $this->op2Id() . '/move', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'direction' => 'up',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(1, $data['rank']); // op2 a pris le rang de op1

        $this->client->request('GET', '/api/operations/' . $this->op1Id());
        $data1 = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(2, $data1['rank']); // op1 a pris le rang de op2
    }

    public function testMoveFirstOperationUpReturnsBadRequest(): void
    {
        $this->loginAs(OperationTestFixtures::ADMIN_EMAIL);

        $this->client->request('POST', '/api/operations/' . $this->op1Id() . '/move', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'direction' => 'up',
        ]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testMoveLastOperationDownReturnsBadRequest(): void
    {
        $this->loginAs(OperationTestFixtures::ADMIN_EMAIL);

        $this->client->request('POST', '/api/operations/' . $this->op2Id() . '/move', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'direction' => 'down',
        ]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testMoveWithInvalidDirectionReturnsBadRequest(): void
    {
        $this->loginAs(OperationTestFixtures::ADMIN_EMAIL);

        $this->client->request('POST', '/api/operations/' . $this->op1Id() . '/move', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'direction' => 'left',
        ]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testMoveWithMissingDirectionReturnsBadRequest(): void
    {
        $this->loginAs(OperationTestFixtures::ADMIN_EMAIL);

        $this->client->request('POST', '/api/operations/' . $this->op1Id() . '/move', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testMoveReturns404ForUnknownOperation(): void
    {
        $this->loginAs(OperationTestFixtures::ADMIN_EMAIL);

        $this->client->request('POST', '/api/operations/999999/move', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'direction' => 'up',
        ]));

        $this->assertResponseStatusCodeSame(404);
    }
}
