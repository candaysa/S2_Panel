<?php

namespace Tests\Unit;

use App\Support\Api;
use Tests\TestCase;

class ApiTest extends TestCase
{

    public function test_success_envelope(): void
    {
        $response = Api::success(['a' => 1], ['page' => 2]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['a' => 1], $response->getData(true)['data']);
        $this->assertSame(['page' => 2], $response->getData(true)['meta']);
    }

    public function test_success_with_null_data(): void
    {
        $this->assertArrayHasKey('data', Api::success()->getData(true));
        $this->assertNull(Api::success()->getData(true)['data']);
    }

    public function test_error_envelope(): void
    {
        $response = Api::error(Api::MSG_NOT_FOUND, ['id' => 'missing'], 404);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(Api::MSG_NOT_FOUND, $response->getData(true)['message']);
        $this->assertSame(['id' => 'missing'], $response->getData(true)['errors']);
    }

    public function test_shortcut_status_codes(): void
    {
        $this->assertSame(404, Api::notFound()->getStatusCode());
        $this->assertSame(403, Api::forbidden()->getStatusCode());
        $this->assertSame(401, Api::unauthenticated()->getStatusCode());
    }

    public function test_messages_are_stable_identifiers(): void
    {
        $this->assertSame('not_found', Api::MSG_NOT_FOUND);
        $this->assertSame('validation_failed', Api::MSG_VALIDATION_FAILED);
    }
}
