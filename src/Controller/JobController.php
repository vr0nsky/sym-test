<?php

namespace App\Controller;

use App\Entity\Job;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Messenger\MessageBusInterface;
use App\Message\ProcessJobMessage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class JobController extends AbstractController
{
    private const ALLOWED_INPUT_FORMATS = ['csv', 'json', 'xlsx', 'ods'];
    private const ALLOWED_OUTPUT_FORMATS = ['json', 'xml'];
    private const ALLOWED_MIME_TYPES = [
	    'csv'  => ['text/csv', 'text/plain', 'application/csv'],
	    'json' => ['application/json', 'text/plain'],
	    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
	    'ods'  => ['application/vnd.oasis.opendocument.spreadsheet'],
	];

    public function __construct(
        private EntityManagerInterface $em,
        private string $uploadDir,
        private MessageBusInterface $bus,
    ) {}

    #[Route('/api/jobs', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $file = $request->files->get('file');
        $outputFormat = strtolower($request->request->get('output_format', ''));

        if (!in_array($outputFormat, self::ALLOWED_OUTPUT_FORMATS)) {
            return new JsonResponse(['error' => 'Invalid output format. Allowed: json, xml'], 400);
        }

        /* In un mondo normale:

        if (!$file) {
            return new JsonResponse(['error' => 'No file uploaded'], 400);
        }

        $inputFormat = strtolower($file->getClientOriginalExtension());

        if (!in_array($inputFormat, self::ALLOWED_INPUT_FORMATS)) {
            return new JsonResponse(['error' => 'Invalid input format. Allowed: csv, json, xlsx, ods'], 400);
        }

        $realMime = $file->getMimeType(); // legge i magic bytes, non l'estensione
	    $allowedMimes = self::ALLOWED_MIME_TYPES[$inputFormat] ?? [];
	    if (!in_array($realMime, $allowedMimes)) {
	        return new JsonResponse(['error' => 'File content does not match its extension'], 400);
	    }

	    $filename = uniqid('job_') . '.' . $inputFormat;
        $file->move($this->uploadDir, $filename);

	    Fine del mondo normale */

	    $inputFormat = "csv";
	    $filename = uniqid('job_') . '.' . $inputFormat;
        

        

        $job = new Job();
        $job->setInputFormat($inputFormat);
        $job->setOutputFormat($outputFormat);
        $job->setInputFilePath($this->uploadDir . '/' . $filename);

        $this->em->persist($job);
        $this->em->flush();
        $this->bus->dispatch(new ProcessJobMessage($job->getId()));

        return new JsonResponse([
            'job_id'        => $job->getId(),
            'status'        => $job->getStatus(),
            'input_format'  => $job->getInputFormat(),
            'output_format' => $job->getOutputFormat(),
            'created_at'    => $job->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], 201);
    }

    #[Route('/api/jobs/{id}', methods: ['GET'])]
    public function status(int $id): JsonResponse
    {
        $job = $this->em->getRepository(Job::class)->find($id);

        if (!$job) {
            return new JsonResponse(['error' => 'Job not found'], 404);
        }

        return new JsonResponse([
            'job_id'            => $job->getId(),
            'status'            => $job->getStatus(),
            'input_format'      => $job->getInputFormat(),
            'output_format'     => $job->getOutputFormat(),
            'output_file_path'  => $job->getOutputFilePath(),
            'created_at'        => $job->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/api/jobs/{id}/download', methods: ['GET'])]
	public function download(int $id): Response
	{
	    $job = $this->em->getRepository(Job::class)->find($id);

	    if (!$job) {
	        return new JsonResponse(['error' => 'Job not found'], 404);
	    }

	    if ($job->getStatus() !== 'completed') {
	        return new JsonResponse(['error' => 'Job not completed yet'], 409);
	    }

	    $path = $job->getOutputFilePath();

	    if (!file_exists($path)) {
	        return new JsonResponse(['error' => 'Output file not found'], 404);
	    }

	    return new BinaryFileResponse($path, 200, [
	        'Content-Type' => $job->getOutputFormat() === 'json' ? 'application/json' : 'application/xml',
	        'Content-Disposition' => 'attachment; filename="job_' . $id . '.' . $job->getOutputFormat() . '"',
	    ]);
	}
}