<?php

namespace App\Tests\Controller;

use App\Entity\Completion;
use App\Entity\Operation;
use App\Tests\DataFixtures\CompletionTestFixtures;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class CompletionControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private CompletionTestFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em     = static::getContainer()->get(EntityManagerInterface::class);

        $hasher         = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->fixtures = new CompletionTestFixtures($hasher);

        $executor = new ORMExecutor($this->em, new ORMPurger());
        $executor->execute([$this->fixtures]);

        $this->loginAs(CompletionTestFixtures::WORKER_EMAIL);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    private function loginAs(string $email, string $password = CompletionTestFixtures::TEST_PASSWORD): void
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
        return $this->fixtures->getReference(CompletionTestFixtures::REF_OPERATION, Operation::class)->getId();
    }

    private function op2Id(): int
    {
        return $this->fixtures->getReference(CompletionTestFixtures::REF_OPERATION2, Operation::class)->getId();
    }

    private function cmp1Id(): int
    {
        return $this->fixtures->getReference(CompletionTestFixtures::REF_COMPLETION1, Completion::class)->getId();
    }

    private function baseUrl(): string
    {
        return '/api/operations/' . $this->opId() . '/completions';
    }

    // ── Authentication & Authorisation ───────────────────────

    public function testUnauthenticatedRequestReturns403(): void
    {
        $this->client->setServerParameters([]);
        $this->client->request('GET', $this->baseUrl());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testWorkerCannotUpdateReturns403(): void
    {
        $this->client->request('PUT', $this->baseUrl() . '/' . $this->cmp1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'actualQuantity' => 99,
        ]));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testWorkerCannotDeleteReturns403(): void
    {
        $this->client->request('DELETE', $this->baseUrl() . '/' . $this->cmp1Id());

        $this->assertResponseStatusCodeSame(403);
    }

    // ── GET /api/operations/{id}/completions ─────────────────

    public function testIndexReturnsSuccessfulJsonArray(): void
    {
        $this->client->request('GET', $this->baseUrl());

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertCount(2, $data);
    }

    public function testIndexOnlyReturnsCompletionsForGivenOperation(): void
    {
        $this->client->request('GET', $this->baseUrl());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        foreach ($data as $cmp) {
            $this->assertSame($this->opId(), $cmp['operation']['id']);
        }
    }

    public function testIndexReturnsExpectedFields(): void
    {
        $this->client->request('GET', $this->baseUrl());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $data[0]);
        $this->assertArrayHasKey('date', $data[0]);
        $this->assertArrayHasKey('actualQuantity', $data[0]);
        $this->assertArrayHasKey('actualDuration', $data[0]);
        $this->assertArrayHasKey('operation', $data[0]);
    }

    public function testIndexReturnsEmptyArrayForOperationWithNoCompletions(): void
    {
        $this->client->request('GET', '/api/operations/' . $this->op2Id() . '/completions');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame([], $data);
    }

    public function testIndexReturns404ForUnknownOperation(): void
    {
        $this->client->request('GET', '/api/operations/999999/completions');

        $this->assertResponseStatusCodeSame(404);
    }

    // ── POST /api/operations/{id}/completions ────────────────

    public function testWorkerCanCreateCompletionReturns201(): void
    {
        $this->client->request('POST', $this->baseUrl(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'date'           => '2026-06-20',
            'actualQuantity' => 60,
        ]));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $data);
        $this->assertSame('2026-06-20', $data['date']);
        $this->assertSame(60, $data['actualQuantity']);
        $this->assertNull($data['actualDuration']);
        $this->assertSame($this->opId(), $data['operation']['id']);
    }

    public function testCreateWithActualDurationReturns201(): void
    {
        $this->client->request('POST', $this->baseUrl(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'date'           => '2026-06-20',
            'actualQuantity' => 60,
            'actualDuration' => 3.5,
        ]));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(3.5, $data['actualDuration']);
    }

    public function testCreateWithNullActualDurationReturns201(): void
    {
        $this->client->request('POST', $this->baseUrl(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'date'           => '2026-06-20',
            'actualQuantity' => 60,
            'actualDuration' => null,
        ]));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertNull($data['actualDuration']);
    }

    public function testCreateWithMissingDateReturnsBadRequest(): void
    {
        $this->client->request('POST', $this->baseUrl(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'actualQuantity' => 60,
        ]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateWithInvalidDateFormatReturnsBadRequest(): void
    {
        $this->client->request('POST', $this->baseUrl(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'date'           => '20/06/2026',
            'actualQuantity' => 60,
        ]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateWithMissingActualQuantityReturnsBadRequest(): void
    {
        $this->client->request('POST', $this->baseUrl(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'date' => '2026-06-20',
        ]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateWithInvalidJsonReturnsBadRequest(): void
    {
        $this->client->request('POST', $this->baseUrl(), [], [], ['CONTENT_TYPE' => 'application/json'], 'not-valid-json');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateReturns404ForUnknownOperation(): void
    {
        $this->client->request('POST', '/api/operations/999999/completions', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'date'           => '2026-06-20',
            'actualQuantity' => 60,
        ]));

        $this->assertResponseStatusCodeSame(404);
    }

    // ── PUT /api/operations/{id}/completions/{cId} ───────────

    public function testUpdateChangesDate(): void
    {
        $this->loginAs(CompletionTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('PUT', $this->baseUrl() . '/' . $this->cmp1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'date' => '2026-07-01',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('2026-07-01', $data['date']);
        $this->assertSame(80, $data['actualQuantity']); // unchanged
    }

    public function testUpdateChangesActualQuantity(): void
    {
        $this->loginAs(CompletionTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('PUT', $this->baseUrl() . '/' . $this->cmp1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'actualQuantity' => 200,
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(200, $data['actualQuantity']);
    }

    public function testUpdateChangesActualDuration(): void
    {
        $this->loginAs(CompletionTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('PUT', $this->baseUrl() . '/' . $this->cmp1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'actualDuration' => 7.5,
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(7.5, $data['actualDuration']);
    }

    public function testUpdateClearsActualDurationWhenNull(): void
    {
        $this->loginAs(CompletionTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('PUT', $this->baseUrl() . '/' . $this->cmp1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'actualDuration' => null,
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertNull($data['actualDuration']);
    }

    public function testUpdateWithInvalidJsonReturnsBadRequest(): void
    {
        $this->loginAs(CompletionTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('PUT', $this->baseUrl() . '/' . $this->cmp1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], 'bad-json');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testUpdateReturns404ForUnknownCompletion(): void
    {
        $this->loginAs(CompletionTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('PUT', $this->baseUrl() . '/999999', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'actualQuantity' => 10,
        ]));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testUpdateReturns404WhenCompletionBelongsToAnotherOperation(): void
    {
        $this->loginAs(CompletionTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('PUT', '/api/operations/' . $this->op2Id() . '/completions/' . $this->cmp1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'actualQuantity' => 10,
        ]));

        $this->assertResponseStatusCodeSame(404);
    }

    // ── DELETE /api/operations/{id}/completions/{cId} ────────

    public function testDeleteCompletionReturns204(): void
    {
        $this->loginAs(CompletionTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('DELETE', $this->baseUrl() . '/' . $this->cmp1Id());

        $this->assertResponseStatusCodeSame(204);
    }

    public function testDeletedCompletionDisappearsFromList(): void
    {
        $this->loginAs(CompletionTestFixtures::SUPERVISOR_EMAIL);

        $id = $this->cmp1Id();
        $this->client->request('DELETE', $this->baseUrl() . '/' . $id);
        $this->assertResponseStatusCodeSame(204);

        $this->loginAs(CompletionTestFixtures::WORKER_EMAIL);
        $this->client->request('GET', $this->baseUrl());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertNotContains($id, array_column($data, 'id'));
    }

    public function testDeleteReturns404ForUnknownCompletion(): void
    {
        $this->loginAs(CompletionTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('DELETE', $this->baseUrl() . '/999999');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteReturns404WhenCompletionBelongsToAnotherOperation(): void
    {
        $this->loginAs(CompletionTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('DELETE', '/api/operations/' . $this->op2Id() . '/completions/' . $this->cmp1Id());

        $this->assertResponseStatusCodeSame(404);
    }
}
