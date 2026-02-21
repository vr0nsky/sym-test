<?php

namespace App\Message;

class ProcessJobMessage
{
    public function __construct(
        public readonly int $jobId,
    ) {}
}