<?php

namespace App\Tests\Controller;

use App\Entity\Machine;
use App\Entity\Operation;
use App\Entity\Part;
use App\Entity\Routing;
use App\Entity\User;
use App\Entity\Workstation;
use App\Enum\PieceType;
use App\Enum\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class MachineControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    private const REF_PREFIX    = 'MTEST-';
    private const WS_PREFIX     = 'WSMT-';
    private const PART_PREFIX   = 'PRTMT-';
    private const RT_PREFIX     = 'RTMT-';
    private const WORKER_EMAIL  = 'worker@mtest.com';
    private const ADMIN_EMAIL   = 'admin@mtest.com';
    private const TEST_PASSWORD = 'test_password';

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanTestMachines();
        $this->cleanTestWorkstations();
        $this->cleanTestRoutings();
        $this->cleanTestParts();
        $this->cleanTestUsers();
        $this->createTestUser(Role::Worker, self::WORKER_EMAIL);
        $this->createTestUser(Role::Admin, self::ADMIN_EMAIL);
        $this->loginAs(self::WORKER_EMAIL);
    }

    protected function tearDown(): void
    {
        $this->cleanTestMachines();
        $this->cleanTestWorkstations();
        $this->cleanTestRoutings();
        $this->cleanTestParts();
        $this->cleanTestUsers();
        parent::tearDown();
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function loginAs(string $email, string $password = self::TEST_PASSWORD): void
    {
        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email'    => $email,
            'password' => $password,
        ]));

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $token = $data['token'] ?? null;
        $this->assertNotNull($token, "Login failed for $email");

        $this->client->setServerParameters([
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
    }

    private function createTestUser(Role $role, string $email): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())
            ->setFirstname('Test')
            ->setLastname('User')
            ->setEmail($email)
            ->setRole($role);
        $user->setPassword($hasher->hashPassword($user, self::TEST_PASSWORD));
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        return $user;
    }

    private function createTestWorkstation(string $suffix = '001'): Workstation
    {
        $ws = (new Workstation())
            ->setReference(self::WS_PREFIX . $suffix)
            ->setLabel('Test WS ' . $suffix);
        $this->em->persist($ws);
        $this->em->flush();
        $this->em->clear();

        return $ws;
    }

    private function createTestMachine(string $suffix = '001', array $workstationIds = []): Machine
    {
        $machine = (new Machine())
            ->setReference(self::REF_PREFIX . $suffix)
            ->setLabel('Test Machine ' . $suffix);

        foreach ($workstationIds as $wsId) {
            $ws = $this->em->find(Workstation::class, $wsId);
            $machine->addWorkstation($ws);
        }

        $this->em->persist($machine);
        $this->em->flush();
        $this->em->clear();

        return $machine;
    }

    private function createTestPart(string $suffix = '001'): Part
    {
        $part = (new Part())
            ->setReference(self::PART_PREFIX . $suffix)
            ->setLabel('Test Part ' . $suffix)
            ->setType(PieceType::RawMaterial);
        $this->em->persist($part);
        $this->em->flush();
        $this->em->clear();

        return $part;
    }

    private function createTestRouting(int $partId, int $supervisorId, string $suffix = '001'): Routing
    {
        $part = $this->em->find(Part::class, $partId);
        $supervisor = $this->em->find(User::class, $supervisorId);
        $routing = (new Routing())
            ->setReference(self::RT_PREFIX . $suffix)
            ->setLabel('Test Routing ' . $suffix)
            ->setPart($part)
            ->setSupervisor($supervisor);
        $this->em->persist($routing);
        $this->em->flush();
        $this->em->clear();

        return $routing;
    }

    private function createTestOperation(int $routingId, int $workstationId, int $machineId): Operation
    {
        $routing     = $this->em->find(Routing::class, $routingId);
        $workstation = $this->em->find(Workstation::class, $workstationId);
        $machine     = $this->em->find(Machine::class, $machineId);

        $op = (new Operation())
            ->setRank(1)
            ->setLabel('Test Operation')
            ->setUnitTime(1.0)
            ->setRouting($routing)
            ->setWorkstation($workstation)
            ->setMachine($machine);
        $this->em->persist($op);
        $this->em->flush();
        $this->em->clear();

        return $op;
    }

    private function getAdminUserId(): int
    {
        return $this->em->createQuery(
            'SELECT u.id FROM App\Entity\User u WHERE u.email = :email'
        )->setParameter('email', self::ADMIN_EMAIL)->getSingleScalarResult();
    }

    // ── Cleanup ──────────────────────────────────────────────────

    private function cleanTestMachines(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'DELETE FROM operation WHERE machine_id IN (SELECT id FROM machine WHERE reference LIKE ?)',
            [self::REF_PREFIX . '%']
        );
        $conn->executeStatement(
            'DELETE FROM machine_workstation WHERE machine_id IN (SELECT id FROM machine WHERE reference LIKE ?)',
            [self::REF_PREFIX . '%']
        );
        $this->em->createQuery(
            'DELETE FROM App\Entity\Machine m WHERE m.reference LIKE :prefix'
        )->setParameter('prefix', self::REF_PREFIX . '%')->execute();
        $this->em->clear();
    }

    private function cleanTestWorkstations(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'DELETE FROM machine_workstation WHERE workstation_id IN (SELECT id FROM workstation WHERE reference LIKE ?)',
            [self::WS_PREFIX . '%']
        );
        $conn->executeStatement(
            'DELETE FROM operation WHERE workstation_id IN (SELECT id FROM workstation WHERE reference LIKE ?)',
            [self::WS_PREFIX . '%']
        );
        $this->em->createQuery(
            'DELETE FROM App\Entity\Workstation w WHERE w.reference LIKE :prefix'
        )->setParameter('prefix', self::WS_PREFIX . '%')->execute();
        $this->em->clear();
    }

    private function cleanTestRoutings(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'DELETE FROM operation WHERE routing_id IN (SELECT id FROM routing WHERE reference LIKE ?)',
            [self::RT_PREFIX . '%']
        );
        $this->em->createQuery(
            'DELETE FROM App\Entity\Routing r WHERE r.reference LIKE :prefix'
        )->setParameter('prefix', self::RT_PREFIX . '%')->execute();
        $this->em->clear();
    }

    private function cleanTestParts(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'DELETE FROM routing WHERE part_id IN (SELECT id FROM part WHERE reference LIKE ?)',
            [self::PART_PREFIX . '%']
        );
        $this->em->createQuery(
            'DELETE FROM App\Entity\Part p WHERE p.reference LIKE :prefix'
        )->setParameter('prefix', self::PART_PREFIX . '%')->execute();
        $this->em->clear();
    }

    private function cleanTestUsers(): void
    {
        $this->em->createQuery(
            'DELETE FROM App\Entity\User u WHERE u.email IN (:emails)'
        )->setParameter('emails', [self::WORKER_EMAIL, self::ADMIN_EMAIL])->execute();
        $this->em->clear();
    }

    // ── Authentication & Authorisation ───────────────────────────

    public function testUnauthenticatedRequestReturns401(): void
    {
        $this->client->setServerParameters([]);
        $this->client->request('GET', '/api/machines');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testWorkerCannotCreateReturns403(): void
    {
        $this->client->request('POST', '/api/machines', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference' => self::REF_PREFIX . '001',
            'label'     => 'Test',
        ]));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testWorkerCannotUpdateReturns403(): void
    {
        $machine = $this->createTestMachine('001');

        $this->client->request('PUT', '/api/machines/' . $machine->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => 'Updated',
        ]));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testWorkerCannotDeleteReturns403(): void
    {
        $machine = $this->createTestMachine('001');

        $this->client->request('DELETE', '/api/machines/' . $machine->getId());

        $this->assertResponseStatusCodeSame(403);
    }

    // ── GET /api/machines ────────────────────────────────────────

    public function testIndexReturnsSuccessfulJsonArray(): void
    {
        $this->client->request('GET', '/api/machines');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testIndexIncludesCreatedMachines(): void
    {
        $this->createTestMachine('001');
        $this->createTestMachine('002');

        $this->client->request('GET', '/api/machines');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $refs = array_column($data, 'reference');
        $this->assertContains(self::REF_PREFIX . '001', $refs);
        $this->assertContains(self::REF_PREFIX . '002', $refs);
    }

    public function testIndexResponseContainsExpectedFields(): void
    {
        $this->createTestMachine('001');

        $this->client->request('GET', '/api/machines');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $item = current(array_filter($data, fn($m) => $m['reference'] === self::REF_PREFIX . '001'));
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('reference', $item);
        $this->assertArrayHasKey('label', $item);
        $this->assertArrayHasKey('workstations', $item);
        $this->assertArrayHasKey('operationsCount', $item);
    }

    // ── GET /api/machines/{id} ───────────────────────────────────

    public function testShowReturnsCorrectMachineData(): void
    {
        $ws1     = $this->createTestWorkstation('001');
        $ws2     = $this->createTestWorkstation('002');
        $machine = $this->createTestMachine('001', [$ws1->getId(), $ws2->getId()]);

        $this->client->request('GET', '/api/machines/' . $machine->getId());

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame($machine->getId(), $data['id']);
        $this->assertSame(self::REF_PREFIX . '001', $data['reference']);
        $this->assertSame('Test Machine 001', $data['label']);
        $this->assertCount(2, $data['workstations']);
        $wsIds = array_column($data['workstations'], 'id');
        $this->assertContains($ws1->getId(), $wsIds);
        $this->assertContains($ws2->getId(), $wsIds);
        $this->assertArrayHasKey('operationsCount', $data);
        $this->assertArrayHasKey('operations', $data);
    }

    public function testShowReturnsEmptyWorkstationsWhenNone(): void
    {
        $machine = $this->createTestMachine('001');

        $this->client->request('GET', '/api/machines/' . $machine->getId());

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame([], $data['workstations']);
    }

    public function testShowReturns404ForUnknownMachine(): void
    {
        $this->client->request('GET', '/api/machines/999999');

        $this->assertResponseStatusCodeSame(404);
    }

    // ── POST /api/machines ───────────────────────────────────────

    public function testCreateMachineReturns201WithCorrectData(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);

        $this->client->request('POST', '/api/machines', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference' => self::REF_PREFIX . '001',
            'label'     => 'Machine de test',
        ]));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $data);
        $this->assertSame(self::REF_PREFIX . '001', $data['reference']);
        $this->assertSame('Machine de test', $data['label']);
        $this->assertSame([], $data['workstations']);
        $this->assertSame(0, $data['operationsCount']);
    }

    public function testCreateMachineWithMultipleWorkstationsReturns201(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $ws1 = $this->createTestWorkstation('001');
        $ws2 = $this->createTestWorkstation('002');

        $this->client->request('POST', '/api/machines', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference'       => self::REF_PREFIX . '001',
            'label'           => 'Machine multi-postes',
            'workstation_ids' => [$ws1->getId(), $ws2->getId()],
        ]));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertCount(2, $data['workstations']);
        $wsIds = array_column($data['workstations'], 'id');
        $this->assertContains($ws1->getId(), $wsIds);
        $this->assertContains($ws2->getId(), $wsIds);
    }

    public function testCreateMachineWithEmptyWorkstationIdsReturns201(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);

        $this->client->request('POST', '/api/machines', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference'       => self::REF_PREFIX . '001',
            'label'           => 'Machine sans poste',
            'workstation_ids' => [],
        ]));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame([], $data['workstations']);
    }

    public function testCreateMachineWithInvalidJsonReturnsBadRequest(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);

        $this->client->request('POST', '/api/machines', [], [], ['CONTENT_TYPE' => 'application/json'], 'not-valid-json');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateMachineWithMissingReferenceReturnsBadRequest(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);

        $this->client->request('POST', '/api/machines', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => 'Machine de test',
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateMachineWithEmptyReferenceReturnsBadRequest(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);

        $this->client->request('POST', '/api/machines', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference' => '   ',
            'label'     => 'Machine de test',
        ]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateMachineWithMissingLabelReturnsBadRequest(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);

        $this->client->request('POST', '/api/machines', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference' => self::REF_PREFIX . '001',
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateMachineWithDuplicateReferenceReturnsConflict(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $this->createTestMachine('001');

        $this->client->request('POST', '/api/machines', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference' => self::REF_PREFIX . '001',
            'label'     => 'Doublon',
        ]));

        $this->assertResponseStatusCodeSame(409);
    }

    public function testCreateMachineWithUnknownWorkstationReturns404(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);

        $this->client->request('POST', '/api/machines', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference'       => self::REF_PREFIX . '001',
            'label'           => 'Machine test',
            'workstation_ids' => [999999],
        ]));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testCreateMachineWithInvalidWorkstationIdsReturnsBadRequest(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);

        $this->client->request('POST', '/api/machines', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference'       => self::REF_PREFIX . '001',
            'label'           => 'Machine test',
            'workstation_ids' => 'invalid',
        ]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateMachineWithNonIntegerWorkstationIdReturnsBadRequest(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);

        $this->client->request('POST', '/api/machines', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference'       => self::REF_PREFIX . '001',
            'label'           => 'Machine test',
            'workstation_ids' => ['abc'],
        ]));

        $this->assertResponseStatusCodeSame(400);
    }

    // ── PUT /api/machines/{id} ───────────────────────────────────

    public function testUpdateChangesSpecifiedFields(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $machine = $this->createTestMachine('001');

        $this->client->request('PUT', '/api/machines/' . $machine->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => 'Machine modifiée',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Machine modifiée', $data['label']);
        $this->assertSame(self::REF_PREFIX . '001', $data['reference']);
    }

    public function testUpdateDoesNotChangeUnspecifiedFields(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $machine = $this->createTestMachine('001');

        $this->client->request('PUT', '/api/machines/' . $machine->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference' => self::REF_PREFIX . 'UPDATED',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(self::REF_PREFIX . 'UPDATED', $data['reference']);
        $this->assertSame('Test Machine 001', $data['label']);
    }

    public function testUpdateWithSameReferenceDoesNotConflict(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $machine = $this->createTestMachine('001');

        $this->client->request('PUT', '/api/machines/' . $machine->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference' => self::REF_PREFIX . '001',
            'label'     => 'Label modifié',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(self::REF_PREFIX . '001', $data['reference']);
        $this->assertSame('Label modifié', $data['label']);
    }

    public function testUpdateReplacesWorkstations(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $ws1     = $this->createTestWorkstation('001');
        $ws2     = $this->createTestWorkstation('002');
        $ws3     = $this->createTestWorkstation('003');
        $machine = $this->createTestMachine('001', [$ws1->getId(), $ws2->getId()]);

        $this->client->request('PUT', '/api/machines/' . $machine->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'workstation_ids' => [$ws3->getId()],
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertCount(1, $data['workstations']);
        $wsRefs = array_column($data['workstations'], 'reference');
        $this->assertContains(self::WS_PREFIX . '003', $wsRefs);
        $this->assertNotContains(self::WS_PREFIX . '001', $wsRefs);
        $this->assertNotContains(self::WS_PREFIX . '002', $wsRefs);
    }

    public function testUpdateClearsAllWorkstations(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $ws      = $this->createTestWorkstation('001');
        $machine = $this->createTestMachine('001', [$ws->getId()]);

        $this->client->request('PUT', '/api/machines/' . $machine->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'workstation_ids' => [],
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame([], $data['workstations']);
    }

    public function testUpdateWithInvalidJsonReturnsBadRequest(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $machine = $this->createTestMachine('001');

        $this->client->request('PUT', '/api/machines/' . $machine->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], 'bad-json');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testUpdateReturns404ForUnknownMachine(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);

        $this->client->request('PUT', '/api/machines/999999', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => 'X',
        ]));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testUpdateWithDuplicateReferenceReturnsConflict(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $this->createTestMachine('001');
        $machine2 = $this->createTestMachine('002');

        $this->client->request('PUT', '/api/machines/' . $machine2->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference' => self::REF_PREFIX . '001',
        ]));

        $this->assertResponseStatusCodeSame(409);
    }

    public function testUpdateWithUnknownWorkstationReturns404(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $machine = $this->createTestMachine('001');

        $this->client->request('PUT', '/api/machines/' . $machine->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'workstation_ids' => [999999],
        ]));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testUpdateWithEmptyReferenceReturnsBadRequest(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $machine = $this->createTestMachine('001');

        $this->client->request('PUT', '/api/machines/' . $machine->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference' => '',
        ]));

        $this->assertResponseStatusCodeSame(400);
    }

    // ── DELETE /api/machines/{id} ────────────────────────────────

    public function testDeleteMachineReturns204(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $machine = $this->createTestMachine('001');

        $this->client->request('DELETE', '/api/machines/' . $machine->getId());

        $this->assertResponseStatusCodeSame(204);
    }

    public function testDeletedMachineIsNoLongerAccessible(): void
    {
        $machine = $this->createTestMachine('001');
        $id = $machine->getId();

        $this->loginAs(self::ADMIN_EMAIL);
        $this->client->request('DELETE', '/api/machines/' . $id);
        $this->assertResponseStatusCodeSame(204);

        $this->loginAs(self::WORKER_EMAIL);
        $this->client->request('GET', '/api/machines/' . $id);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteReturns404ForUnknownMachine(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);

        $this->client->request('DELETE', '/api/machines/999999');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteMachineWithLinkedOperationsReturns409(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);

        $ws      = $this->createTestWorkstation('001');
        $machine = $this->createTestMachine('001', [$ws->getId()]);
        $part    = $this->createTestPart('001');
        $routing = $this->createTestRouting($part->getId(), $this->getAdminUserId(), '001');
        $this->createTestOperation($routing->getId(), $ws->getId(), $machine->getId());

        $this->client->request('DELETE', '/api/machines/' . $machine->getId());

        $this->assertResponseStatusCodeSame(409);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testShowOperationsCountReflectsLinkedOperations(): void
    {
        $ws      = $this->createTestWorkstation('001');
        $machine = $this->createTestMachine('001', [$ws->getId()]);
        $part    = $this->createTestPart('001');
        $routing = $this->createTestRouting($part->getId(), $this->getAdminUserId(), '001');
        $this->createTestOperation($routing->getId(), $ws->getId(), $machine->getId());

        $this->client->request('GET', '/api/machines/' . $machine->getId());

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(1, $data['operationsCount']);
        $this->assertCount(1, $data['operations']);
        $this->assertSame('Test Operation', $data['operations'][0]['label']);
    }
}
