<?php

declare(strict_types=1);

namespace BvlionBatch5\Tests;

use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

final class OvertimeNotificationRouteTest extends TestCase
{
    public function testOvertimeNotificationRouteRequiresAuthentication(): void
    {
        /** @var App<null> $app */
        $app = require __DIR__ . '/../bootstrap/app.php';
        $request = (new ServerRequestFactory())->createServerRequest(
            'POST',
            '/api/overtime/notify',
        );

        $response = $app->handle($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(
            'Bearer',
            $response->getHeaderLine('WWW-Authenticate'),
        );
    }
}
