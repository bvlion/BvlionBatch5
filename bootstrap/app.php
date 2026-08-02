<?php

declare(strict_types=1);

use BvlionBatch5\Database\ConnectionFactory;
use BvlionBatch5\Dating\DatingNotificationService;
use BvlionBatch5\Dating\DatingRepository;
use BvlionBatch5\Middleware\BearerTokenMiddleware;
use BvlionBatch5\Overtime\OvertimeNotificationRepository;
use BvlionBatch5\Overtime\OvertimeNotificationService;
use BvlionBatch5\Slack\SlackClient;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Factory\AppFactory;

require_once __DIR__ . '/../vendor/autoload.php';
$configuration = require __DIR__ . '/config.php';

$app = AppFactory::create();
$app->addRoutingMiddleware();

$databaseConfiguration = $configuration['database'];
$datingNotificationService = new DatingNotificationService(
    new DatingRepository(
        new ConnectionFactory(
            $databaseConfiguration['host'],
            $databaseConfiguration['port'],
            $databaseConfiguration['name'],
            $databaseConfiguration['user'],
            $databaseConfiguration['password'],
        ),
    ),
    new SlackClient(
        new Client(),
        $configuration['slack']['bot_token'],
    ),
    new DateTimeZone($configuration['app']['timezone']),
);
$overtimeNotificationService = new OvertimeNotificationService(
    new OvertimeNotificationRepository(
        new ConnectionFactory(
            $databaseConfiguration['host'],
            $databaseConfiguration['port'],
            $databaseConfiguration['name'],
            $databaseConfiguration['user'],
            $databaseConfiguration['password'],
        ),
    ),
    new SlackClient(
        new Client(),
        $configuration['slack']['bot_token'],
    ),
);

$app
    ->post(
        '/api/dating/notify',
        function (
            ServerRequestInterface $request,
            ResponseInterface $response,
        ) use ($datingNotificationService): ResponseInterface {
            $datingNotificationService->notify();

            return $response->withStatus(204);
        },
    )
    ->add(
        new BearerTokenMiddleware(
            $configuration['bearer_token']['scheduler'],
            $app->getResponseFactory(),
        ),
    );

$app
    ->post(
        '/api/overtime/notify',
        function (
            ServerRequestInterface $request,
            ResponseInterface $response,
        ) use ($overtimeNotificationService): ResponseInterface {
            $result = $overtimeNotificationService->notify();
            $response->getBody()->write(
                json_encode($result['body'], JSON_THROW_ON_ERROR),
            );

            return $response->withStatus($result['status']);
        },
    )
    ->add(
        new BearerTokenMiddleware(
            $configuration['bearer_token']['overtime'],
            $app->getResponseFactory(),
        ),
    );

$app->add(
    function (
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        return $handler
            ->handle($request)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    },
);

$errorMiddleware = $app->addErrorMiddleware(false, false, false);
$errorHandler = $errorMiddleware->getDefaultErrorHandler();
$errorHandler->forceContentType('application/json');

return $app;
