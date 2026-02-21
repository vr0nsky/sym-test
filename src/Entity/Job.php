<?php

namespace App\Entity;

use App\Repository\JobRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: JobRepository::class)]
class Job
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $status = 'pending';

    #[ORM\Column(length: 10)]
    private string $inputFormat;

    #[ORM\Column(length: 10)]
    private string $outputFormat;

    #[ORM\Column(length: 255)]
    private string $inputFilePath;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $outputFilePath = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function getInputFormat(): string { return $this->inputFormat; }
    public function setInputFormat(string $inputFormat): static { $this->inputFormat = $inputFormat; return $this; }
    public function getOutputFormat(): string { return $this->outputFormat; }
    public function setOutputFormat(string $outputFormat): static { $this->outputFormat = $outputFormat; return $this; }
    public function getInputFilePath(): string { return $this->inputFilePath; }
    public function setInputFilePath(string $inputFilePath): static { $this->inputFilePath = $inputFilePath; return $this; }
    public function getOutputFilePath(): ?string { return $this->outputFilePath; }
    public function setOutputFilePath(?string $outputFilePath): static { $this->outputFilePath = $outputFilePath; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}