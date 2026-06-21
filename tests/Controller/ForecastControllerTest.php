<?php

namespace App\Tests\Controller;

use App\Entity\Forecast;
use App\Entity\Operation;
use App\Entity\Part;
use App\Tests\DataFixtures\ForecastTestFixtures;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ForecastControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ForecastTestFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em     = static::getContainer()->get(EntityManagerInterface::class);

        $hasher         = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->fixtures = new ForecastTestFixtures($hasher);

        $executor = new ORMExecutor($this->em, new ORMPurger());
        $executor->execute([$this->fixtures]);

        $this->loginAs(ForecastTestFixtures::WORKER_EMAIL);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    private function loginAs(string $email, string $password = ForecastTestFixtures::TEST_PASSWORD): void
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
        return $this->fixtures->getReference(ForecastTestFixtures::REF_OPERATION, Operation::class)->getId();
    }

    private function op2Id(): int
    {
        return $this->fixtures->getReference(ForecastTestFixtures::REF_OPERATION2, Operation::class)->getId();
    }

    private function fc1Id(): int
    {
        return $this->fixtures->getReference(ForecastTestFixtures::REF_FORECAST1, Forecast::class)->getId();
    }

    private function baseUrl(): string
    {
        return '/api/operations/' . $this->opId() . '/forecasts';
    }

    // ── Authentication & Authorisation ───────────────────────

    public function testUnauthenticatedRequestReturns403(): void
    {
        $this->client->setServerParameters([]);
        $this->client->request('GET', $this->baseUrl());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testWorkerCannotCreateReturns403(): void
    {
        $this->client->request('POST', $this->baseUrl(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'plannedDate'     => '2026-08-01',
            'plannedQuantity' => 50,
        ]));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testWorkerCannotUpdateReturns403(): void
    {
        $this->client->request('PUT', $this->baseUrl() . '/' . $this->fc1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'plannedQuantity' => 99,
        ]));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testWorkerCannotDeleteReturns403(): void
    {
        $this->client->request('DELETE', $this->baseUrl() . '/' . $this->fc1Id());

        $this->assertResponseStatusCodeSame(403);
    }

    // ── GET /api/operations/{id}/forecasts ───────────────────

    public function testIndexReturnsSuccessfulJsonArray(): void
    {
        $this->client->request('GET', $this->baseUrl());

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertCount(2, $data);
    }

    public function testIndexOnlyReturnsForecastsForGivenOperation(): void
    {
        $this->client->request('GET', $this->baseUrl());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        foreach ($data as $fc) {
            $this->assertSame($this->opId(), $fc['operation']['id']);
        }
    }

    public function testIndexReturnsExpectedFields(): void
    {
        $this->client->request('GET', $this->baseUrl());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $data[0]);
        $this->assertArrayHasKey('plannedDate', $data[0]);
        $this->assertArrayHasKey('plannedQuantity', $data[0]);
        $this->assertArrayHasKey('status', $data[0]);
        $this->assertArrayHasKey('operation', $data[0]);
    }

    public function testIndexReturnsEmptyArrayForOperationWithNoForecasts(): void
    {
        $this->client->request('GET', '/api/operations/' . $this->op2Id() . '/forecasts');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame([], $data);
    }

    public function testIndexReturns404ForUnknownOperation(): void
    {
        $this->client->request('GET', '/api/operations/999999/forecasts');

        $this->assertResponseStatusCodeSame(404);
    }

    // ── POST /api/operations/{id}/forecasts ──────────────────

    public function testCreateForecastReturns201(): void
    {
        $this->loginAs(ForecastTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('POST', $this->baseUrl(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'plannedDate'     => '2026-09-01',
            'plannedQuantity' => 75,
        ]));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $data);
        $this->assertSame('2026-09-01', $data['plannedDate']);
        $this->assertSame(75, $data['plannedQuantity']);
        $this->assertSame('in_progress', $data['status']);
        $this->assertSame($this->opId(), $data['operation']['id']);
    }

    public function testCreateWithExplicitStatusReturns201(): void
    {
        $this->loginAs(ForecastTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('POST', $this->baseUrl(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'plannedDate'     => '2026-09-10',
            'plannedQuantity' => 30,
            'status'          => 'in_progress',
        ]));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('in_progress', $data['status']);
    }

    public function testCreateWithMissingPlannedDateReturnsBadRequest(): void
    {
        $this->loginAs(ForecastTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('POST', $this->baseUrl(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'plannedQuantity' => 50,
        ]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateWithInvalidDateFormatReturnsBadRequest(): void
    {
        $this->loginAs(ForecastTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('POST', $this->baseUrl(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'plannedDate'     => '01/09/2026',
            'plannedQuantity' => 50,
        ]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateWithMissingPlannedQuantityReturnsBadRequest(): void
    {
        $this->loginAs(ForecastTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('POST', $this->baseUrl(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'plannedDate' => '2026-09-01',
        ]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateWithInvalidStatusReturnsBadRequest(): void
    {
        $this->loginAs(ForecastTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('POST', $this->baseUrl(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'plannedDate'     => '2026-09-01',
            'plannedQuantity' => 50,
            'status'          => 'unknown_status',
        ]));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateWithInvalidJsonReturnsBadRequest(): void
    {
        $this->loginAs(ForecastTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('POST', $this->baseUrl(), [], [], ['CONTENT_TYPE' => 'application/json'], 'not-valid-json');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateReturns404ForUnknownOperation(): void
    {
        $this->loginAs(ForecastTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('POST', '/api/operations/999999/forecasts', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'plannedDate'     => '2026-09-01',
            'plannedQuantity' => 50,
        ]));

        $this->assertResponseStatusCodeSame(404);
    }

    // ── PUT /api/operations/{id}/forecasts/{fId} ─────────────

    public function testUpdateChangesPlannedDate(): void
    {
        $this->loginAs(ForecastTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('PUT', $this->baseUrl() . '/' . $this->fc1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'plannedDate' => '2026-10-01',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('2026-10-01', $data['plannedDate']);
        $this->assertSame(100, $data['plannedQuantity']); // unchanged
    }

    public function testUpdateChangesPlannedQuantity(): void
    {
        $this->loginAs(ForecastTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('PUT', $this->baseUrl() . '/' . $this->fc1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'plannedQuantity' => 999,
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(999, $data['plannedQuantity']);
    }

    public function testUpdateChangesStatus(): void
    {
        $this->loginAs(ForecastTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('PUT', $this->baseUrl() . '/' . $this->fc1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'status' => 'completed',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('completed', $data['status']);
    }

    public function testUpdateWithInvalidJsonReturnsBadRequest(): void
    {
        $this->loginAs(ForecastTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('PUT', $this->baseUrl() . '/' . $this->fc1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], 'bad-json');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testUpdateReturns404ForUnknownForecast(): void
    {
        $this->loginAs(ForecastTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('PUT', $this->baseUrl() . '/999999', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'plannedQuantity' => 10,
        ]));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testUpdateReturns404WhenForecastBelongsToAnotherOperation(): void
    {
        $this->loginAs(ForecastTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('PUT', '/api/operations/' . $this->op2Id() . '/forecasts/' . $this->fc1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'plannedQuantity' => 10,
        ]));

        $this->assertResponseStatusCodeSame(404);
    }

    // ── DELETE /api/operations/{id}/forecasts/{fId} ──────────

    public function testDeleteForecastReturns204(): void
    {
        $this->loginAs(ForecastTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('DELETE', $this->baseUrl() . '/' . $this->fc1Id());

        $this->assertResponseStatusCodeSame(204);
    }

    public function testDeletedForecastDisappearsFromList(): void
    {
        $this->loginAs(ForecastTestFixtures::SUPERVISOR_EMAIL);

        $id = $this->fc1Id();
        $this->client->request('DELETE', $this->baseUrl() . '/' . $id);
        $this->assertResponseStatusCodeSame(204);

        $this->loginAs(ForecastTestFixtures::WORKER_EMAIL);
        $this->client->request('GET', $this->baseUrl());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertNotContains($id, array_column($data, 'id'));
    }

    public function testDeleteReturns404ForUnknownForecast(): void
    {
        $this->loginAs(ForecastTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('DELETE', $this->baseUrl() . '/999999');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteReturns404WhenForecastBelongsToAnotherOperation(): void
    {
        $this->loginAs(ForecastTestFixtures::SUPERVISOR_EMAIL);

        $this->client->request('DELETE', '/api/operations/' . $this->op2Id() . '/forecasts/' . $this->fc1Id());

        $this->assertResponseStatusCodeSame(404);
    }

    // ── Stock updates ────────────────────────────────────────

    private function stockQuantity(): int
    {
        $part = $this->fixtures->getReference(ForecastTestFixtures::REF_PART, Part::class);
        $this->em->refresh($part);

        return $part->getStockQuantity();
    }

    public function testCreateWithCompletedStatusIncrementsStock(): void
    {
        $this->loginAs(ForecastTestFixtures::SUPERVISOR_EMAIL);
        $before = $this->stockQuantity();

        $this->client->request('POST', $this->baseUrl(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'plannedDate'     => '2026-09-01',
            'plannedQuantity' => 40,
            'status'          => 'completed',
        ]));

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame($before + 40, $this->stockQuantity());
    }

    public function testCreateWithNonCompletedStatusDoesNotChangeStock(): void
    {
        $this->loginAs(ForecastTestFixtures::SUPERVISOR_EMAIL);
        $before = $this->stockQuantity();

        $this->client->request('POST', $this->baseUrl(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'plannedDate'     => '2026-09-01',
            'plannedQuantity' => 40,
        ]));

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame($before, $this->stockQuantity());
    }

    public function testUpdateStatusToCompletedIncrementsStock(): void
    {
        $this->loginAs(ForecastTestFixtures::SUPERVISOR_EMAIL);
        $before = $this->stockQuantity();

        // fc1 is IN_PROGRESS with plannedQuantity=100
        $this->client->request('PUT', $this->baseUrl() . '/' . $this->fc1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'status' => 'completed',
        ]));

        $this->assertResponseIsSuccessful();
        $this->assertSame($before + 100, $this->stockQuantity());
    }

    public function testUpdateStatusFromCompletedDecrementsStock(): void
    {
        $this->loginAs(ForecastTestFixtures::SUPERVISOR_EMAIL);

        // First complete fc1
        $this->client->request('PUT', $this->baseUrl() . '/' . $this->fc1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'status' => 'completed',
        ]));
        $after_complete = $this->stockQuantity();

        // Then revert to in_progress
        $this->client->request('PUT', $this->baseUrl() . '/' . $this->fc1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'status' => 'in_progress',
        ]));

        $this->assertResponseIsSuccessful();
        $this->assertSame($after_complete - 100, $this->stockQuantity());
    }

    public function testUpdateQuantityWhenCompletedAdjustsDelta(): void
    {
        $this->loginAs(ForecastTestFixtures::SUPERVISOR_EMAIL);

        // Complete fc1 (plannedQuantity=100)
        $this->client->request('PUT', $this->baseUrl() . '/' . $this->fc1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'status' => 'completed',
        ]));
        $after_complete = $this->stockQuantity();

        // Increase quantity from 100 to 150
        $this->client->request('PUT', $this->baseUrl() . '/' . $this->fc1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'plannedQuantity' => 150,
        ]));

        $this->assertResponseIsSuccessful();
        $this->assertSame($after_complete + 50, $this->stockQuantity());
    }

    public function testDeleteCompletedForecastDecrementsStock(): void
    {
        $this->loginAs(ForecastTestFixtures::SUPERVISOR_EMAIL);

        // Complete fc1 first
        $this->client->request('PUT', $this->baseUrl() . '/' . $this->fc1Id(), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'status' => 'completed',
        ]));
        $after_complete = $this->stockQuantity();

        // Delete it
        $this->client->request('DELETE', $this->baseUrl() . '/' . $this->fc1Id());

        $this->assertResponseStatusCodeSame(204);
        $this->assertSame($after_complete - 100, $this->stockQuantity());
    }

    public function testDeleteNonCompletedForecastDoesNotChangeStock(): void
    {
        $this->loginAs(ForecastTestFixtures::SUPERVISOR_EMAIL);
        $before = $this->stockQuantity();

        // fc1 is IN_PROGRESS, delete it without completing
        $this->client->request('DELETE', $this->baseUrl() . '/' . $this->fc1Id());

        $this->assertResponseStatusCodeSame(204);
        $this->assertSame($before, $this->stockQuantity());
    }
}
