<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Enum\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class LoginControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    private const TEST_EMAIL    = 'login-test@ptest.com';
    private const TEST_PASSWORD = 'test_password';

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanTestUser();
        $this->createTestUser();
    }

    protected function tearDown(): void
    {
        $this->cleanTestUser();
        parent::tearDown();
    }

    private function createTestUser(): void
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())
            ->setFirstname('Login')
            ->setLastname('Test')
            ->setEmail(self::TEST_EMAIL)
            ->setRole(Role::Worker);
        $user->setPassword($hasher->hashPassword($user, self::TEST_PASSWORD));
        $this->em->persist($user);
        $this->em->flush();
    }

    private function cleanTestUser(): void
    {
        $this->em->createQuery(
            'DELETE FROM App\Entity\User u WHERE u.email = :email'
        )->setParameter('email', self::TEST_EMAIL)->execute();
        $this->em->clear();
    }

    public function testLoginReturnsJwtToken(): void
    {
        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email'    => self::TEST_EMAIL,
            'password' => self::TEST_PASSWORD,
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('token', $data);
        $this->assertNotEmpty($data['token']);
    }

    public function testLoginWithWrongPasswordReturns401(): void
    {
        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email'    => self::TEST_EMAIL,
            'password' => 'wrong_password',
        ]));

        $this->assertResponseStatusCodeSame(401);
    }

    public function testLoginWithUnknownEmailReturns401(): void
    {
        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email'    => 'nobody@ptest.com',
            'password' => self::TEST_PASSWORD,
        ]));

        $this->assertResponseStatusCodeSame(401);
    }

    public function testLoginWithInvalidJsonReturns400(): void
    {
        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], 'not-json');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testTokenAllowsAccessToProtectedRoute(): void
    {
        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email'    => self::TEST_EMAIL,
            'password' => self::TEST_PASSWORD,
        ]));

        $token = json_decode($this->client->getResponse()->getContent(), true)['token'];

        $this->client->request('GET', '/api/parts', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testExpiredOrInvalidTokenReturns401(): void
    {
        $this->client->request('GET', '/api/parts', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer invalid.token.here',
        ]);

        $this->assertResponseStatusCodeSame(401);
    }
}
