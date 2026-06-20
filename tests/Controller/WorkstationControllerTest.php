<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\Workstation;
use App\Enum\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class WorkstationControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    private const REF_PREFIX    = 'WSTEST-';
    private const WORKER_EMAIL  = 'worker@wstest.com';
    private const ADMIN_EMAIL   = 'admin@wstest.com';
    private const TEST_PASSWORD = 'test_password';

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanTestWorkstations();
        $this->cleanTestUsers();
        $this->createTestUser(Role::Worker, self::WORKER_EMAIL);
        $this->createTestUser(Role::Admin, self::ADMIN_EMAIL);
        $this->loginAs(self::WORKER_EMAIL);
    }

    protected function tearDown(): void
    {
        $this->cleanTestWorkstations();
        $this->cleanTestUsers();
        parent::tearDown();
    }

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

    private function createTestWorkstation(string $suffix = '001', ?int $capacity = null): Workstation
    {
        $workstation = (new Workstation())
            ->setReference(self::REF_PREFIX . $suffix)
            ->setLabel('Test Workstation ' . $suffix)
            ->setCapacity($capacity);
        $this->em->persist($workstation);
        $this->em->flush();
        $this->em->clear();

        return $workstation;
    }

    private function cleanTestWorkstations(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'DELETE FROM machine WHERE workstation_id IN (SELECT id FROM workstation WHERE reference LIKE ?)',
            [self::REF_PREFIX . '%']
        );
        $conn->executeStatement(
            'DELETE FROM operation WHERE workstation_id IN (SELECT id FROM workstation WHERE reference LIKE ?)',
            [self::REF_PREFIX . '%']
        );
        $this->em->createQuery(
            'DELETE FROM App\Entity\Workstation w WHERE w.reference LIKE :prefix'
        )->setParameter('prefix', self::REF_PREFIX . '%')->execute();
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
        $this->client->request('GET', '/api/workstations');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testWorkerCannotCreateReturns403(): void
    {
        $this->client->request('POST', '/api/workstations', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference' => self::REF_PREFIX . '001',
            'label'     => 'Test',
        ]));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testWorkerCannotUpdateReturns403(): void
    {
        $workstation = $this->createTestWorkstation('001');

        $this->client->request('PUT', '/api/workstations/' . $workstation->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => 'Updated',
        ]));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testWorkerCannotDeleteReturns403(): void
    {
        $workstation = $this->createTestWorkstation('001');

        $this->client->request('DELETE', '/api/workstations/' . $workstation->getId());

        $this->assertResponseStatusCodeSame(403);
    }

    // ── GET /api/workstations ────────────────────────────────────

    public function testIndexReturnsSuccessfulJsonArray(): void
    {
        $this->client->request('GET', '/api/workstations');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testIndexIncludesCreatedWorkstations(): void
    {
        $this->createTestWorkstation('001');
        $this->createTestWorkstation('002');

        $this->client->request('GET', '/api/workstations');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $refs = array_column($data, 'reference');
        $this->assertContains(self::REF_PREFIX . '001', $refs);
        $this->assertContains(self::REF_PREFIX . '002', $refs);
    }

    // ── GET /api/workstations/{id} ──────────────────────────────

    public function testShowReturnsCorrectWorkstationData(): void
    {
        $workstation = $this->createTestWorkstation('001', 10);

        $this->client->request('GET', '/api/workstations/' . $workstation->getId());

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame($workstation->getId(), $data['id']);
        $this->assertSame(self::REF_PREFIX . '001', $data['reference']);
        $this->assertSame('Test Workstation 001', $data['label']);
        $this->assertSame(10, $data['capacity']);
        $this->assertArrayHasKey('machinesCount', $data);
        $this->assertArrayHasKey('operationsCount', $data);
    }

    public function testShowReturns404ForUnknownWorkstation(): void
    {
        $this->client->request('GET', '/api/workstations/999999');

        $this->assertResponseStatusCodeSame(404);
    }

    // ── POST /api/workstations ──────────────────────────────────

    public function testCreateWorkstationReturns201WithCorrectData(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);

        $this->client->request('POST', '/api/workstations', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference' => self::REF_PREFIX . '001',
            'label'     => 'Poste de test',
            'capacity'  => 5,
        ]));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $data);
        $this->assertSame(self::REF_PREFIX . '001', $data['reference']);
        $this->assertSame('Poste de test', $data['label']);
        $this->assertSame(5, $data['capacity']);
        $this->assertSame(0, $data['machinesCount']);
        $this->assertSame(0, $data['operationsCount']);
    }

    public function testCreateWorkstationWithoutCapacityReturns201(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);

        $this->client->request('POST', '/api/workstations', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference' => self::REF_PREFIX . '001',
            'label'     => 'Poste sans capacité',
        ]));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertNull($data['capacity']);
    }

    public function testCreateWorkstationWithInvalidJsonReturnsBadRequest(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);

        $this->client->request('POST', '/api/workstations', [], [], ['CONTENT_TYPE' => 'application/json'], 'not-valid-json');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateWorkstationWithMissingReferenceReturnsBadRequest(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);

        $this->client->request('POST', '/api/workstations', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => 'Poste de test',
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateWorkstationWithMissingLabelReturnsBadRequest(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);

        $this->client->request('POST', '/api/workstations', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference' => self::REF_PREFIX . '001',
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateWorkstationWithDuplicateReferenceReturnsConflict(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $this->createTestWorkstation('001');

        $this->client->request('POST', '/api/workstations', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference' => self::REF_PREFIX . '001',
            'label'     => 'Doublon',
        ]));

        $this->assertResponseStatusCodeSame(409);
    }

    public function testCreateWorkstationWithInvalidCapacityReturnsBadRequest(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);

        $this->client->request('POST', '/api/workstations', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference' => self::REF_PREFIX . '001',
            'label'     => 'Poste test',
            'capacity'  => 'invalid',
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    // ── PUT /api/workstations/{id} ──────────────────────────────

    public function testUpdateChangesSpecifiedFields(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $workstation = $this->createTestWorkstation('001', 5);

        $this->client->request('PUT', '/api/workstations/' . $workstation->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => 'Poste modifié',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Poste modifié', $data['label']);
        $this->assertSame(self::REF_PREFIX . '001', $data['reference']);
        $this->assertSame(5, $data['capacity']);
    }

    public function testUpdateDoesNotChangeUnspecifiedFields(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $workstation = $this->createTestWorkstation('001', 8);

        $this->client->request('PUT', '/api/workstations/' . $workstation->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference' => self::REF_PREFIX . 'UPDATED',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(self::REF_PREFIX . 'UPDATED', $data['reference']);
        $this->assertSame('Test Workstation 001', $data['label']);
        $this->assertSame(8, $data['capacity']);
    }

    public function testUpdateCapacityToNull(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $workstation = $this->createTestWorkstation('001', 5);

        $this->client->request('PUT', '/api/workstations/' . $workstation->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'capacity' => null,
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertNull($data['capacity']);
    }

    public function testUpdateWithInvalidJsonReturnsBadRequest(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $workstation = $this->createTestWorkstation('001');

        $this->client->request('PUT', '/api/workstations/' . $workstation->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], 'bad-json');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testUpdateReturns404ForUnknownWorkstation(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);

        $this->client->request('PUT', '/api/workstations/999999', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => 'X',
        ]));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testUpdateWithDuplicateReferenceReturnsConflict(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $this->createTestWorkstation('001');
        $workstation2 = $this->createTestWorkstation('002');

        $this->client->request('PUT', '/api/workstations/' . $workstation2->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference' => self::REF_PREFIX . '001',
        ]));

        $this->assertResponseStatusCodeSame(409);
    }

    // ── DELETE /api/workstations/{id} ───────────────────────────

    public function testDeleteWorkstationReturns204(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $workstation = $this->createTestWorkstation('001');

        $this->client->request('DELETE', '/api/workstations/' . $workstation->getId());

        $this->assertResponseStatusCodeSame(204);
    }

    public function testDeletedWorkstationIsNoLongerAccessible(): void
    {
        $workstation = $this->createTestWorkstation('001');
        $id = $workstation->getId();

        $this->loginAs(self::ADMIN_EMAIL);
        $this->client->request('DELETE', '/api/workstations/' . $id);
        $this->assertResponseStatusCodeSame(204);

        $this->loginAs(self::WORKER_EMAIL);
        $this->client->request('GET', '/api/workstations/' . $id);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteReturns404ForUnknownWorkstation(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);

        $this->client->request('DELETE', '/api/workstations/999999');

        $this->assertResponseStatusCodeSame(404);
    }
}
