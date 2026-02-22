<?php

namespace App\Tests\Stub;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;

abstract class ResettableHttpClientStub implements HttpClientInterface, ResetInterface {}
