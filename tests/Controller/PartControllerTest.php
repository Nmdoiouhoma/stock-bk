<?php

namespace App\Tests\Controller;

use App\Entity\Part;
use App\Entity\Supplier;
use App\Entity\User;
use App\Enum\PieceType;
use App\Enum\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class PartControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    private const REF_PREFIX    = 'PTEST-';
    private const WORKER_EMAIL  = 'worker@ptest.com';
    private const ADMIN_EMAIL   = 'admin@ptest.com';
    private const TEST_PASSWORD = 'test_password';

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanTestUsers();
        $this->cleanTestParts();
        $this->cleanTestSuppliers();
        $this->createTestUser(Role::Worker, self::WORKER_EMAIL);
        $this->createTestUser(Role::Admin, self::ADMIN_EMAIL);
        $this->loginAs(self::WORKER_EMAIL);
    }

    protected function tearDown(): void
    {
        $this->cleanTestParts();
        $this->cleanTestSuppliers();
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

    private function createTestUser(Role $role, string $email): void
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
    }

    private function cleanTestUsers(): void
    {
        $this->em->createQuery(
            'DELETE FROM App\Entity\User u WHERE u.email IN (:emails)'
        )->setParameter('emails', [self::WORKER_EMAIL, self::ADMIN_EMAIL])->execute();
        $this->em->clear();
    }

    private function cleanTestParts(): void
    {
        $this->em->createQuery(
            'DELETE FROM App\Entity\Part p WHERE p.reference LIKE :prefix'
        )->setParameter('prefix', self::REF_PREFIX . '%')->execute();
        $this->em->clear();
    }

    private function createTestSupplier(string $name = 'Test Supplier'): Supplier
    {
        $supplier = (new Supplier())->setName($name);
        $this->em->persist($supplier);
        $this->em->flush();
        $this->em->clear();

        return $supplier;
    }

    private function cleanTestSuppliers(): void
    {
        $this->em->createQuery(
            'DELETE FROM App\Entity\Supplier s WHERE s.name LIKE :prefix'
        )->setParameter('prefix', 'Test Supplier%')->execute();
        $this->em->clear();
    }

    private function createPart(
        string $suffix = '001',
        PieceType $type = PieceType::Purchased,
        ?float $salePrice = 10.0,
        int $stockQuantity = 100,
        int $stockMin = 10,
    ): Part {
        $part = (new Part())
            ->setReference(self::REF_PREFIX . $suffix)
            ->setLabel('Test Part ' . $suffix)
            ->setType($type)
            ->setSalePrice($salePrice)
            ->setStockQuantity($stockQuantity)
            ->setStockMin($stockMin);
        $this->em->persist($part);
        $this->em->flush();
        $this->em->clear();

        return $part;
    }

    // ── Authentication ───────────────────────────────────────

    public function testUnauthenticatedRequestReturns401(): void
    {
        $this->client->setServerParameters([]);
        $this->client->request('GET', '/api/parts');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testWorkerCannotDeleteReturns403(): void
    {
        $part = $this->createPart('001');

        $this->client->request('DELETE', '/api/parts/' . $part->getId());

        $this->assertResponseStatusCodeSame(403);
    }

    // ── GET /api/parts ───────────────────────────────────────

    public function testIndexReturnsSuccessfulJsonArray(): void
    {
        $this->client->request('GET', '/api/parts');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testIndexIncludesCreatedParts(): void
    {
        $this->createPart('001');
        $this->createPart('002');

        $this->client->request('GET', '/api/parts');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $refs = array_column($data, 'reference');
        $this->assertContains('PTEST-001', $refs);
        $this->assertContains('PTEST-002', $refs);
    }

    // ── POST /api/parts ──────────────────────────────────────

    public function testCreatePartReturns201WithCorrectData(): void
    {
        $this->client->request('POST', '/api/parts', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference'     => 'PTEST-NEW',
            'label'         => 'Created Part',
            'type'          => 'finished',
            'salePrice'     => 25.5,
            'stockQuantity' => 50,
            'stockMin'      => 5,
        ]));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $data);
        $this->assertSame('PTEST-NEW', $data['reference']);
        $this->assertSame('Created Part', $data['label']);
        $this->assertSame('finished', $data['type']);
        $this->assertSame(25.5, $data['salePrice']);
        $this->assertSame(50, $data['stockQuantity']);
        $this->assertSame(5, $data['stockMin']);
        $this->assertNull($data['supplier']);
    }

    public function testCreatePartAcceptsAllPieceTypes(): void
    {
        $supplier = $this->createTestSupplier();
        $bought = [PieceType::Purchased, PieceType::RawMaterial];

        foreach (PieceType::cases() as $i => $type) {
            $extra = match(true) {
                $type === PieceType::Finished                 => ['salePrice' => 10.0],
                in_array($type, $bought, true)                => ['catalogPrice' => 10.0, 'supplierId' => $supplier->getId()],
                default                                       => [],
            };
            $this->client->request('POST', '/api/parts', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(array_merge([
                'reference' => "PTEST-TYPE-{$i}",
                'label'     => "Type test {$type->value}",
                'type'      => $type->value,
            ], $extra)));
            $this->assertResponseStatusCodeSame(201, "Failed for type: {$type->value}");
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertSame($type->value, $data['type']);
        }
    }

    public function testCreatePartWithInvalidJsonReturnsBadRequest(): void
    {
        $this->client->request('POST', '/api/parts', [], [], ['CONTENT_TYPE' => 'application/json'], 'not-valid-json');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreatePartWithInvalidTypeReturnsBadRequest(): void
    {
        $this->client->request('POST', '/api/parts', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference' => 'PTEST-BAD',
            'label'     => 'Bad type part',
            'type'      => 'invalid_type',
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreatePartWithBlankFieldsReturnsUnprocessable(): void
    {
        $this->client->request('POST', '/api/parts', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference' => '',
            'label'     => '',
            'type'      => 'purchased',
        ]));

        $this->assertResponseStatusCodeSame(422);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $fields = array_column($data['errors'], 'field');
        $this->assertContains('reference', $fields);
        $this->assertContains('label', $fields);
    }

    public function testCreatePartWithUnknownSupplierReturns404(): void
    {
        $this->client->request('POST', '/api/parts', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference'  => 'PTEST-NOSUP',
            'label'      => 'No Supplier Part',
            'type'       => 'purchased',
            'supplierId' => 999999,
        ]));

        $this->assertResponseStatusCodeSame(404);
    }

    // ── GET /api/parts/{id} ──────────────────────────────────

    public function testShowReturnsCorrectPartData(): void
    {
        $part = $this->createPart('001', PieceType::Finished, 99.9, 30, 5);

        $this->client->request('GET', '/api/parts/' . $part->getId());

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame($part->getId(), $data['id']);
        $this->assertSame('PTEST-001', $data['reference']);
        $this->assertSame('Test Part 001', $data['label']);
        $this->assertSame('finished', $data['type']);
        $this->assertSame(99.9, $data['salePrice']);
        $this->assertSame(30, $data['stockQuantity']);
        $this->assertSame(5, $data['stockMin']);
        $this->assertNull($data['supplier']);
    }

    public function testShowReturns404ForUnknownPart(): void
    {
        $this->client->request('GET', '/api/parts/999999');

        $this->assertResponseStatusCodeSame(404);
    }

    // ── PUT /api/parts/{id} ──────────────────────────────────

    public function testUpdateChangesSpecifiedFields(): void
    {
        $part = $this->createPart('001', PieceType::Finished);

        $this->client->request('PUT', '/api/parts/' . $part->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label'         => 'Updated Label',
            'stockQuantity' => 200,
            'stockMin'      => 20,
            'salePrice'     => 99.99,
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Updated Label', $data['label']);
        $this->assertSame(200, $data['stockQuantity']);
        $this->assertSame(20, $data['stockMin']);
        $this->assertSame(99.99, $data['salePrice']);
        $this->assertSame('PTEST-001', $data['reference']); // unchanged
    }

    public function testUpdateDoesNotChangeUnspecifiedFields(): void
    {
        $part = $this->createPart('001', PieceType::Finished, 50.5, 30, 5);

        $this->client->request('PUT', '/api/parts/' . $part->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => 'Changed Label Only',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Changed Label Only', $data['label']);
        $this->assertSame('finished', $data['type']);  // unchanged
        $this->assertSame(50.5, $data['salePrice']);       // unchanged
        $this->assertSame(30, $data['stockQuantity']);     // unchanged
        $this->assertSame(5, $data['stockMin']);           // unchanged
    }

    public function testUpdateWithNullStringFieldReturnsUnprocessable(): void
    {
        $part = $this->createPart('001');

        $this->client->request('PUT', '/api/parts/' . $part->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'reference' => null,
            'label'     => null,
        ]));

        $this->assertResponseStatusCodeSame(422);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $fields = array_column($data['errors'], 'field');
        $this->assertContains('reference', $fields);
        $this->assertContains('label', $fields);
    }

    public function testUpdateWithInvalidJsonReturnsBadRequest(): void
    {
        $part = $this->createPart('001');

        $this->client->request('PUT', '/api/parts/' . $part->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], 'bad-json');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testUpdateWithInvalidTypeReturnsBadRequest(): void
    {
        $part = $this->createPart('001');

        $this->client->request('PUT', '/api/parts/' . $part->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'type' => 'not_a_valid_type',
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testUpdateReturns404ForUnknownPart(): void
    {
        $this->client->request('PUT', '/api/parts/999999', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => 'Updated',
        ]));

        $this->assertResponseStatusCodeSame(404);
    }

    // ── DELETE /api/parts/{id} ───────────────────────────────

    public function testDeletePartReturns204(): void
    {
        $part = $this->createPart('001');

        $this->loginAs(self::ADMIN_EMAIL);
        $this->client->request('DELETE', '/api/parts/' . $part->getId());

        $this->assertResponseStatusCodeSame(204);
    }

    public function testDeletedPartIsNoLongerAccessible(): void
    {
        $part = $this->createPart('001');
        $id = $part->getId();

        $this->loginAs(self::ADMIN_EMAIL);
        $this->client->request('DELETE', '/api/parts/' . $id);
        $this->assertResponseStatusCodeSame(204);

        $this->loginAs(self::WORKER_EMAIL);
        $this->client->request('GET', '/api/parts/' . $id);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteReturns404ForUnknownPart(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);
        $this->client->request('DELETE', '/api/parts/999999');

        $this->assertResponseStatusCodeSame(404);
    }
}
