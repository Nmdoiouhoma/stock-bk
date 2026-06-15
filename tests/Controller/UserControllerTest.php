<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Enum\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    private const ADMIN_EMAIL    = 'admin@utest.ptest.com';
    private const WORKER_EMAIL   = 'worker@utest.ptest.com';
    private const TEST_PASSWORD  = 'test_password';
    private const CREATED_DOMAIN = '@utest.ptest.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanTestUsers();
        $this->createTestUser(Role::Admin, self::ADMIN_EMAIL);
        $this->createTestUser(Role::Worker, self::WORKER_EMAIL);
        $this->loginAs(self::ADMIN_EMAIL);
    }

    protected function tearDown(): void
    {
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

    private function cleanTestUsers(): void
    {
        $this->em->createQuery(
            'DELETE FROM App\Entity\User u WHERE u.email LIKE :domain'
        )->setParameter('domain', '%' . self::CREATED_DOMAIN)->execute();
        $this->em->clear();
    }

    private function makeEmail(string $suffix): string
    {
        return 'user-' . $suffix . self::CREATED_DOMAIN;
    }

    // ── Authentication ───────────────────────────────────────

    public function testUnauthenticatedRequestReturns401(): void
    {
        $this->client->setServerParameters([]);
        $this->client->request('GET', '/api/users');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testWorkerCannotAccessUsersReturns403(): void
    {
        $this->loginAs(self::WORKER_EMAIL);
        $this->client->request('GET', '/api/users');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testWorkerCannotCreateUserReturns403(): void
    {
        $this->loginAs(self::WORKER_EMAIL);
        $this->client->request('POST', '/api/users', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'firstname' => 'Jean',
            'lastname'  => 'Test',
            'email'     => $this->makeEmail('forbidden'),
            'password'  => 'pass',
            'role'      => 'worker',
        ]));

        $this->assertResponseStatusCodeSame(403);
    }

    // ── GET /api/users ───────────────────────────────────────

    public function testIndexReturnsSuccessfulJsonArray(): void
    {
        $this->client->request('GET', '/api/users');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testIndexIncludesCreatedUser(): void
    {
        $email = $this->makeEmail('list');
        $this->createTestUser(Role::Worker, $email);

        $this->client->request('GET', '/api/users');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $emails = array_column($data, 'email');
        $this->assertContains($email, $emails);
    }

    public function testIndexResponseDoesNotExposePassword(): void
    {
        $this->client->request('GET', '/api/users');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        foreach ($data as $user) {
            $this->assertArrayNotHasKey('password', $user);
        }
    }

    // ── POST /api/users ──────────────────────────────────────

    public function testCreateUserReturns201WithCorrectData(): void
    {
        $this->client->request('POST', '/api/users', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'firstname' => 'Jean',
            'lastname'  => 'Dupont',
            'email'     => $this->makeEmail('create'),
            'password'  => 'motdepasse',
            'role'      => 'worker',
        ]));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $data);
        $this->assertSame('Jean', $data['firstname']);
        $this->assertSame('Dupont', $data['lastname']);
        $this->assertSame($this->makeEmail('create'), $data['email']);
        $this->assertSame('worker', $data['role']);
        $this->assertArrayNotHasKey('password', $data);
    }

    public function testCreateUserAcceptsAllRoles(): void
    {
        foreach (Role::cases() as $role) {
            $this->client->request('POST', '/api/users', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'firstname' => 'Test',
                'lastname'  => 'Role',
                'email'     => $this->makeEmail('role-' . $role->value),
                'password'  => 'pass',
                'role'      => $role->value,
            ]));
            $this->assertResponseStatusCodeSame(201, "Failed for role: {$role->value}");
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertSame($role->value, $data['role']);
        }
    }

    public function testCreatedUserCanLogin(): void
    {
        $email = $this->makeEmail('canlogin');
        $password = 'secret123';

        $this->client->request('POST', '/api/users', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'firstname' => 'Login',
            'lastname'  => 'Test',
            'email'     => $email,
            'password'  => $password,
            'role'      => 'worker',
        ]));
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email'    => $email,
            'password' => $password,
        ]));
        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('token', $data);
    }

    public function testCreateUserWithMissingFieldsReturnsBadRequest(): void
    {
        $this->client->request('POST', '/api/users', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'firstname' => 'Jean',
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateUserWithInvalidRoleReturnsBadRequest(): void
    {
        $this->client->request('POST', '/api/users', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'firstname' => 'Jean',
            'lastname'  => 'Dupont',
            'email'     => $this->makeEmail('badrole'),
            'password'  => 'pass',
            'role'      => 'superuser',
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateUserWithInvalidJsonReturnsBadRequest(): void
    {
        $this->client->request('POST', '/api/users', [], [], ['CONTENT_TYPE' => 'application/json'], 'not-valid-json');

        $this->assertResponseStatusCodeSame(400);
    }

    // ── GET /api/users/{id} ──────────────────────────────────

    public function testShowReturnsCorrectUserData(): void
    {
        $email = $this->makeEmail('show');
        $user = $this->createTestUser(Role::Seller, $email);

        $this->client->request('GET', '/api/users/' . $user->getId());

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame($user->getId(), $data['id']);
        $this->assertSame('Test', $data['firstname']);
        $this->assertSame('User', $data['lastname']);
        $this->assertSame($email, $data['email']);
        $this->assertSame('seller', $data['role']);
        $this->assertArrayNotHasKey('password', $data);
    }

    public function testShowReturns404ForUnknownUser(): void
    {
        $this->client->request('GET', '/api/users/999999');

        $this->assertResponseStatusCodeSame(404);
    }

    // ── PUT /api/users/{id} ──────────────────────────────────

    public function testUpdateChangesSpecifiedFields(): void
    {
        $user = $this->createTestUser(Role::Worker, $this->makeEmail('update'));

        $this->client->request('PUT', '/api/users/' . $user->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'firstname' => 'Pierre',
            'lastname'  => 'Martin',
            'role'      => 'seller',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Pierre', $data['firstname']);
        $this->assertSame('Martin', $data['lastname']);
        $this->assertSame('seller', $data['role']);
        $this->assertSame($this->makeEmail('update'), $data['email']); // unchanged
    }

    public function testUpdateDoesNotChangeUnspecifiedFields(): void
    {
        $user = $this->createTestUser(Role::Customer, $this->makeEmail('partial'));

        $this->client->request('PUT', '/api/users/' . $user->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'firstname' => 'Changed',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Changed', $data['firstname']);
        $this->assertSame('User', $data['lastname']);     // unchanged
        $this->assertSame('customer', $data['role']);     // unchanged
    }

    public function testUpdatePasswordAllowsNewLogin(): void
    {
        $email = $this->makeEmail('pwchange');
        $user = $this->createTestUser(Role::Worker, $email);
        $newPassword = 'new_secret_456';

        $this->client->request('PUT', '/api/users/' . $user->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'password' => $newPassword,
        ]));
        $this->assertResponseIsSuccessful();

        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email'    => $email,
            'password' => $newPassword,
        ]));
        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('token', $data);
    }

    public function testUpdateWithEmptyPasswordDoesNotChangeIt(): void
    {
        $email = $this->makeEmail('emptypass');
        $user = $this->createTestUser(Role::Worker, $email);

        $this->client->request('PUT', '/api/users/' . $user->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'password' => '',
        ]));
        $this->assertResponseIsSuccessful();

        // original password still works
        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email'    => $email,
            'password' => self::TEST_PASSWORD,
        ]));
        $this->assertResponseIsSuccessful();
    }

    public function testUpdateWithInvalidRoleReturnsBadRequest(): void
    {
        $user = $this->createTestUser(Role::Worker, $this->makeEmail('badrole'));

        $this->client->request('PUT', '/api/users/' . $user->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'role' => 'superadmin',
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testUpdateWithInvalidJsonReturnsBadRequest(): void
    {
        $user = $this->createTestUser(Role::Worker, $this->makeEmail('badjson'));

        $this->client->request('PUT', '/api/users/' . $user->getId(), [], [], ['CONTENT_TYPE' => 'application/json'], 'bad-json');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testUpdateReturns404ForUnknownUser(): void
    {
        $this->client->request('PUT', '/api/users/999999', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'firstname' => 'Nobody',
        ]));

        $this->assertResponseStatusCodeSame(404);
    }

    // ── DELETE /api/users/{id} ───────────────────────────────

    public function testDeleteUserReturns204(): void
    {
        $user = $this->createTestUser(Role::Worker, $this->makeEmail('delete'));

        $this->client->request('DELETE', '/api/users/' . $user->getId());

        $this->assertResponseStatusCodeSame(204);
    }

    public function testDeletedUserIsNoLongerAccessible(): void
    {
        $user = $this->createTestUser(Role::Worker, $this->makeEmail('deleted'));
        $id = $user->getId();

        $this->client->request('DELETE', '/api/users/' . $id);
        $this->assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/users/' . $id);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteReturns404ForUnknownUser(): void
    {
        $this->client->request('DELETE', '/api/users/999999');

        $this->assertResponseStatusCodeSame(404);
    }
}
