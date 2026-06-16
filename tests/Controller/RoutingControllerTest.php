<?php

namespace App\Tests\Controller;

use App\Entity\Part;
use App\Entity\Routing;
use App\Entity\User;
use App\Enum\PieceType;
use App\Enum\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class RoutingControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    private const REF_PREFIX        = 'GTEST-';
    private const PART_PREFIX       = 'PGTEST-';
    private const WORKER_EMAIL      = 'worker@gtest.com';
    private const ADMIN_EMAIL       = 'admin@gtest.com';
    private const SUPERVISOR_EMAIL  = 'supervisor@gtest.com';
    private const TEST_PASSWORD     = 'test_password';

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanTestRoutings();
        $this->cleanTestParts();
        $this->cleanTestUsers();
        $this->createTestUser(Role::Worker, self::WORKER_EMAIL);
        $this->createTestUser(Role::Admin, self::ADMIN_EMAIL);
        $this->createTestUser(Role::Supervisor, self::SUPERVISOR_EMAIL);
        $this->loginAs(self::WORKER_EMAIL);
    }

    protected function tearDown(): void
    {
        $this->cleanTestRoutings();
        $this->cleanTestParts();
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

    private function createTestPart(string $suffix = '001', PieceType $type = PieceType::Finished): Part
    {
        $part = (new Part())
            ->setReference(self::PART_PREFIX . $suffix)
            ->setLabel('Test Part ' . $suffix)
            ->setType($type)
            ->setSalePrice(100.0)
            ->setStockQuantity(10)
            ->setStockMin(1);
        $this->em->persist($part);
        $this->em->flush();
        $this->em->clear();

        return $part;
    }

    private function createTestRouting(string $suffix = '001', ?Part $part = null, ?User $supervisor = null): Routing
    {
        if ($part === null) {
            $part = $this->createTestPart($suffix);
        }

        // Re-fetch to ensure entities are managed after any previous em->clear()
        $part       = $this->em->find(Part::class, $part->getId());
        $supervisor = $supervisor
            ? $this->em->find(User::class, $supervisor->getId())
            : $this->em->getRepository(User::class)->findOneBy(['email' => self::SUPERVISOR_EMAIL]);

        $routing = (new Routing())
            ->setReference(self::REF_PREFIX . $suffix)
            ->setLabel('Test Routing ' . $suffix)
            ->setPart($part)
            ->setSupervisor($supervisor);
        $this->em->persist($routing);
        $this->em->flush();
        $this->em->clear();

        return $routing;
    }

    private function getSupervisorId(): int
    {
        return $this->em->getRepository(User::class)->findOneBy(['email' => self::SUPERVISOR_EMAIL])->getId();
    }

    private function getWorkerIdWithRole(Role $role): int
    {
        return $this->em->getRepository(User::class)->findOneBy(['email' => self::WORKER_EMAIL])->getId();
    }

    private function cleanTestRoutings(): void
    {
        $this->em->createQuery(
            'DELETE FROM App\Entity\Routing r WHERE r.reference LIKE :prefix'
        )->setParameter('prefix', self::REF_PREFIX . '%')->execute();
        $this->em->clear();
    }

    private function cleanTestParts(): void
    {
        $this->em->createQuery(
            'DELETE FROM App\Entity\Part p WHERE p.reference LIKE :prefix'
        )->setParameter('prefix', self::PART_PREFIX . '%')->execute();
        $this->em->clear();
    }

    private function cleanTestUsers(): void
    {
        $this->em->createQuery(
            'DELETE FROM App\Entity\User u WHERE u.email IN (:emails)'
        )->setParameter('emails', [self::WORKER_EMAIL, self::ADMIN_EMAIL, self::SUPERVISOR_EMAIL])->execute();
        $this->em->clear();
    }

    // ── Authentication & Authorisation ───────────────────────

    public function testUnauthenticatedRequestReturns401(): void
    {
        $this->client->setServerParameters([]);
        $this->client->request('GET', '/api/routings');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testWorkerCannotCreateReturns403(): void
    {
        $part = $this->createTestPart('001');

        $this->client->request('POST', '/api/routings', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference'    => self::REF_PREFIX . '001',
            'label'        => 'Test',
            'partId'       => $part->getId(),
            'supervisorId' => $this->getSupervisorId(),
        ]));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testWorkerCannotUpdateReturns403(): void
    {
        $routing = $this->createTestRouting('001');

        $this->client->request('PUT', '/api/routings/' . $routing->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => 'Updated',
        ]));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testWorkerCannotDeleteReturns403(): void
    {
        $routing = $this->createTestRouting('001');

        $this->client->request('DELETE', '/api/routings/' . $routing->getId());

        $this->assertResponseStatusCodeSame(403);
    }

    // ── GET /api/routings ───────────────────────────────────────

    public function testIndexReturnsSuccessfulJsonArray(): void
    {
        $this->client->request('GET', '/api/routings');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testIndexIncludesCreatedRoutings(): void
    {
        $this->createTestRouting('001');
        $this->createTestRouting('002', $this->createTestPart('002'));

        $this->client->request('GET', '/api/routings');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $refs = array_column($data, 'reference');
        $this->assertContains(self::REF_PREFIX . '001', $refs);
        $this->assertContains(self::REF_PREFIX . '002', $refs);
    }

    // ── GET /api/routings/{id} ──────────────────────────────────

    public function testShowReturnsCorrectRoutingData(): void
    {
        $routing = $this->createTestRouting('001');

        $this->client->request('GET', '/api/routings/' . $routing->getId());

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame($routing->getId(), $data['id']);
        $this->assertSame(self::REF_PREFIX . '001', $data['reference']);
        $this->assertSame('Test Routing 001', $data['label']);
        $this->assertArrayHasKey('part', $data);
        $this->assertArrayHasKey('supervisor', $data);
        $this->assertArrayHasKey('operationsCount', $data);
        $this->assertSame(self::PART_PREFIX . '001', $data['part']['reference']);
        $this->assertSame(self::SUPERVISOR_EMAIL, $this->em->getRepository(User::class)->find($data['supervisor']['id'])->getEmail());
    }

    public function testShowReturns404ForUnknownRouting(): void
    {
        $this->client->request('GET', '/api/routings/999999');

        $this->assertResponseStatusCodeSame(404);
    }

    // ── POST /api/routings ──────────────────────────────────────

    public function testCreateRoutingReturns201WithCorrectData(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $part = $this->createTestPart('001');

        $this->client->request('POST', '/api/routings', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference'    => self::REF_PREFIX . '001',
            'label'        => 'Gamme test',
            'partId'       => $part->getId(),
            'supervisorId' => $this->getSupervisorId(),
        ]));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $data);
        $this->assertSame(self::REF_PREFIX . '001', $data['reference']);
        $this->assertSame('Gamme test', $data['label']);
        $this->assertSame($part->getId(), $data['part']['id']);
        $this->assertSame($this->getSupervisorId(), $data['supervisor']['id']);
        $this->assertSame(0, $data['operationsCount']);
    }

    public function testCreateRoutingWithInvalidJsonReturnsBadRequest(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);

        $this->client->request('POST', '/api/routings', [], [], ['CONTENT_TYPE' => 'application/json'], 'not-valid-json');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateRoutingWithMissingReferenceReturnsBadRequest(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $part = $this->createTestPart('001');

        $this->client->request('POST', '/api/routings', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label'        => 'Gamme test',
            'partId'       => $part->getId(),
            'supervisorId' => $this->getSupervisorId(),
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateRoutingWithMissingLabelReturnsBadRequest(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $part = $this->createTestPart('001');

        $this->client->request('POST', '/api/routings', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference'    => self::REF_PREFIX . '001',
            'partId'       => $part->getId(),
            'supervisorId' => $this->getSupervisorId(),
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateRoutingWithUnknownPartReturns404(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);

        $this->client->request('POST', '/api/routings', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference'    => self::REF_PREFIX . '001',
            'label'        => 'Gamme test',
            'partId'       => 999999,
            'supervisorId' => $this->getSupervisorId(),
        ]));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testCreateRoutingWithNonManufacturedPartReturnsBadRequest(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $part = $this->createTestPart('001', PieceType::Purchased);

        $this->client->request('POST', '/api/routings', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference'    => self::REF_PREFIX . '001',
            'label'        => 'Gamme test',
            'partId'       => $part->getId(),
            'supervisorId' => $this->getSupervisorId(),
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateRoutingWithPartAlreadyHavingRoutingReturnsConflict(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $routing = $this->createTestRouting('001');

        $part = $this->em->getRepository(Part::class)->findOneBy(['reference' => self::PART_PREFIX . '001']);

        $this->client->request('POST', '/api/routings', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference'    => self::REF_PREFIX . '002',
            'label'        => 'Gamme doublon',
            'partId'       => $part->getId(),
            'supervisorId' => $this->getSupervisorId(),
        ]));

        $this->assertResponseStatusCodeSame(409);
    }

    public function testCreateRoutingWithUnknownSupervisorReturns404(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $part = $this->createTestPart('001');

        $this->client->request('POST', '/api/routings', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference'    => self::REF_PREFIX . '001',
            'label'        => 'Gamme test',
            'partId'       => $part->getId(),
            'supervisorId' => 999999,
        ]));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testCreateRoutingWithNonSupervisorUserReturnsBadRequest(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $part = $this->createTestPart('001');
        $workerId = $this->em->getRepository(User::class)->findOneBy(['email' => self::WORKER_EMAIL])->getId();

        $this->client->request('POST', '/api/routings', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference'    => self::REF_PREFIX . '001',
            'label'        => 'Gamme test',
            'partId'       => $part->getId(),
            'supervisorId' => $workerId,
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    // ── PUT /api/routings/{id} ──────────────────────────────────

    public function testUpdateChangesSpecifiedFields(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $routing = $this->createTestRouting('001');

        $this->client->request('PUT', '/api/routings/' . $routing->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => 'Gamme modifiée',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Gamme modifiée', $data['label']);
        $this->assertSame(self::REF_PREFIX . '001', $data['reference']); // inchangé
    }

    public function testUpdateDoesNotChangeUnspecifiedFields(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $routing = $this->createTestRouting('001');

        $this->client->request('PUT', '/api/routings/' . $routing->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference' => self::REF_PREFIX . 'UPDATED',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(self::REF_PREFIX . 'UPDATED', $data['reference']);
        $this->assertSame('Test Routing 001', $data['label']); // inchangé
    }

    public function testUpdateSupervisorMustHaveSupervisorRole(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $routing = $this->createTestRouting('001');
        $workerId = $this->em->getRepository(User::class)->findOneBy(['email' => self::WORKER_EMAIL])->getId();

        $this->client->request('PUT', '/api/routings/' . $routing->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'supervisorId' => $workerId,
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testUpdateWithInvalidJsonReturnsBadRequest(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $routing = $this->createTestRouting('001');

        $this->client->request('PUT', '/api/routings/' . $routing->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], 'bad-json');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testUpdateReturns404ForUnknownRouting(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);

        $this->client->request('PUT', '/api/routings/999999', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => 'X',
        ]));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testUpdatePartAlreadyHavingRoutingReturnsConflict(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $this->createTestRouting('001');
        $routing2 = $this->createTestRouting('002', $this->createTestPart('002'));

        $part1 = $this->em->getRepository(Part::class)->findOneBy(['reference' => self::PART_PREFIX . '001']);

        $this->client->request('PUT', '/api/routings/' . $routing2->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'partId' => $part1->getId(),
        ]));

        $this->assertResponseStatusCodeSame(409);
    }

    // ── DELETE /api/routings/{id} ───────────────────────────────

    public function testDeleteRoutingReturns204(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $routing = $this->createTestRouting('001');

        $this->client->request('DELETE', '/api/routings/' . $routing->getId());

        $this->assertResponseStatusCodeSame(204);
    }

    public function testDeletedRoutingIsNoLongerAccessible(): void
    {
        $routing = $this->createTestRouting('001');
        $id = $routing->getId();

        $this->loginAs(self::ADMIN_EMAIL);
        $this->client->request('DELETE', '/api/routings/' . $id);
        $this->assertResponseStatusCodeSame(204);

        $this->loginAs(self::WORKER_EMAIL);
        $this->client->request('GET', '/api/routings/' . $id);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteReturns404ForUnknownRouting(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);

        $this->client->request('DELETE', '/api/routings/999999');

        $this->assertResponseStatusCodeSame(404);
    }
}
