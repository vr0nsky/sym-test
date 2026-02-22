<?php

// Override sleep() nel namespace del handler per evitare i 10s di attesa
namespace App\MessageHandler {
    function sleep(int $seconds): int
    {
        return 0;
    }
}

namespace App\Tests\MessageHandler {

    use App\Entity\Job;
    use App\Message\ProcessJobMessage;
    use App\MessageHandler\ProcessJobMessageHandler;
    use Doctrine\ORM\EntityManagerInterface;
    use Doctrine\ORM\EntityRepository;
    use PHPUnit\Framework\TestCase;

    class ProcessJobMessageHandlerTest extends TestCase
    {
        private string $outputDir;
        private array $createdFiles = [];

        protected function setUp(): void
        {
            $this->outputDir = sys_get_temp_dir();
        }

        protected function tearDown(): void
        {
            foreach ($this->createdFiles as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }

        private function makeJob(string $inputFilename, string $outputFormat): Job
        {
            $job = new Job();
            $job->setInputFormat('csv');
            $job->setOutputFormat($outputFormat);
            $job->setInputFilePath('/tmp/' . $inputFilename . '.csv');

            return $job;
        }

        private function makeHandler(EntityManagerInterface $em): ProcessJobMessageHandler
        {
            return new ProcessJobMessageHandler($em, $this->outputDir);
        }

        public function testJobNotFoundDoesNothing(): void
        {
            $repo = $this->createMock(EntityRepository::class);
            $repo->method('find')->willReturn(null);

            $em = $this->createMock(EntityManagerInterface::class);
            $em->method('getRepository')->willReturn($repo);
            $em->expects($this->never())->method('flush');

            $this->makeHandler($em)(new ProcessJobMessage(999));
        }

        public function testStatusIsSetToProcessingThenCompleted(): void
        {
            $job = $this->makeJob('test_status', 'json');
            $statusOnFirstFlush = null;

            $repo = $this->createMock(EntityRepository::class);
            $repo->method('find')->willReturn($job);

            $em = $this->createMock(EntityManagerInterface::class);
            $em->method('getRepository')->willReturn($repo);
            $em->method('flush')->willReturnCallback(function () use ($job, &$statusOnFirstFlush): void {
                if ($statusOnFirstFlush === null) {
                    $statusOnFirstFlush = $job->getStatus();
                }
            });

            $this->makeHandler($em)(new ProcessJobMessage(1));

            $this->createdFiles[] = $this->outputDir . '/test_status.json';

            $this->assertEquals('processing', $statusOnFirstFlush);
            $this->assertEquals('completed', $job->getStatus());
        }

        public function testJsonOutputIsWritten(): void
        {
            $job = $this->makeJob('test_json', 'json');

            $repo = $this->createMock(EntityRepository::class);
            $repo->method('find')->willReturn($job);

            $em = $this->createMock(EntityManagerInterface::class);
            $em->method('getRepository')->willReturn($repo);
            $em->expects($this->exactly(2))->method('flush');

            $this->makeHandler($em)(new ProcessJobMessage(1));

            $outputPath = $this->outputDir . '/test_json.json';
            $this->createdFiles[] = $outputPath;

            $this->assertFileExists($outputPath);
            $this->assertJson(file_get_contents($outputPath));
            $this->assertEquals($outputPath, $job->getOutputFilePath());
        }

        public function testXmlOutputIsWritten(): void
        {
            $job = $this->makeJob('test_xml', 'xml');

            $repo = $this->createMock(EntityRepository::class);
            $repo->method('find')->willReturn($job);

            $em = $this->createMock(EntityManagerInterface::class);
            $em->method('getRepository')->willReturn($repo);

            $this->makeHandler($em)(new ProcessJobMessage(1));

            $outputPath = $this->outputDir . '/test_xml.xml';
            $this->createdFiles[] = $outputPath;

            $this->assertFileExists($outputPath);
            $this->assertStringContainsString('<?xml', file_get_contents($outputPath));
            $this->assertEquals($outputPath, $job->getOutputFilePath());
        }
    }
}
