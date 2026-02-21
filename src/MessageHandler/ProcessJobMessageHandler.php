<?php

namespace App\MessageHandler;

use App\Message\ProcessJobMessage;
use App\Entity\Job;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ProcessJobMessageHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private string $outputDir,
    ) {}

    public function __invoke(ProcessJobMessage $message): void
    {
        $job = $this->em->getRepository(Job::class)->find($message->jobId);

        if (!$job) {
            return;
        }

        $job->setStatus('processing');
        $this->em->flush();

        // Simula lavoro lungo
        sleep(10);

        $outputPath = $this->outputDir . '/' . pathinfo($job->getInputFilePath(), PATHINFO_FILENAME) . '.' . $job->getOutputFormat();
        
        file_put_contents($outputPath, $this->convert($job));

        $job->setStatus('completed');
        $job->setOutputFilePath($outputPath);
        $this->em->flush();
    }

    private function convert(Job $job): string
    {
        // Dummy: per ora ritorna solo un placeholder
        if ($job->getOutputFormat() === 'json') {
            return json_encode(['converted' => true, 'job_id' => $job->getId()]);
        }
        return '<?xml version="1.0"?><converted><job_id>' . $job->getId() . '</job_id></converted>';
    }
}