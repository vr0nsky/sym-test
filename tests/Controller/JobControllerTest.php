<?php

namespace App\Tests\Controller;

use App\Entity\Job;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class JobControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private array $createdJobIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    protected function tearDown(): void
    {
        if ($this->createdJobIds) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            foreach ($this->createdJobIds as $id) {
                $job = $em->find(Job::class, $id);
                if ($job) {
                    $em->remove($job);
                }
            }
            $em->flush();
        }

        parent::tearDown();
    }

    private function createJob(string $status = 'pending', ?string $outputFilePath = null): Job
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $job = new Job();
        $job->setInputFormat('csv');
        $job->setOutputFormat('json');
        $job->setInputFilePath('/tmp/test.csv');
        $job->setStatus($status);
        if ($outputFilePath !== null) {
            $job->setOutputFilePath($outputFilePath);
        }

        $em->persist($job);
        $em->flush();

        $this->createdJobIds[] = $job->getId();

        return $job;
    }

    // POST /api/jobs

    public function testCreateJobInvalidOutputFormat(): void
    {
        $this->client->request('POST', '/api/jobs', ['output_format' => 'pdf']);

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('INVALID_OUTPUT_FORMAT', $data['error']['code']);
    }

    public function testCreateJobSuccess(): void
    {
        $this->client->request('POST', '/api/jobs', ['output_format' => 'json']);

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('job_id', $data['data']);
        $this->assertEquals('pending', $data['data']['status']);
        $this->assertEquals('json', $data['data']['output_format']);

        $this->createdJobIds[] = $data['data']['job_id'];
    }

    // GET /api/jobs/{id}

    public function testStatusJobNotFound(): void
    {
        $this->client->request('GET', '/api/jobs/99999');

        $this->assertResponseStatusCodeSame(404);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('JOB_NOT_FOUND', $data['error']['code']);
    }

    public function testStatusJobFound(): void
    {
        $job = $this->createJob('processing');

        $this->client->request('GET', '/api/jobs/' . $job->getId());

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertEquals($job->getId(), $data['data']['job_id']);
        $this->assertEquals('processing', $data['data']['status']);
    }

    // GET /api/jobs/{id}/download

    public function testDownloadJobNotFound(): void
    {
        $this->client->request('GET', '/api/jobs/99999/download');

        $this->assertResponseStatusCodeSame(404);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('JOB_NOT_FOUND', $data['error']['code']);
    }

    public function testDownloadJobNotCompleted(): void
    {
        $job = $this->createJob('processing');

        $this->client->request('GET', '/api/jobs/' . $job->getId() . '/download');

        $this->assertResponseStatusCodeSame(409);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('JOB_NOT_COMPLETED', $data['error']['code']);
    }

    public function testDownloadOutputFileNotFound(): void
    {
        $job = $this->createJob('completed', '/tmp/nonexistent_orango_output.json');

        $this->client->request('GET', '/api/jobs/' . $job->getId() . '/download');

        $this->assertResponseStatusCodeSame(404);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('OUTPUT_FILE_NOT_FOUND', $data['error']['code']);
    }

    public function testDownloadSuccess(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'orango_test_');
        file_put_contents($tmpFile, json_encode(['converted' => true]));

        $job = $this->createJob('completed', $tmpFile);

        $this->client->request('GET', '/api/jobs/' . $job->getId() . '/download');

        $this->assertResponseStatusCodeSame(200);
        $this->assertResponseHeaderSame('content-type', 'application/json');

        unlink($tmpFile);
    }
}
